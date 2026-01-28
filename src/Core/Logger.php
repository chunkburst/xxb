<?php

declare(strict_types=1);

namespace ChatBot\Core;

/**
 * 一个简单的文件日志记录器，支持按 chatId 分割文件
 */
class Logger
{
    private const LOG_LEVELS = [
        'DEBUG'   => 100,
        'INFO'    => 200,
        'WARNING' => 300,
        'ERROR'   => 400,
    ];

    private string $logDirectory;
    private int $logLevel;
    private bool $enabled;

    public function __construct(array $config)
    {
        $this->enabled = $config['enabled'] ?? true;
        $this->logDirectory = $config['log_directory'] ?? __DIR__ . '/../../logs';
        $this->logLevel = self::LOG_LEVELS[strtoupper($config['level'] ?? 'INFO')] ?? self::LOG_LEVELS['INFO'];
        
        if ($this->enabled) {
            $this->ensureLogDirectoryExists();
        }
    }

    public function info(string $message, array $context = [], ?int $chatId = null): void
    {
        $this->log('INFO', $message, $context, $chatId);
    }

    public function error(string $message, array $context = [], ?int $chatId = null): void
    {
        $this->log('ERROR', $message, $context, $chatId);
    }
    
    public function warning(string $message, array $context = [], ?int $chatId = null): void
    {
        $this->log('WARNING', $message, $context, $chatId);
    }

    public function debug(string $message, array $context = [], ?int $chatId = null): void
    {
        $this->log('DEBUG', $message, $context, $chatId);
    }

    private function log(string $level, string $message, array $context = [], ?int $chatId = null): void
    {
        if (!$this->enabled || (self::LOG_LEVELS[$level] < $this->logLevel)) {
            return;
        }

        // 根据是否存在 chatId 决定日志文件名
        $logFile = $chatId ? "{$chatId}.log" : "app.log";
        $logPath = $this->logDirectory . '/' . $logFile;

        $logRecord = sprintf(
            "[%s] [%s] %s %s\n",
            date('Y-m-d H:i:s'),
            $level,
            $message,
            $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
        );

        file_put_contents($logPath, $logRecord, FILE_APPEND | LOCK_EX);
    }

    private function ensureLogDirectoryExists(): void
    {
        if (!is_dir($this->logDirectory)) {
            mkdir($this->logDirectory, 0775, true);
        }
    }
}