<?php

declare(strict_types=1);

namespace ChatBot\Database;

use ChatBot\Core\Logger;
use Redis;
use RedisException;

/**
 * Redis 键值存储驱动
 * 需要 PHP 的 `redis` 扩展。
 */
class RedisStore implements KVStoreInterface
{
    private ?Redis $redis;
    private ?Logger $logger;

    public function __construct(array $config, ?Logger $logger = null)
    {
        $this->logger = $logger;
        
        if (!class_exists('Redis')) {
            $this->logger?->error('RedisStore: PHP Redis 扩展未安装或未启用。');
            throw new \RuntimeException("PHP Redis 扩展未安装或未启用。");
        }

        $this->redis = new Redis();
        try {
            // 使用 pconnect 实现持久连接，提高性能
            $this->redis->pconnect($config['host'], $config['port']);
            
            if (!empty($config['password'])) {
                $this->redis->auth($config['password']);
            }
            if (isset($config['database'])) {
                $this->redis->select($config['database']);
            }
        } catch (RedisException $e) {
            $this->logger?->error('RedisStore: 无法连接到 Redis 服务器', ['error' => $e->getMessage()]);
            $this->redis = null; // 连接失败，将 redis 实例置为 null
        }
    }

    public function get(string $key)
    {
        if (!$this->redis) return null;

        $value = $this->redis->get($key);
        return $value ? json_decode($value, true) : null;
    }

    public function set(string $key, $value): bool
    {
        if (!$this->redis) return false;

        $jsonValue = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $this->redis->set($key, $jsonValue);
    }

    public function delete(string $key): bool
    {
        if (!$this->redis) return false;

        return $this->redis->del($key) > 0;
    }

    public function exists(string $key): bool
    {
        if (!$this->redis) return false;

        return (bool)$this->redis->exists($key);
    }
}