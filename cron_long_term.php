<?php

// +----------------------------------------------------------------------
// | Telegram ChatBot 知识库同步更新处理器 (带缓存)
// | 职责：由系统 crontab 定时调用，为聊天记录有变化的群组执行知识库更新。
// +----------------------------------------------------------------------

declare(strict_types=1);

// --- 1. 引入公共引导文件 ---
$bootstrap = require_once __DIR__ . '/bootstrap.php';
$config = $bootstrap['config'];
$logger = $bootstrap['logger'];

use ChatBot\Services\AIService;
use ChatBot\Business\ContextManager;
use ChatBot\Business\KnowledgeBaseManager;
use ChatBot\Business\SystemPromptManager;
use ChatBot\Database\FileStore;
use ChatBot\Database\RedisStore;

// --- 2. 初始化 ---

$logger->info('KnowledgeCron: 带缓存的同步知识库更新任务启动。');

// --- 3. 初始化服务和缓存 ---
$dbDriver = $config['database']['driver'] ?? 'file';
$db = ($dbDriver === 'redis')
    ? new RedisStore($config['database']['redis'], $logger)
    : new FileStore($config['database']['file'], $logger);

$ai = new AIService($config, $logger);
$contextManager = new ContextManager($db, $config['context'], $logger);
$knowledgeManager = new KnowledgeBaseManager($config, $logger);
$systemPromptManager = new SystemPromptManager($config['paths'], $logger);

$hashCacheFile = __DIR__ . '/storage/cache/knowledge_hashes.json';
$hashes = [];
if (file_exists($hashCacheFile)) {
    $hashes = json_decode(file_get_contents($hashCacheFile), true) ?? [];
}

// --- 4. 获取需要处理的群组列表 ---
// 从新的配置结构中获取启用了知识库更新功能的群组
$targetChats = [];
$chatConfigs = $config['business']['chat_configs'] ?? [];
foreach ($chatConfigs as $chatId => $chatConfig) {
    if ($chatConfig['enable_cron_update'] ?? false) {
        $targetChats[] = $chatId;
    }
}

if (empty($targetChats)) {
    $logger->info('KnowledgeCron: 未配置任何目标群组，任务退出。');
    exit;
}

$logger->info('KnowledgeCron: 开始为 ' . count($targetChats) . ' 个群组检查并更新知识库。');

// --- 5. 循环同步处理 ---
foreach ($targetChats as $chatId) {
    $chatId = (int)$chatId;
    $knowledgeUpdateStartTime = microtime(true);
    
    try {
        $context = $contextManager->getContext($chatId);

        // --- 缓存检查逻辑 ---
        $currentHash = hash('sha256', json_encode($context));
        $lastHash = $hashes[$chatId] ?? null;

        if ($currentHash === $lastHash) {
            $logger->debug('KnowledgeCron: 聊天记录未变化，跳过总结。', ['chat_id' => $chatId]);
            continue; // 跳到下一个群组
        }
        // --- 缓存检查结束 ---

        $logger->info('KnowledgeCron: 聊天记录有变化，开始处理群组。', ['chat_id' => $chatId]);

        $dynamicKnowledge = $knowledgeManager->getDynamicKnowledge($chatId);
        $prompt = $systemPromptManager->get('summarize_knowledge');

        if (!$prompt) {
            $logger->error('KnowledgeCron: 找不到知识库整理的提示词', [], $chatId);
            continue;
        }
        
        if (empty($context)) {
            $logger->debug('KnowledgeCron: 上下文为空，无需整理', [], $chatId);
            $hashes[$chatId] = $currentHash; // 记录空内容的哈希，避免重复处理
            continue;
        }

        // --- 新增：计算元数据 ---
        $totalMessages = count($context);
        $botMessages = 0;
        if ($totalMessages > 0) {
            foreach ($context as $message) {
                if (isset($message['uid']) && $message['uid'] == ($config['telegram']['bot_id'] ?? 0)) {
                    $botMessages++;
                }
            }
        }
        $botActivityScore = ($totalMessages > 0) ? (int)(($botMessages / $totalMessages) * 100) : 0;
        // --- 计算结束 ---

        $inputForAI = [
            'current_knowledge_base' => $dynamicKnowledge,
            'chat_history' => $context,
            'metadata' => [
                'generation_time' => gmdate('Y-m-d H:i:s') . ' UTC',
                'bot_activity_score' => $botActivityScore,
            ],
        ];
        
        $messages = [['role' => 'user', 'content' => json_encode($inputForAI, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]];
        $newKnowledgeMarkdown = $ai->generateReply('summarize', '', $prompt, $messages);

        if (!is_string($newKnowledgeMarkdown)) {
            $logger->error('KnowledgeCron: AI Service 未能返回字符串', ['response_type' => gettype($newKnowledgeMarkdown)], $chatId);
            continue;
        }
        
        // 只要AI成功返回，就更新哈希值，因为这个context已经被“处理”过了
        $hashes[$chatId] = $currentHash;

        $newKnowledgeMarkdown = trim($newKnowledgeMarkdown);
        if (empty($newKnowledgeMarkdown) || $newKnowledgeMarkdown === 'NO_KNOWLEDGE_UPDATE') {
            $logger->debug('KnowledgeCron: AI判断无需更新或返回为空', [], $chatId);
        } else {
            $knowledgeManager->updateKnowledge($chatId, $newKnowledgeMarkdown);
            $elapsedTime = round(microtime(true) - $knowledgeUpdateStartTime, 2);
            $logger->info("KnowledgeCron: 知识库已成功更新，用时 {$elapsedTime} 秒", ['chat_id' => $chatId, 'length' => strlen($newKnowledgeMarkdown)], $chatId);
        }

    } catch (\Throwable $e) {
        $logger->error('KnowledgeCron: 处理群组时发生致命错误', [
            'chat_id' => $chatId,
            'error' => $e->getMessage(),
        ]);
    }
    
    sleep(5); // 稍微降低请求频率
}

// --- 6. 保存更新后的哈希缓存 ---
file_put_contents($hashCacheFile, json_encode($hashes, JSON_PRETTY_PRINT));
$logger->info('KnowledgeCron: 所有任务处理完毕，哈希缓存已保存。');