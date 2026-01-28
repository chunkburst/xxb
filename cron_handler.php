<?php

// +----------------------------------------------------------------------
// | Telegram ChatBot 主动任务 Cron 触发器
// | 职责：由系统 crontab 定时调用，用于为所有群组触发预设的主动任务
// +----------------------------------------------------------------------

declare(strict_types=1);

// --- 1. 引入公共引导文件 ---
$bootstrap = require_once __DIR__ . '/bootstrap.php';
$config = $bootstrap['config'];
$logger = $bootstrap['logger'];

use ChatBot\Core\CurlHelper;

// --- 2. 初始化 ---

// 从新的 'worker' 配置块读取 URL
$workerUrl = $config['worker']['url'] ?? null;
if (empty($workerUrl)) {
    $logger->error('CronHandler: 未在 "worker.url" 中配置 worker URL，任务无法继续。');
    exit;
}

$logger->debug('CronHandler: 任务处理器已启动。');

// --- 3. 定义要执行的任务列表 ---
$tasksToDispatch = [
    'say_hello'         // 主动打招呼
];

// --- 4. 获取目标群组并派发任务 ---
$enabledGroups = $config['telegram']['enabled_groups'] ?? [];

if (empty($enabledGroups)) {
    $logger->warning('CronHandler: 未配置任何启用的群组 (enabled_groups)，任务退出。');
    exit;
}

$logger->debug('CronHandler: 开始为 ' . count($enabledGroups) . ' 个群组派发 ' . count($tasksToDispatch) . ' 种任务。');

foreach ($enabledGroups as $chatId) {
    foreach ($tasksToDispatch as $taskType) {
        $payload = [
            'type' => $taskType,
            'data' => [
                'chat_id' => $chatId,
                'triggered_at' => time()
            ]
        ];

        $logger->debug('CronHandler: 派发任务到工作进程', ['task' => $taskType, 'chat_id' => $chatId, 'url' => $workerUrl]);
        
        // 使用异步 "Fire and Forget" 模式派发任务
        $success = CurlHelper::sendAsyncPost($workerUrl, $payload, 1, $logger);

        if (!$success) {
            $logger->error('CronHandler: 派发任务失败', ['task' => $taskType, 'chat_id' => $chatId]);
        }
    }
    // 在为单个群组派发完所有任务后，稍微错开一点
    sleep(1);
}

// Trigger timer checks
$timerCronUrl = ($config['tools']['timer_api_url'] ?? '') . '/cron.php';
if (!empty($config['tools']['timer_api_url'])) {
    $ch = curl_init($timerCronUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 55); // Slightly less than 1 minute
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-Api-Key: ' . ($config['common_api_key'] ?? '')
    ]);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        $logger->error('CronHandler: Timer cron job failed', ['error' => curl_error($ch)]);
    }
    curl_close($ch);
    $logger->info('CronHandler: Timer check triggered.', ['response' => $response]);
}


$logger->info('CronHandler: 所有任务派发完毕。');