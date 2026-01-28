<?php

declare(strict_types=1);

namespace ChatBot\Database;

/**
 * KV 存储接口
 * 定义了所有键值存储驱动必须实现的方法。
 * 这允许我们在文件存储和 Redis 存储之间轻松切换。
 */
interface KVStoreInterface
{
    /**
     * 根据键获取值
     *
     * @param string $key 键名
     * @return mixed|null 如果键不存在则返回 null
     */
    public function get(string $key);

    /**
     * 设置一个键值对
     *
     * @param string $key 键名
     * @param mixed $value 值
     * @return bool 是否成功
     */
    public function set(string $key, $value): bool;

    /**
     * 删除一个键
     *
     * @param string $key 键名
     * @return bool 是否成功
     */
    public function delete(string $key): bool;

    /**
     * 检查一个键是否存在
     *
     * @param string $key 键名
     * @return bool
     */
    public function exists(string $key): bool;
}