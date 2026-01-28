<?php

/**
 * =============================================================================
 * 群聊运营数据报告脚本
 * =============================================================================
 * 
 * 功能:
 * 1. 定期报告指定群聊的当前上下文（聊天记录）数量。
 * 2. 估算使用默认模型进行一次典型AI交互可能消耗的Token数量。
 * 
 * Token估算逻辑:
 * Token数量与发送给AI模型的输入文本长度强相关。此脚本通过以下方式进行估算：
 * - 加载默认的系统提示词 (prompt.txt)。
 * - 获取目标群聊的全部历史上下文。
 * - 将上述两者组合成一个模拟的AI请求体 (messages数组)。
 * - 计算这个请求体JSON编码后的字符串长度。
 * 这个长度可以作为一个稳定、可比较的性能指标，来衡量上下文增长对API调用成本的影响。
 * 
 * 如何运行:
 * 在服务器上设置一个cron job来定期执行此文件。
 * 例如: `* * * * * /usr/bin/php /path/to/your/project/cron_report.php >> /path/to/your/project/logs/cron_report.log 2>&1`
 */

declare(strict_types=1);

// 引入项目引导文件
$services = require_once __DIR__ . '/bootstrap.php';

use ChatBot\Business\ContextManager;
use ChatBot\Database\FileStore;
use ChatBot\Core\Logger;

// 从引导文件中获取核心服务实例
$config = $services['config'];
$logger = $services['logger'];

$logger->info('Cron Report Job: 开始执行...');

// 从新的配置结构中获取启用了报告功能的群组
$reportChatIds = [];
$chatConfigs = $config['business']['chat_configs'] ?? [];
foreach ($chatConfigs as $chatId => $chatConfig) {
    if ($chatConfig['enable_cron_report'] ?? false) {
        $reportChatIds[] = $chatId;
    }
}

$estimateToken = $config['business']['cron_report_estimate_token'] ?? false;

if (empty($reportChatIds)) {
    $logger->info('Cron Report Job: 未配置任何需要报告的群聊 (business.chat_configs[].enable_cron_report)，任务结束。');
    exit;
}

// 手动实例化服务，因为没有DI容器
$db = new FileStore($config['database']['file'], $logger);
$contextManager = new ContextManager($db, $config['context'], $logger);

// 如果启用了Token估算，则预先加载提示词
$systemPrompt = '';
if ($estimateToken) {
    $defaultSystemPromptPath = $config['business']['default_persona_file'] ?? __DIR__ . '/prompt.txt';
    if (!file_exists($defaultSystemPromptPath)) {
        $logger->warning('Cron Report Job: 无法找到默认系统提示词文件，已禁用本次任务的Token估算。', ['path' => $defaultSystemPromptPath]);
        $estimateToken = false; // 找不到文件则临时禁用
    } else {
        $systemPrompt = file_get_contents($defaultSystemPromptPath);
    }
}

foreach ($reportChatIds as $chatId) {
    try {
        // 1. 获取上下文并计算数量
        $context = $contextManager->getContext($chatId);
        $contextCount = count($context);
        
        $reportData = [
            'chat_id' => $chatId,
            'context_count' => $contextCount,
        ];

        // 2. 如果启用，则估算Token消耗
        if ($estimateToken) {
            // 模拟一个AI请求的messages结构
            $messagesForEstimation = [
                ['role' => 'system', 'content' => $systemPrompt],
            ];
            // 将历史记录作为单个'user'消息，这模拟了整个上下文被发送的情况
            $messagesForEstimation[] = ['role' => 'user', 'content' => json_encode($context, JSON_UNESCAPED_UNICODE)];

            // 计算JSON编码后的字符串长度作为Token估算值
            $estimatedTokenSize = strlen(json_encode($messagesForEstimation, JSON_UNESCAPED_UNICODE));
            $reportData['estimated_token_size_chars'] = $estimatedTokenSize;
        }

        $logger->info('群聊数据报告', $reportData);

    } catch (\Exception $e) {
        $logger->error('Cron Report Job: 处理群聊时发生错误', [
            'chat_id' => $chatId,
            'error_message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}

$logger->info('Cron Report Job: 所有群聊报告完毕，任务结束。');
