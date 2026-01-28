<?php

declare(strict_types=1);

namespace ChatBot\Services;

use ChatBot\Core\Logger;

/**
 * Telegram Bot API 封装
 * 负责所有与 Telegram API 的通信。
 */
class TelegramAPI
{
    private string $botToken;
    private string $apiUrl;
    private ?Logger $logger;

    public function __construct(string $botToken, ?Logger $logger = null)
    {
        $this->botToken = $botToken;
        $this->apiUrl = "https://api.telegram.org/bot{$this->botToken}";
        $this->logger = $logger;
    }

    /**
     * 发送消息
     */
    public function sendMessage(int $chatId, string $text, array $options = []): ?array
    {
        return $this->request('sendMessage', array_merge(['chat_id' => $chatId, 'text' => $text], $options));
    }

    /**
     * 编辑消息文本
     */
    public function editMessageText(int $chatId, int $messageId, string $text, array $options = []): ?array
    {
        return $this->request('editMessageText', array_merge(['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text], $options));
    }

    /**
     * 删除消息
     */
    public function deleteMessage(int $chatId, int $messageId): ?array
    {
        return $this->request('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
    }

    /**
     * 获取文件的公共访问 URL (内部使用)
     */
    private function getFileUrl(string $fileId): ?string
    {
        $response = $this->request('getFile', ['file_id' => $fileId]);
        if (isset($response['result']['file_path'])) {
            $filePath = $response['result']['file_path'];
            return "https://api.telegram.org/file/bot{$this->botToken}/{$filePath}";
        }
        return null;
    }

    /**
     * 获取文件内容及其MIME类型
     */
    public function getFileContentWithMime(string $fileId): ?array
    {
        $fileUrl = $this->getFileUrl($fileId);
        if (!$fileUrl) {
            return null;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fileUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $content = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($content === false) {
            $this->logger?->error('TelegramAPI: 下载文件失败', ['url' => $fileUrl, 'error' => $curlError]);
            return null;
        }

        // 使用 finfo 直接从文件内容判断 MIME 类型，这是最可靠的方式
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($content);

        // 确保返回的是支持的图片类型
        $supportedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($mime, $supportedMimes)) {
            $this->logger?->warning('TelegramAPI: 下载的文件MIME类型不被支持', ['url' => $fileUrl, 'mime' => $mime]);
            return null;
        }

        return ['content' => $content, 'mime' => $mime];
    }

    /**
     * 核心请求方法
     *
     * @param string $method API 方法名
     * @param array $params 请求参数
     * @return array|null 成功时返回 API 响应数组，失败时返回 null
     */
    private function request(string $method, array $params = []): ?array
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, "{$this->apiUrl}/{$method}");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $responseJson = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($responseJson === false) {
            $this->logger?->error('TelegramAPI: cURL 请求失败', ['method' => $method, 'error' => $curlError]);
            return null;
        }

        $response = json_decode($responseJson, true);

        if (!$response['ok']) {
            $this->logger?->error('TelegramAPI: API 返回错误', [
                'method' => $method,
                'error_code' => $response['error_code'] ?? 'N/A',
                'description' => $response['description'] ?? 'N/A',
            ]);
            return null;
        }

        return $response;
    }
}