<?php
// +----------------------------------------------------------------------
// | Telegram ChatBot Webhook 主入口
// | 职责：接收Telegram更新，快速响应，并将耗时任务异步分发给 request_chat.php
// +----------------------------------------------------------------------

declare(strict_types=1);
require_once __DIR__ . '/src/Core/CurlHelper.php';


#echo 'ok';
#die();

$config = require __DIR__ . '/config.php';
$workerUrl = $config['worker']['url'];
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['ok' => false, 'description' => 'Method Not Allowed']);
    exit;
}

$updateJson = file_get_contents('php://input');
if (!$updateJson) {
    http_response_code(400); // Bad Request
    echo json_encode(['ok' => false, 'description' => 'Empty request body']);
    exit;
}

$update = json_decode($updateJson, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400); // Bad Request
    echo json_encode(['ok' => false, 'description' => 'Invalid JSON']);
    exit;
}

$message = $update['message'] ?? $update['edited_message'] ?? null;

if (
    $message
    && isset($message['chat']['type'])
    && in_array($message['chat']['type'], ['group', 'supergroup'])
) {
    //检查群组ID是否在白名单中
    $enabledGroups = $config['telegram']['enabled_groups'] ?? [];
    if (!empty($enabledGroups) && !in_array($message['chat']['id'], $enabledGroups)) {
        http_response_code(200);
        echo json_encode(['ok' => true, 'description' => 'Group not enabled']);
        exit;
    }

    $payload = [
        'type' => 'handle_message', // 定义任务类型
        'data' => $message,         // 原始消息数据
        'received_at' => time(),    // 接收时间戳
    ];

    \ChatBot\Core\CurlHelper::sendAsyncPost($workerUrl, $payload);
}

http_response_code(200);
echo json_encode(['ok' => true]);

exit;