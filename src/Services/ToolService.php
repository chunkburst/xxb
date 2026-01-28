<?php

declare(strict_types=1);

namespace ChatBot\Services;

use ChatBot\Core\Logger;

/**
 * 工具服务
 * 封装了调用外部工具（如网页抓取、搜索）的能力。
 */
class ToolService
{
    private array $config;
    private ?Logger $logger;

    public function __construct(array $config, ?Logger $logger = null)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * @return \CurlHandle|null
     */
    public function prepareFetchUrl(string $url): ?object
    {
        $gatewayUrlTemplate = $this->config['tools']['fetch_gateway_url'] ?? null;
        $targetUrl = $url;
        if ($gatewayUrlTemplate) {
            $targetUrl = str_replace('{URL}', urlencode($url), $gatewayUrlTemplate);
        }

        $workerUrl = $this->config['worker']['curl_url'] ?? null;
        if (!$workerUrl) {
            return null;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $workerUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['url' => $targetUrl]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        return $ch;
    }

    public function processFetchUrlResult(string $responseJson, string $url, string $error): string
    {
        if ($responseJson === false) {
            $this->logger?->error('ToolService: 调用 cURL worker 失败', ['url' => $url, 'error' => $error]);
            return "抓取失败: " . $error;
        }

        $response = json_decode($responseJson, true);
        return $response['content'] ?? "抓取成功，但未能提取有效内容。";
    }

    public function fetchUrl(string $url): ?string
    {
        $this->logger?->info('ToolService: 开始执行(串行)URL抓取', ['url' => $url]);
        
        $ch = $this->prepareFetchUrl($url);
        if (!$ch) {
            $this->logger?->error('ToolService: request_curl.php 的 URL 未在配置中设置');
            return null;
        }

        $responseJson = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        return $this->processFetchUrlResult($responseJson, $url, $error);
    }

    /**
     * 执行搜索查询
     *
     * @param string $query
     * @return string|null 搜索结果
     */
    /**
     * 准备一个用于SearxNG搜索的cURL句柄
     * @return \CurlHandle|null
     */
    public function prepareSearch(string $query): ?object
    {
        $apiUrl = $this->config['tools']['searxng_url'] ?? null;
        if (!$apiUrl) {
            $this->logger?->error('ToolService: SearxNG 的 URL 未在配置中设置');
            return null;
        }

        $requestUrl = rtrim($apiUrl, '/') . '/search?q=' . urlencode($query) . '&format=json';
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $requestUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
        
        $headers = [
            'Accept: application/json',
            'Accept-Language: en-US,en;q=0.9,zh-CN;q=0.8,zh;q=0.7',
            'Connection: keep-alive',
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        return $ch;
    }

    /**
     * 解析SearxNG的cURL响应
     */
    public function processSearchResult(string $responseJson, string $query, int $httpCode, string $error): string
    {
        if ($responseJson === false) {
            $this->logger?->error('ToolService: 调用 SearxNG API 失败', ['query' => $query, 'error' => $error]);
            return "搜索失败: " . $error;
        }

        if ($httpCode !== 200) {
            $this->logger?->error('ToolService: SearxNG API 返回非200状态码', ['query' => $query, 'http_code' => $httpCode, 'response' => $responseJson]);
            return "搜索失败: 搜索引擎返回状态码 " . $httpCode;
        }

        $data = json_decode($responseJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger?->error('ToolService: 解析 SearxNG 响应失败', ['query' => $query, 'error' => json_last_error_msg(), 'response' => $responseJson]);
            return "搜索服务暂时不可用，无法解析搜索引擎的响应。";
        }

        if (empty($data['results'])) {
            $this->logger?->info('ToolService: SearxNG搜索无结果', ['query' => $query]);
            return "没有找到与 '{$query}' 相关的结果。";
        }

        $formattedResult = "关于“{$query}”的搜索结果摘要：\n";
        $count = 0;
        foreach ($data['results'] as $result) {
            if ($count >= 5) break;
            $title = $result['title'] ?? '无标题';
            $content = $result['content'] ?? '无摘要';
            $url = $result['url'] ?? '#';
            $formattedResult .= "- 标题: {$title}\n  摘要: " . strip_tags($content) . "\n  链接: {$url}\n";
            $count++;
        }

        return $formattedResult;
    }

    public function search(string $query): ?string
    {
        $this->logger?->info('ToolService: 开始执行(串行)SearxNG搜索', ['query' => $query]);

        $ch = $this->prepareSearch($query);
        if (!$ch) {
            return "搜索工具未配置。";
        }

        $responseJson = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $result = $this->processSearchResult($responseJson, $query, $httpCode, $error);
        $this->logger?->info('ToolService: (串行)SearxNG搜索成功完成', ['query' => $query]);
        return $result;
    }

    /**
     * 查询IP地址的质量信息
     *
     * @param string $ip
     * @return string
     */
    /**
     * @return \CurlHandle|null
     */
    public function prepareQueryIpQuality(string $ip): ?object
    {
        $apiUrl = $this->config['tools']['ip_quality_api'] ?? null;
        if (!$apiUrl) {
            return null;
        }

        if (!filter_var($ip, FILTER_VALIDATE_IP) && !filter_var($ip, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            return null; // 返回null表示准备失败
        }

        $requestUrl = $apiUrl . '?ip=' . urlencode($ip) . '&raw_mode=true';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $requestUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        return $ch;
    }

    public function processQueryIpQualityResult(string $responseJson, string $ip, int $httpCode, string $error): string
    {
        if ($responseJson === false) {
            $this->logger?->error('ToolService: 调用 IP Quality API 失败', ['ip' => $ip, 'error' => $error]);
            return "查询IP信息失败: " . $error;
        }

        if ($httpCode !== 200) {
            $this->logger?->error('ToolService: IP Quality API 返回非200状态码', ['ip' => $ip, 'http_code' => $httpCode, 'response' => $responseJson]);
            return "查询IP信息失败: API返回状态码 " . $httpCode;
        }

        $data = json_decode($responseJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger?->error('ToolService: 解析 IP Quality API 响应失败', ['ip' => $ip, 'error' => json_last_error_msg(), 'response' => $responseJson]);
            return "IP查询服务暂时不可用，无法解析API提供商的响应。";
        }

        if (isset($data['error'])) {
            $this->logger?->error('ToolService: IP Quality API 在响应中返回错误', ['ip' => $ip, 'response' => $responseJson]);
            return "查询IP {$ip} 时出错: " . $data['error'];
        }

        return $responseJson;
    }

    public function queryIpQuality(string $ip): string
    {
        $this->logger?->info('ToolService: 开始执行(串行)IP质量查询', ['ip' => $ip]);
        
        $ch = $this->prepareQueryIpQuality($ip);
        if (!$ch) {
            if (!filter_var($ip, FILTER_VALIDATE_IP) && !filter_var($ip, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                return "无效的IP地址或域名格式: {$ip}";
            }
            $this->logger?->error('ToolService: IP Quality API 的 URL 未在配置中设置');
            return "IP查询工具未配置。";
        }

        $responseJson = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return $this->processQueryIpQualityResult($responseJson, $ip, $httpCode, $error);
    }
    
    public function prepareAddTimer(array $params): ?object
    {
        $apiUrl = $this->config['tools']['timer_api_url'] ?? null;
        if (!$apiUrl) {
            $this->logger?->error('ToolService: Timer API URL not configured');
            return null;
        }

        // 恢复：根据用户要求，从配置中明确读取回调URL
        $params['callback_url'] = $this->config['tools']['timer_callback_url'] ?? '';
        if (empty($params['callback_url'])) {
            $this->logger?->error('ToolService: Timer callback URL not configured');
            return null;
        }

        $requestUrl = rtrim($apiUrl, '/') . '?action=create';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $requestUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-Api-Key: ' . ($this->config['common_api_key'] ?? '')
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        return $ch;
    }

    public function processAddTimerResult(string $responseJson, array $query, int $httpCode, string $error): string
    {
        if ($responseJson === false) {
            $this->logger?->error('ToolService: Calling Timer API failed', ['query' => $query, 'error' => $error]);
            return "创建定时器失败: " . $error;
        }

        $data = json_decode($responseJson, true);
        if ($httpCode !== 200 || json_last_error() !== JSON_ERROR_NONE || !isset($data['id'])) {
            $this->logger?->error('ToolService: Timer API returned an error or invalid response', [
                'query' => $query,
                'http_code' => $httpCode,
                'response' => $responseJson
            ]);
            $errorMsg = $data['error'] ?? 'API返回了无效的响应';
            return "创建定时器失败: " . $errorMsg . " (状态码: {$httpCode})";
        }

        $timerName = htmlspecialchars($data['name'] ?? '无名氏');
        return "定时器 `{$timerName}` 已成功创建 (ID: {$data['id']})。";
    }

    // --- LIST TIMERS ---
    public function prepareListTimers(array $params): ?object
    {
        $apiUrl = $this->config['tools']['timer_api_url'] ?? null;
        if (!$apiUrl) {
            $this->logger?->error('ToolService: Timer API URL not configured');
            return null;
        }
        $requestUrl = rtrim($apiUrl, '/') . '?action=list';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $requestUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-Api-Key: ' . ($this->config['common_api_key'] ?? '')
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        return $ch;
    }

    public function processListTimersResult(string $responseJson, array $query, int $httpCode, string $error): string
    {
        if ($responseJson === false || $httpCode !== 200) {
            $this->logger?->error('ToolService: Calling Timer API (list) failed', ['query' => $query, 'http_code' => $httpCode, 'error' => $error]);
            return "获取定时器列表失败: " . ($error ?: "API返回状态码 {$httpCode}");
        }

        $data = json_decode($responseJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return "获取定时器列表失败: 无法解析API响应。";
        }

        if (empty($data)) {
            return "当前没有任何定时器。";
        }

        $result = "当前激活的定时器列表:\n";
        foreach ($data as $timer) {
            $name = htmlspecialchars($timer['name'] ?? '无名');
            $id = htmlspecialchars($timer['id'] ?? 'N/A');
            $cron = $timer['cron_expression'] ?? 'N/A';
            $oneTime = ($timer['one_time'] ?? false) ? '是' : '否';
            $result .= "- 名称: `{$name}`\n  ID: `{$id}`\n  Cron: `{$cron}`\n  一次性: {$oneTime}\n\n";
        }
        return $result;
    }

    // --- DELETE TIMER ---
    public function prepareDeleteTimer(array $params): ?object
    {
        $apiUrl = $this->config['tools']['timer_api_url'] ?? null;
        if (!$apiUrl) {
            $this->logger?->error('ToolService: Timer API URL not configured');
            return null;
        }
        $requestUrl = rtrim($apiUrl, '/') . '?action=delete';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $requestUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-Api-Key: ' . ($this->config['common_api_key'] ?? '')
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        return $ch;
    }

    public function processDeleteTimerResult(string $responseJson, array $query, int $httpCode, string $error): string
    {
        if ($responseJson === false || $httpCode !== 200) {
            $this->logger?->error('ToolService: Calling Timer API (delete) failed', ['query' => $query, 'http_code' => $httpCode, 'error' => $error]);
            return "删除定时器失败: " . ($error ?: "API返回状态码 {$httpCode}");
        }

        $data = json_decode($responseJson, true);
        if (isset($data['success']) && $data['success']) {
            return "定时器 `{$data['id']}` 已成功删除。";
        }
        
        $errorMsg = $data['error'] ?? '未知错误';
        $timerId = $query['id'] ?? 'N/A';
        return "删除定时器 `{$timerId}` 失败: {$errorMsg}。";
    }
}