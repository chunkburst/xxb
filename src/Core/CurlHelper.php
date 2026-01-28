<?php

declare(strict_types=1);

namespace ChatBot\Core;

/**
 * cURL 辅助类
 * 封装了发送异步请求的功能
 */
class CurlHelper
{
    /**
     * 发送一个异步的 POST 请求 (即发即忘)
     *
     * @param string $url 请求的 URL
     * @param array $data 要 POST 的数据
     * @param int $timeout 超时时间 (秒)，设置为极短的时间来实现异步效果
     * @return bool 是否成功初始化了 cURL 请求
     */
    public static function sendAsyncPost(string $url, array $data, int $timeout = 1, ?Logger $logger = null): bool
    {
        $ch = curl_init();
        if ($ch === false) {
            $logger?->error('CurlHelper: Failed to initialize cURL session.');
            return false;
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        
        // 关键设置：实现异步的关键
        // CURLOPT_RETURNTRANSFER 设置为 false，脚本不等待响应
        // CURLOPT_TIMEOUT_MS 设置一个极短的超时，防止阻塞
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, $timeout * 1000);
        
        // 在某些环境中，需要忽略 SSL 证书验证
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        // 立即执行。对于异步请求，我们不关心返回值，因为它很可能会因为超时而返回 false。
        // 我们只关心请求是否被成功发起。
        curl_exec($ch);
        
        // 检查在执行 *之前* 是否有错误。
        $curlErrno = curl_errno($ch);
        
        // 对于异步调用，超时是预期的行为，不应视为错误。
        if ($curlErrno !== 0 && $curlErrno !== CURLE_OPERATION_TIMEDOUT) {
            $logger?->error('CurlHelper: cURL request failed before execution', ['errno' => $curlErrno, 'error' => curl_error($ch)]);
            curl_close($ch);
            return false;
        }
        
        curl_close($ch);

        // 只要没有初始化错误，我们就认为异步请求已成功派发。
        return true;
    }
}