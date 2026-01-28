<?php

declare(strict_types=1);

namespace ChatBot\Services;

use ChatBot\Core\Logger;

/**
 * AI 服务调用封装
 * 负责与 AI 模型 API 进行交互，支持判定模型和回复模型。
 */
class AIService
{
    private ?Logger $logger;
    private TelegramAPI $tg;
    private string $capabilityCacheFile;
    private array $runtimeCache = [];

    public function __construct(array $config, ?Logger $logger = null)
    {
        $this->labels = $config['ai_labels'] ?? [];
        $this->usage = $config['ai_usage'] ?? [];
        $this->logger = $logger;
        $this->capabilityCacheFile = $config['paths']['capability_cache'] ?? '';
        
        $botToken = $config['telegram']['bot_token'] ?? null;
        if (!$botToken) {
            throw new \InvalidArgumentException('Telegram bot token is not configured.');
        }
        $this->tg = new TelegramAPI($botToken, $logger);

        $this->loadCapabilitiesFromCache();
    }
    
    /**
     * 获取是否启用时间追踪
     */
    private function isTimeCountEnabled(): bool
    {
        // 从配置中读取，这里暂时使用全局变量访问配置
        // 理想情况下应该通过构造函数传入
        global $config;
        return $config['debug']['time_count'] ?? false;
    }

    /**
     * 调用【判定模型】来决定是否需要回复
     */
    public function shouldReply(string $function, string $modelName, string $systemPrompt, array $context, array $latestMessage, string $knowledgeBase = ''): bool
    {
        $inputForAI = [
            'knowledge_base' => $knowledgeBase,
            'chat_history' => $context,
            'latest_message' => $latestMessage,
        ];

        $userInput = json_encode($inputForAI, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userInput]
        ];

        $response = $this->callWithBackup($function, $modelName, $messages);

        return $response !== null && strtolower(trim($response)) === 'yes';
    }

    /**
     * 调用【回复模型】来生成回复内容
     * 支持自动使用正确的模型标签和备用
     */
    public function generateReply(string $function, string $modelName, string $systemPrompt, array $messages, array $options = []): ?string
    {
        array_unshift($messages, ['role' => 'system', 'content' => $systemPrompt]);
        
        // 使用新的标签系统
        return $this->callWithBackup($function, $modelName, $messages, $options);
    }
    
    /**
     * 获取功能配置
     */
    private function getUsageConfig(string $function): ?array
    {
        return $this->usage[$function] ?? null;
    }
    
    /**
     * 获取标签配置
     */
    private function getLabelConfig(string $label): ?array
    {
        return $this->labels[$label] ?? null;
    }
    
    /**
     * 调用API，支持自动备用
     */
    private function callWithBackup(string $function, string $model, array $messages, array $options = []): ?string
    {
        $usageConfig = $this->getUsageConfig($function);
        if (!$usageConfig) {
            $this->logger?->error('未找到功能配置', ['function' => $function]);
            return null;
        }
        
        // 合并超时设置
        $options['timeout'] = $options['timeout'] ?? $usageConfig['timeout'] ?? 60;
        
        // 尝试主标签
        $primaryLabel = $usageConfig['label'];
        $result = $this->callByLabel($primaryLabel, $messages, $options);
        
        if ($result !== null) {
            return $result;
        }
        
        // 尝试备用标签（仅一次）
        $backupLabel = $usageConfig['backup'] ?? null;
        if ($backupLabel && isset($this->labels[$backupLabel])) {
            $this->logger?->warning('主标签调用失败，尝试备用标签', [
                'primary_label' => $primaryLabel,
                'backup_label' => $backupLabel,
                'function' => $function
            ]);
            
            return $this->callByLabel($backupLabel, $messages, $options);
        }
        
        return null;
    }

    /**
     * 调用【判定模型】来决定回复目标
     */
    public function getReplyTarget(string $function, string $modelName, string $systemPrompt, array $currentMessage, array $repliedMessage): string
    {
        $currentMsgText = "当前消息 (来自 {$currentMessage['from']['username']}): {$currentMessage['text']}";
        $repliedMsgText = "被回复的消息 (来自 {$repliedMessage['from']['username']}): {$repliedMessage['text']}";
        $userInput = "{$currentMsgText}\n{$repliedMsgText}";

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userInput]
        ];

        $response = $this->callWithBackup($function, $modelName, $messages);

        if ($response !== null && strtoupper(trim($response)) === 'CURRENT') {
            return 'CURRENT';
        }
        
        return 'REPLIED';
    }

    /**
     * 调用【分析模型】来决定对话的焦点 message_id
     */
    public function findConversationFocus(string $function, string $modelName, string $systemPrompt, array $context): ?int
    {
        $userInput = "## 聊天记录:\n" . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userInput]
        ];

        $response = $this->callWithBackup($function, $modelName, $messages);

        if ($response !== null && is_numeric(trim($response))) {
            return (int)trim($response);
        }

        return null;
    }

    /**
     * 调用【分析模型】来决定回复意图 (SELF or OTHER)
     */
    public function analyzeReplyIntent(string $function, string $modelName, string $systemPrompt, array $currentMessage, array $repliedMessage): string
    {
        $currentMsgText = "当前消息 (来自 {$currentMessage['from']['username']}): " . ($currentMessage['text'] ?? '');
        $repliedMsgText = "被回复的消息 (来自 {$repliedMessage['from']['username']}): " . ($repliedMessage['text'] ?? '');
        $userInput = "## 当前消息\n{$currentMsgText}\n\n## 被回复的消息\n{$repliedMsgText}";

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userInput]
        ];

        $response = $this->callWithBackup($function, $modelName, $messages);

        if ($response !== null && strtoupper(trim($response)) === 'OTHER') {
            return 'OTHER';
        }
        
        return 'SELF';
    }

    /**
     * 获取图片的文字描述
     */
    public function getImageDescription(string $fileId, string $prompt): ?string
    {
        $fileData = $this->tg->getFileContentWithMime($fileId);
        if (!$fileData) {
            $this->logger?->error('AIService: 无法获取图片文件内容或MIME类型', ['file_id' => $fileId]);
            return null;
        }
        
        $imageBase64 = base64_encode($fileData['content']);
        $mimeType = $fileData['mime'];

        // 获取视觉功能配置
        $visionUsage = $this->getUsageConfig('vision');
        if (!$visionUsage) {
            $this->logger?->error('AIService: 未配置视觉功能');
            return null;
        }
        
        $visionLabel = $visionUsage['label'];
        $visionConfig = $this->getLabelConfig($visionLabel);
        
        if (!$visionConfig) {
            $this->logger?->error('AIService: 未找到视觉标签配置', ['label' => $visionLabel]);
            return null;
        }

        if (!$this->checkVisionCapability($visionLabel)) {
            $this->logger?->warning('AIService: 所选模型不具备识图能力', ['model' => $visionConfig['model']]);
            return null;
        }

        $messages = [
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => "data:{$mimeType};base64,{$imageBase64}"
                        ]
                    ]
                ]
            ]
        ];
        
        return $this->callByLabel($visionLabel, $messages, ['timeout' => $visionUsage['timeout'] ?? 180]);
    }

    /**
     * 通过标签调用模型
     */
    private function callByLabel(string $label, array $messages, array $options = []): ?string
    {
        $labelConfig = $this->getLabelConfig($label);
        if (!$labelConfig) {
            $this->logger?->error('AIService: 未找到标签配置', ['label' => $label]);
            return null;
        }
        
        return $this->call($labelConfig, $messages, $options);
    }
    
    /**
     * 核心 AI API 调用方法
     */
    private function call(array $labelConfig, array $messages, array $options = []): ?string
    {
        $enableTimeCount = $this->isTimeCountEnabled();
        $callStartTime = $enableTimeCount ? microtime(true) : 0;

        $payload = [
            'model' => $labelConfig['model'],
            'messages' => $messages,
            'temperature' => 0.7,
        ];

        // 仅在显式配置了 max_tokens 时才将其添加到 payload 中
        $maxTokens = $options['max_tokens'] ?? null;
        if ($maxTokens !== null) {
            $payload['max_tokens'] = (int)$maxTokens;
        }

        $payloadSize = $enableTimeCount ? strlen(json_encode($payload)) : 0;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $labelConfig['endpoint']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $labelConfig['api_key'],
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, $options['timeout'] ?? 60);

        $curlStartTime = $enableTimeCount ? microtime(true) : 0;
        $responseJson = curl_exec($ch);
        
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($enableTimeCount) {
            $curlTime = round(microtime(true) - $curlStartTime, 3);
            $responseSize = strlen($responseJson ?: '');
            
            $this->logger?->debug('AI API调用详情', [
                'model' => $labelConfig['model'],
                'endpoint' => $labelConfig['endpoint'],
                'payload_size' => $payloadSize,
                'response_size' => $responseSize,
                'curl_time' => $curlTime . 's',
                'http_code' => $httpCode,
                'total_time' => round(microtime(true) - $callStartTime, 3) . 's'
            ]);
        }

        if ($responseJson === false) {
            $this->logger?->error('AIService: cURL 请求失败', ['model' => $labelConfig['model'], 'error' => $curlError]);
            return null;
        }

        $response = json_decode($responseJson, true);

        if ($httpCode !== 200 || !isset($response['choices'][0]['message']['content'])) {
            $this->logger?->error('AIService: API 返回无效响应', [
                'model' => $labelConfig['model'],
                'http_code' => $httpCode,
                'response_body' => $responseJson
            ]);
            return null;
        }

        return $response['choices'][0]['message']['content'];
    }
    
    /**
     * 检查模型是否具有识图能力
     */
    public function checkVisionCapability(string $label): bool
    {
        $config = $this->getLabelConfig($label);
        if (!$config) {
            return false;
        }
        
        $cacheKey = hash('sha256', json_encode([$config['endpoint'], $config['api_key'], $config['model']]));

        if (isset($this->runtimeCache[$cacheKey])) {
            return $this->runtimeCache[$cacheKey];
        }

        $prompt = "What is in this image?";
        $messages = [
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => 'data:image/png;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'
                        ]
                    ]
                ]
            ]
        ];

        $payload = ['model' => $config['model'], 'messages' => $messages, 'max_tokens' => 20];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $config['endpoint']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $config['api_key'],
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $isVisionModel = ($httpCode === 200);
        
        $this->runtimeCache[$cacheKey] = $isVisionModel;
        $this->saveCapabilitiesToCache();
        
        $this->logger?->info('模型能力检查', ['label' => $label, 'model' => $config['model'], 'vision_enabled' => $isVisionModel, 'http_code' => $httpCode]);

        return $isVisionModel;
    }

    private function loadCapabilitiesFromCache(): void
    {
        if (!empty($this->capabilityCacheFile) && file_exists($this->capabilityCacheFile)) {
            $cacheData = json_decode(file_get_contents($this->capabilityCacheFile), true);
            if (is_array($cacheData)) {
                $this->runtimeCache = $cacheData;
            }
        }
    }

    private function saveCapabilitiesToCache(): void
    {
        if (!empty($this->capabilityCacheFile)) {
            $dir = dirname($this->capabilityCacheFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            file_put_contents($this->capabilityCacheFile, json_encode($this->runtimeCache, JSON_PRETTY_PRINT));
        }
    }
}