<?php

declare(strict_types=1);

namespace ChatBot\Business;

use ChatBot\Database\KVStoreInterface;
use ChatBot\Core\Logger;

/**
 * 上下文管理器
 * 负责处理聊天上下文的读取、写入和自动清理。
 */
class ContextManager
{
    private KVStoreInterface $db;
    private ?Logger $logger;
    private int $maxLength;
    private int $truncateSize;

    public function __construct(KVStoreInterface $db, array $config, ?Logger $logger = null)
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->maxLength = $config['max_length'] ?? 3000;
        $this->truncateSize = $config['truncate_size'] ?? 1000;
    }

    /**
     * 获取指定聊天的上下文
     *
     * @param int $chatId
     * @return array
     */
    public function getContext(int $chatId): array
    {
        return $this->db->get($this->getContextKey($chatId)) ?? [];
    }

    /**
     * 向上下文中添加一条新消息，并处理清理逻辑
     *
     * @param int $chatId
     * @param array $message 格式化后的消息
     * @return void
     */
    public function addMessage(int $chatId, array $message): void
    {
        $context = $this->getContext($chatId);
        $context[] = $message;

        if (count($context) > $this->maxLength) {
            $this->logger?->info('上下文达到上限，执行清理', [
                'chat_id' => $chatId,
                'current_size' => count($context),
                'max_size' => $this->maxLength
            ]);
            // 从数组开头删除最旧的记录
            $context = array_slice($context, $this->truncateSize);
        }

        $this->db->set($this->getContextKey($chatId), $context);
    }

    /**
     * 根据聊天 ID 生成数据库键名
     *
     * @param int $chatId
     * @return string
     */
    private function getContextKey(int $chatId): string
    {
        return "context_chat_{$chatId}";
    }
}