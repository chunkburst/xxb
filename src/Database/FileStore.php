<?php

declare(strict_types=1);

namespace ChatBot\Database;

use ChatBot\Core\Logger;

/**
 * 文件/JSON 键值存储驱动
 * 将数据作为 JSON 文件存储在磁盘上。
 */
class FileStore implements KVStoreInterface
{
    private string $storagePath;
    private ?Logger $logger;

    public function __construct(array $config, ?Logger $logger = null)
    {
        $this->storagePath = $config['storage_path'] ?? __DIR__ . '/../../storage';
        $this->logger = $logger;
        $this->ensureStorageDirectoryExists();
    }

    public function setStoragePath(string $path): void
    {
        $this->storagePath = $path;
        $this->ensureStorageDirectoryExists();
    }

    public function get(string $key)
    {
        $filePath = $this->getFilePath($key);
        if (!file_exists($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);
        return $content ? json_decode($content, true) : null;
    }

    public function set(string $key, $value): bool
    {
        $filePath = $this->getFilePath($key);
        $jsonValue = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger?->error('FileStore: JSON 编码失败', ['key' => $key, 'error' => json_last_error_msg()]);
            return false;
        }

        // 使用文件锁防止并发写入冲突
        $result = file_put_contents($filePath, $jsonValue, LOCK_EX);

        return $result !== false;
    }

    public function delete(string $key): bool
    {
        $filePath = $this->getFilePath($key);
        if (file_exists($filePath)) {
            return unlink($filePath);
        }
        return true; // 如果文件不存在，也视为成功
    }

    public function exists(string $key): bool
    {
        $filePath = $this->getFilePath($key);
        return file_exists($filePath);
    }

    /**
     * 根据键名生成一个安全的文件路径
     * @param string $key
     * @return string
     */
    private function getFilePath(string $key): string
    {
        // 使用 hash 来避免非法字符和过长的文件名
        $safeFileName = hash('sha256', $key) . '.json';
        return $this->storagePath . '/' . $safeFileName;
    }

    /**
     * 确保存储目录存在
     */
    private function ensureStorageDirectoryExists(): void
    {
        if (!is_dir($this->storagePath)) {
            if (!mkdir($this->storagePath, 0775, true)) {
                $this->logger?->error('FileStore: 无法创建存储目录', ['path' => $this->storagePath]);
                // 如果无法创建目录，抛出异常可能更合适，因为这是个致命错误
                throw new \RuntimeException("无法创建存储目录: {$this->storagePath}");
            }
        }
    }
}