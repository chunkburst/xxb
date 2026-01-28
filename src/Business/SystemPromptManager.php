<?php

declare(strict_types=1);

namespace ChatBot\Business;

use ChatBot\Core\Logger;

/**
 * 系统提示词管理器
 * 负责从 /system_prompt/ 目录加载指定的提示词文件。
 */
class SystemPromptManager
{
    private string $promptPath;
    private ?Logger $logger;

    public function __construct(array $pathsConfig, ?Logger $logger = null)
    {
        if (!isset($pathsConfig['system_prompt_path'])) {
            throw new \InvalidArgumentException('System prompts path is not configured in the "paths" section of the config.');
        }
        $this->promptPath = $pathsConfig['system_prompt_path'];
        $this->logger = $logger;
    }

    /**
     * 根据名称获取系统提示词
     *
     * @param string $name (例如: 'judge', 'say_hello')
     * @return string|null
     */
    public function get(string $name): ?string
    {
        $filePath = $this->promptPath . '/' . $name . '.txt';

        if (file_exists($filePath) && is_readable($filePath)) {
            return file_get_contents($filePath);
        }

        $this->logger?->warning('找不到指定的系统提示词文件', [
            'name' => $name,
            'path' => $filePath
        ]);

        return null;
    }
}