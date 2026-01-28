<?php

declare(strict_types=1);

namespace ChatBot\Business;

use ChatBot\Core\Logger;

/**
 * 知识库管理器
 * 负责处理每个群聊的长期记忆（知识库）的读取、写入和更新。
 * 直接操作 Markdown 文件，不再依赖数据库。
 */
class KnowledgeBaseManager
{
    private ?Logger $logger;
    private string $storagePath;

    public function __construct(array $config, ?Logger $logger = null)
    {
        $this->logger = $logger;
        
        $this->storagePath = $config['paths']['knowledge_base'] ?? '';
        if (empty($this->storagePath) || !is_dir($this->storagePath)) {
            throw new \InvalidArgumentException('Knowledge base path is not configured or is not a valid directory.');
        }
    }

    /**
     * 获取指定聊天的知识库
     *
     * @param int $chatId
     * @return string Markdown 格式的知识库文本
     */
    public function getKnowledge(int $chatId, array $options = []): string
    {
        // 默认全部加载，以实现向后兼容
        $loadPermanent = $options['permanent'] ?? true;
        $loadGroup = $options['group'] ?? true;

        $knowledgeParts = [];

        // 1. 根据选项加载全局的、人工维护的永久知识库
        if ($loadPermanent) {
            $permanentKnowledgePath = $this->storagePath . '/permanent_knowledge.md';
            if (file_exists($permanentKnowledgePath)) {
                $content = file_get_contents($permanentKnowledgePath);
                if (!empty(trim($content))) {
                    $knowledgeParts[] = "--- 全局知识库 ---\n" . trim($content);
                }
            }
        }

        // 2. 根据选项加载特定于该聊天的动态知识库
        if ($loadGroup) {
            $dynamicKnowledge = $this->getDynamicKnowledge($chatId);
            if (!empty(trim($dynamicKnowledge))) {
                $knowledgeParts[] = "--- 群组动态知识库 ---\n" . trim($dynamicKnowledge);
            }
        }

        // 3. 合并知识库
        return implode("\n\n", $knowledgeParts);
    }

    /**
     * 获取指定聊天的动态知识库 (不包含不动产知识)
     *
     * @param int $chatId
     * @return string Markdown 格式的动态知识库文本
     */
    public function getDynamicKnowledge(int $chatId): string
    {
        $dynamicKnowledgePath = $this->getKnowledgePath($chatId);
        if (file_exists($dynamicKnowledgePath)) {
            return file_get_contents($dynamicKnowledgePath) ?: '';
        }
        return '';
    }

    /**
     * 更新知识库
     *
     * @param int $chatId
     * @param string $newKnowledgeMarkdown 经过AI整理后的新知识库 (Markdown格式)
     * @return void
     */
    public function updateKnowledge(int $chatId, string $newKnowledgeMarkdown): void
    {
        $path = $this->getKnowledgePath($chatId);
        file_put_contents($path, $newKnowledgeMarkdown);
        $this->logger?->debug('知识库 Markdown 文件已更新', ['chat_id' => $chatId, 'path' => $path], $chatId);
        $this->logger?->debug('知识库 Markdown 文件已更新', ['chat_id' => $chatId, 'path' => $path], $chatId);
    }

    /**
     * 根据聊天 ID 生成知识库 Markdown 文件的完整路径
     *
     * @param int $chatId
     * @return string
     */
    private function getKnowledgePath(int $chatId): string
    {
        // 文件名直接使用 chat_id，例如 -100123456789.md
        return $this->storagePath . '/' . $chatId . '.md';
    }
}