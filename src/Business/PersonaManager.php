<?php

declare(strict_types=1);

namespace ChatBot\Business;

use ChatBot\Core\Logger;

/**
 * 人设管理器
 * 负责根据用户ID加载特定的人设 Prompt，或提供默认人设。
 */
class PersonaManager
{
    private string $personaPath;
    private string $defaultPersonaFile;
    private ?Logger $logger;

    public function __construct(array $config, ?Logger $logger = null)
    {
        $this->personaPath = $config['persona_path'];
        $this->defaultPersonaFile = $config['default_persona_file'];
        $this->logger = $logger;
    }

    /**
     * 根据用户ID获取人设 Prompt
     *
     * @param int $userId
     * @return string
     */
    public function getPrompt(int $userId): string
    {
        // 1. 尝试加载特定用户的人设文件
        $userPersonaFile = $this->personaPath . '/' . $userId . '.txt';
        if (file_exists($userPersonaFile) && is_readable($userPersonaFile)) {
            $this->logger?->info('加载了特定用户的人设', ['user_id' => $userId, 'file' => $userPersonaFile]);
            return file_get_contents($userPersonaFile);
        }

        // 2. 如果特定人设不存在，加载默认人设
        if (file_exists($this->defaultPersonaFile) && is_readable($this->defaultPersonaFile)) {
            $this->logger?->debug('加载了默认人设', ['file' => $this->defaultPersonaFile]);
            return file_get_contents($this->defaultPersonaFile);
        }

        // 3. 如果默认人设也不存在，返回一个硬编码的最终后备人设
        $this->logger?->warning('找不到任何可用的人设文件，使用硬编码的后备人设', [
            'specific_file' => $userPersonaFile,
            'default_file' => $this->defaultPersonaFile
        ]);
        
        return '你是一个AI助手。';
    }
}