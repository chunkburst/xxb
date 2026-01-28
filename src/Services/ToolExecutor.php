<?php

declare(strict_types=1);

namespace ChatBot\Services;

use ChatBot\Core\Logger;

/**
 * 并行工具执行器
 * 负责接收一个工具调用请求列表，并使用 curl_multi 并行执行它们。
 */
class ToolExecutor
{
    private ToolService $toolService;
    private Logger $logger;

    public function __construct(ToolService $toolService, Logger $logger)
    {
        $this->toolService = $toolService;
        $this->logger = $logger;
    }

    public function execute(array $toolCalls, int $chatId, int $userId): array
    {
        $this->logger->debug('ToolExecutor: 开始并行执行 ' . count($toolCalls) . ' 个工具调用', ['chat_id' => $chatId, 'user_id' => $userId]);

        $multiHandle = curl_multi_init();
        $handles = [];
        $callMap = [];

        // 1. 准备所有cURL句柄
        foreach ($toolCalls as $index => $call) {
            $toolName = $call['tool'] ?? 'UNKNOWN';
            $query = $call['query'] ?? '';
            $ch = null;

            switch ($toolName) {
                case 'SEARCH':
                    $ch = $this->toolService->prepareSearch($query);
                    break;
                case 'FETCH':
                    $ch = $this->toolService->prepareFetchUrl($query);
                    break;
                case 'IP_QUALITY':
                    $ch = $this->toolService->prepareQueryIpQuality($query);
                    break;
                case 'ADD_TIMER':
                    $params = json_decode($query, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $params['creator_chat_id'] = $chatId;
                        $params['creator_user_id'] = $userId;
                        $ch = $this->toolService->prepareAddTimer($params);
                    }
                    break;
                case 'LIST_TIMERS':
                    // List timers for the current chat
                    $params = ['creator_chat_id' => $chatId];
                    $ch = $this->toolService->prepareListTimers($params);
                    break;
                case 'DELETE_TIMER':
                    // The query for DELETE_TIMER is expected to be the timer_id string
                    $params = ['id' => $query];
                    $ch = $this->toolService->prepareDeleteTimer($params);
                    break;
            }

            if ($ch) {
                curl_multi_add_handle($multiHandle, $ch);
                $handles[(int)$ch] = $ch;
                $callMap[(int)$ch] = $call; // 映射句柄ID到原始调用信息
            }
        }

        // 2. 执行并行请求
        $active = null;
        do {
            $mrc = curl_multi_exec($multiHandle, $active);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);

        while ($active && $mrc == CURLM_OK) {
            if (curl_multi_select($multiHandle) != -1) {
                do {
                    $mrc = curl_multi_exec($multiHandle, $active);
                } while ($mrc == CURLM_CALL_MULTI_PERFORM);
            }
        }

        // 3. 获取并处理结果
        $results = [];
        foreach ($handles as $ch) {
            $response = curl_multi_getcontent($ch);
            $info = curl_getinfo($ch);
            $error = curl_error($ch);
            $call = $callMap[(int)$ch];
            $toolName = $call['tool'];
            $query = $call['query'];
            $httpCode = $info['http_code'] ?? 500;

            $processedResult = "未知工具或执行失败。";
            switch ($toolName) {
                case 'SEARCH':
                    $processedResult = $this->toolService->processSearchResult($response, $query, $httpCode, $error);
                    break;
                case 'FETCH':
                    $processedResult = $this->toolService->processFetchUrlResult($response, $query, $error);
                    break;
                case 'IP_QUALITY':
                    $processedResult = $this->toolService->processQueryIpQualityResult($response, $query, $httpCode, $error);
                    break;
                case 'ADD_TIMER':
                    $params = json_decode($query, true) ?? [];
                    $processedResult = $this->toolService->processAddTimerResult($response, $params, $httpCode, $error);
                    break;
                case 'LIST_TIMERS':
                    $params = ['creator_chat_id' => $chatId];
                    $processedResult = $this->toolService->processListTimersResult($response, $params, $httpCode, $error);
                    break;
                case 'DELETE_TIMER':
                    $params = ['id' => $query];
                    $processedResult = $this->toolService->processDeleteTimerResult($response, $params, $httpCode, $error);
                    break;
            }
            
            $results[] = [
                'tool_name' => $toolName,
                'query' => $query,
                'result' => $processedResult,
            ];

            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
        }

        curl_multi_close($multiHandle);

        $this->logger->debug('ToolExecutor: 所有工具调用并行执行完毕');
        return $results;
    }
}