<?php

declare(strict_types=1);

namespace ChatBot\Core;

/**
 * 消息去重器
 * 使用文件锁机制防止同一消息被重复处理
 */
class MessageDeduplicator
{
    private string $lockDir;
    private ?Logger $logger;
    private int $lockTimeout;
    private int $processingWindow;
    private int $chatLockTimeout; // 聊天级别的锁超时时间
    
    public function __construct(string $lockDir = null, ?Logger $logger = null, int $lockTimeout = 10, int $processingWindow = 5)
    {
        $this->lockDir = $lockDir ?? __DIR__ . '/../../storage/locks';
        $this->logger = $logger;
        $this->lockTimeout = $lockTimeout; // 锁定超时时间（秒），改为10秒
        $this->processingWindow = $processingWindow; // 处理窗口期（秒），只在这个时间内防止重复
        $this->chatLockTimeout = 3; // 聊天级别锁的超时时间（秒）
        
        // 确保锁目录存在
        if (!is_dir($this->lockDir)) {
            mkdir($this->lockDir, 0777, true);
        }
    }
    
    /**
     * 尝试获取消息处理锁
     *
     * @param int $chatId 聊天ID
     * @param int $messageId 消息ID
     * @param array $message 完整的消息数据（用于检查是否是主动的二次回复）
     * @return bool 是否成功获取锁（true表示可以处理，false表示消息正在处理）
     */
    public function tryAcquireLock(int $chatId, int $messageId, array $message = []): bool
    {
        // 高并发优化：先获取聊天级别的锁，确保同一聊天中同时只处理一条消息
        $chatLockFile = $this->getChatLockFile($chatId);
        $chatLockAcquired = false;
        
        // 尝试获取聊天锁（最多等待100ms）
        $maxAttempts = 10;
        $attemptDelay = 10000; // 10ms
        
        for ($i = 0; $i < $maxAttempts; $i++) {
            if ($this->tryAcquireChatLock($chatId)) {
                $chatLockAcquired = true;
                break;
            }
            usleep($attemptDelay);
        }
        
        if (!$chatLockAcquired) {
            $this->logger?->debug('无法获取聊天锁，可能有其他消息正在处理', [
                'chat_id' => $chatId,
                'message_id' => $messageId
            ]);
            return false;
        }
        
        $lockFile = $this->getLockFile($chatId, $messageId);
        $processedFile = $this->getProcessedFile($chatId, $messageId);
        
        // 清理过期的锁文件
        $this->cleanExpiredLocks();
        
        // 检查是否存在处理中的锁
        if (file_exists($lockFile)) {
            $lockTime = (int)file_get_contents($lockFile);
            $currentTime = time();
            
            // 如果锁还在有效期内，说明消息正在处理中
            if ($currentTime - $lockTime < $this->lockTimeout) {
                $this->logger?->debug('消息正在处理中，跳过', [
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    'lock_age' => $currentTime - $lockTime
                ]);
                return false;
            }
            
            // 锁已过期，删除并继续
            $this->logger?->debug('清理过期的处理锁', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'lock_age' => $currentTime - $lockTime
            ]);
            unlink($lockFile);
        }
        
        // 检查是否在处理窗口期内已经处理过
        if (file_exists($processedFile)) {
            $processedTime = (int)file_get_contents($processedFile);
            $currentTime = time();
            
            // 只在处理窗口期内防止重复
            if ($currentTime - $processedTime < $this->processingWindow) {
                $this->logger?->debug('消息在处理窗口期内已处理过，跳过', [
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    'window_age' => $currentTime - $processedTime
                ]);
                return false;
            }
            
            // 超过处理窗口期，删除标记文件，允许再次处理
            unlink($processedFile);
        }
        
        // 创建锁文件
        $written = file_put_contents($lockFile, (string)time(), LOCK_EX);
        
        if ($written === false) {
            $this->logger?->error('无法创建消息锁文件', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'lock_file' => $lockFile
            ]);
            // 释放聊天锁
            $this->releaseChatLock($chatId);
            return false;
        }
        
        // 获取消息锁成功后，立即释放聊天锁，允许其他消息排队
        $this->releaseChatLock($chatId);
        
        $this->logger?->debug('成功获取消息处理锁', [
            'chat_id' => $chatId,
            'message_id' => $messageId
        ]);
        
        return true;
    }
    
    /**
     * 释放消息处理锁并标记为已处理
     *
     * @param int $chatId 聊天ID
     * @param int $messageId 消息ID
     */
    public function releaseLock(int $chatId, int $messageId): void
    {
        $lockFile = $this->getLockFile($chatId, $messageId);
        $processedFile = $this->getProcessedFile($chatId, $messageId);
        
        // 删除处理锁
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }
        
        // 创建已处理标记
        file_put_contents($processedFile, (string)time());
        
        $this->logger?->debug('释放消息处理锁并标记为已处理', [
            'chat_id' => $chatId,
            'message_id' => $messageId
        ]);
    }
    
    /**
     * 获取锁文件路径
     */
    private function getLockFile(int $chatId, int $messageId): string
    {
        return $this->lockDir . '/' . abs($chatId) . '_' . $messageId . '.lock';
    }
    
    /**
     * 获取已处理标记文件路径
     */
    private function getProcessedFile(int $chatId, int $messageId): string
    {
        return $this->lockDir . '/' . abs($chatId) . '_' . $messageId . '.processed';
    }
    
    /**
     * 获取聊天锁文件路径
     */
    private function getChatLockFile(int $chatId): string
    {
        return $this->lockDir . '/chat_' . abs($chatId) . '.lock';
    }
    
    /**
     * 尝试获取聊天级别的锁
     */
    private function tryAcquireChatLock(int $chatId): bool
    {
        $lockFile = $this->getChatLockFile($chatId);
        
        // 检查是否已有锁
        if (file_exists($lockFile)) {
            $lockTime = (int)@file_get_contents($lockFile);
            $currentTime = time();
            
            // 如果锁还在有效期内，返回false
            if ($currentTime - $lockTime < $this->chatLockTimeout) {
                return false;
            }
            
            // 锁已过期，尝试删除
            @unlink($lockFile);
        }
        
        // 尝试创建锁文件（原子操作）
        $handle = @fopen($lockFile, 'x');
        if ($handle === false) {
            return false;
        }
        
        // 写入当前时间
        fwrite($handle, (string)time());
        fclose($handle);
        
        return true;
    }
    
    /**
     * 释放聊天级别的锁
     */
    private function releaseChatLock(int $chatId): void
    {
        $lockFile = $this->getChatLockFile($chatId);
        @unlink($lockFile);
    }
    
    /**
     * 清理过期的锁文件和处理标记
     */
    private function cleanExpiredLocks(): void
    {
        // 清理过期的锁文件
        $lockFiles = glob($this->lockDir . '/*.lock');
        if ($lockFiles) {
            $currentTime = time();
            $cleanedLocks = 0;
            
            foreach ($lockFiles as $file) {
                if (!file_exists($file)) {
                    continue;
                }
                
                // 跳过聊天锁文件，它们有自己的超时逻辑
                if (strpos(basename($file), 'chat_') === 0) {
                    $lockTime = (int)@file_get_contents($file);
                    if ($currentTime - $lockTime > $this->chatLockTimeout) {
                        @unlink($file);
                        $cleanedLocks++;
                    }
                    continue;
                }
                
                $lockTime = (int)file_get_contents($file);
                if ($currentTime - $lockTime > $this->lockTimeout) {
                    unlink($file);
                    $cleanedLocks++;
                }
            }
            
            if ($cleanedLocks > 0) {
                $this->logger?->debug('清理过期锁文件', ['count' => $cleanedLocks]);
            }
        }
        
        // 清理过期的处理标记（保留1小时）
        $processedFiles = glob($this->lockDir . '/*.processed');
        if ($processedFiles) {
            $currentTime = time();
            $cleanedProcessed = 0;
            
            foreach ($processedFiles as $file) {
                if (!file_exists($file)) {
                    continue;
                }
                
                $processedTime = (int)file_get_contents($file);
                if ($currentTime - $processedTime > 3600) { // 1小时后清理
                    unlink($file);
                    $cleanedProcessed++;
                }
            }
            
            if ($cleanedProcessed > 0) {
                $this->logger?->debug('清理过期处理标记', ['count' => $cleanedProcessed]);
            }
        }
        
    }
}