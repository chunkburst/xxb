<?php

// +----------------------------------------------------------------------
// | Telegram ChatBot 异步任务处理器
// | 职责：执行所有耗时和核心的业务逻辑
// +----------------------------------------------------------------------

declare(strict_types=1);

spl_autoload_register(function ($class) {
    $prefix = 'ChatBot\\';
    $base_dir = __DIR__ . '/src/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

use ChatBot\Core\Logger;
use ChatBot\Database\FileStore;
use ChatBot\Database\RedisStore;
use ChatBot\Services\TelegramAPI;
use ChatBot\Services\AIService;
use ChatBot\Business\ContextManager;
use ChatBot\Business\PersonaManager;
use ChatBot\Business\SystemPromptManager;
use ChatBot\Business\KnowledgeBaseManager;
use ChatBot\Services\ToolService;
use ChatBot\Services\ToolExecutor;

$config = require __DIR__ . '/config.php';

$logger = new Logger($config['log']);


if (php_sapi_name() === 'cli' || !isset($_SERVER['REQUEST_METHOD'])) {
    // cron task
} elseif ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $logger->warning('RequestWorker: 收到非POST请求');
    http_response_code(405);
    exit;
}

$payloadJson = file_get_contents('php://input');
$payload = json_decode($payloadJson, true);

if (!$payload || !isset($payload['type'], $payload['data'])) {
    $logger->error('RequestWorker: 无效的载荷', ['payload' => $payloadJson]);
    http_response_code(400);
    exit;
}

try {
    switch ($payload['type']) {
        case 'handle_message':
            handle_message($payload['data'], $config, $logger);
            break;
        default:
            $logger->warning('RequestWorker: 未知的任务类型', ['type' => $payload['type']]);
    }
} catch (\Throwable $e) {
    $logger->error('RequestWorker: 处理任务时发生致命错误', [
        'type' => $payload['type'],
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}

http_response_code(200);
echo "OK";


function initialize_services(array $config, Logger $logger): array
{
    $dbDriver = $config['database']['driver'] ?? 'file';
    $db = ($dbDriver === 'redis')
        ? new RedisStore($config['database']['redis'], $logger)
        : new FileStore($config['database']['file'], $logger);

    return [
        'db' => $db,
        'tg' => new TelegramAPI($config['telegram']['bot_token'], $logger),
        'ai' => new AIService($config, $logger),
        'contextManager' => new ContextManager($db, $config['context'], $logger),
        'personaManager' => new PersonaManager($config['business'], $logger),
        'systemPromptManager' => new SystemPromptManager($config['paths'], $logger),
        'toolService' => new ToolService($config, $logger),
        'toolExecutor' => new ToolExecutor(new ToolService($config, $logger), $logger),
        'knowledgeManager' => new KnowledgeBaseManager($config, $logger),
    ];
}

/**
 * 处理单条消息的核心逻辑
 */
function handle_message(array $message, array $config, Logger $logger): void
{
    global $payload; // 从全局范围获取payload
    $receivedAt = $payload['received_at'] ?? time();
    $processingStartTime = microtime(true);

    // 提前获取关键信息
    $chatId = (int)$message['chat']['id'];
    $messageId = (int)$message['message_id'];
    
    $logger->debug('开始处理新消息', [
        'chat_id' => $chatId,
        'message_id' => $messageId
    ]);

    // 初始化服务
    $services = initialize_services($config, $logger);
        $tg = $services['tg'];
        $ai = $services['ai'];
        $contextManager = $services['contextManager'];
        $personaManager = $services['personaManager'];
        $systemPromptManager = $services['systemPromptManager'];
        $toolService = $services['toolService'];
        $toolExecutor = $services['toolExecutor'];
        $knowledgeManager = $services['knowledgeManager'];

        $userId = (int)$message['from']['id'];
        $text = $message['text'] ?? $message['caption'] ?? '';
        $botUsername = $config['telegram']['bot_username'];

    $formattedMessage = [
        'role' => 'user',
        'uid' => $userId,
        'username' => $message['from']['username'] ?? '',
        'full_name' => trim(($message['from']['first_name'] ?? '') . ' ' . ($message['from']['last_name'] ?? '')),
        'timestamp' => $message['date'],
        'message_id' => $messageId,
    ];

    if (isset($message['text'])) {
        $formattedMessage['text_content'] = $message['text'];
    }
    if (isset($message['caption'])) {
        $formattedMessage['caption_content'] = $message['caption'];
    }

    if (isset($message['reply_to_message'])) {
        $replyTo = $message['reply_to_message'];
        $formattedMessage['reply_to'] = [
            'uid' => $replyTo['from']['id'],
            'username' => $replyTo['from']['username'] ?? '',
            'full_name' => trim(($replyTo['from']['first_name'] ?? '') . ' ' . ($replyTo['from']['last_name'] ?? '')),
            'content_message_id' => $replyTo['message_id'],
        ];
    }

    if (isset($message['forward_date']) || isset($message['forward_from']) || isset($message['forward_from_chat']) || isset($message['forward_sender_name'])) {
        $formattedMessage['is_forwarded'] = true;
        
        if (isset($message['forward_from'])) {
            $forwardFrom = $message['forward_from'];
            $formattedMessage['forward_from'] = [
                'uid' => $forwardFrom['id'],
                'username' => $forwardFrom['username'] ?? '',
                'full_name' => trim(($forwardFrom['first_name'] ?? '') . ' ' . ($forwardFrom['last_name'] ?? '')),
            ];
        } elseif (isset($message['forward_from_chat'])) {
            $forwardChat = $message['forward_from_chat'];
            $formattedMessage['forward_from_chat'] = [
                'id' => $forwardChat['id'],
                'title' => $forwardChat['title'] ?? '',
                'type' => $forwardChat['type'] ?? '',
            ];
        } elseif (isset($message['forward_sender_name'])) {
            $formattedMessage['forward_sender_name'] = $message['forward_sender_name'];
        }
    }

    $photos = $message['photo'] ?? null;
    if ($photos) {
        $fileId = end($photos)['file_id'];
        $formattedMessage['content_png'] = ['[图片解析中...]'];
        $formattedMessage['image_file_id'] = $fileId;
        $formattedMessage['image_status'] = 'pending';
        
        $hasTextContent = !empty($formattedMessage['text_content']) || !empty($formattedMessage['caption_content']);
        
        if ($hasTextContent) {
            $contextManager->addMessage($chatId, $formattedMessage);
            $logger->debug('已保存带图片占位符的消息到上下文', ['has_text' => true], $chatId);
        }
        
        try {
            $imagePrompt = $systemPromptManager->get('describe_image') ?: 'Describe this image in detail.';
            $imageParseStartTime = microtime(true);
            $description = $ai->getImageDescription($fileId, $imagePrompt);
            $imageParseTime = round(microtime(true) - $imageParseStartTime, 3);
            
            if ($description) {
                $formattedMessage['content_png'] = [$description];
                $formattedMessage['image_status'] = 'success';
                $logger->info('图片解析成功', ['parse_time' => $imageParseTime . 's', 'desc_length' => strlen($description)], $chatId);
                
                if ($hasTextContent) {
                    $updatedContext = $contextManager->getContext($chatId);
                    if (!empty($updatedContext)) {
                        $lastIndex = count($updatedContext) - 1;
                        if ($updatedContext[$lastIndex]['message_id'] == $messageId) {
                            $updatedContext[$lastIndex]['content_png'] = [$description];
                            $updatedContext[$lastIndex]['image_status'] = 'success';
                            $logger->debug('图片解析完成，但无法更新已保存的上下文', [], $chatId);
                        }
                    }
                }
            } else {
                $formattedMessage['content_png'] = ['[图片解析失败]'];
                $formattedMessage['image_status'] = 'failed';
                $logger->warning('图片解析失败', ['file_id' => $fileId], $chatId);
            }
        } catch (\Exception $e) {
            $formattedMessage['content_png'] = ['[图片解析异常]'];
            $formattedMessage['image_status'] = 'error';
            $logger->error('图片解析发生异常', ['error' => $e->getMessage()], $chatId);
        }
        
        if (!$hasTextContent && $formattedMessage['image_status'] !== 'success') {
            $logger->debug('消息只包含无法解析的图片，已忽略', [], $chatId);
            return;
        }
        
        $formattedMessage['_already_saved'] = $hasTextContent;
    } else {
        $formattedMessage['_already_saved'] = false;
    }

    if (empty($formattedMessage['text_content']) &&
        empty($formattedMessage['caption_content']) &&
        (empty($formattedMessage['content_png']) || $formattedMessage['content_png'][0] === '[图片解析失败]' || $formattedMessage['content_png'][0] === '[图片解析异常]')) {
        $logger->debug('消息既无文本也无有效图片描述，已忽略', [], $chatId);
        return;
    }
    
    $context = $contextManager->getContext($chatId);
    
    $contextManager->addMessage($chatId, $formattedMessage);
    $formattedMessage['_already_saved'] = true;
    
    $formattedMessage['_is_current_message'] = true;
    
    if ($photos && !$hasTextContent && $formattedMessage['image_status'] === 'pending') {
        $logger->debug('等待图片解析完成后再进行判定', [], $chatId);
        return;
    }
    
    $enableTimeCount = $config['debug']['time_count'] ?? false;
    $judgeStartTime = $enableTimeCount ? microtime(true) : 0;
    $judgePrompt = $systemPromptManager->get('judge');
    $action = 'IGNORE'; // 默认行为

    if (!$judgePrompt || !is_string($judgePrompt)) {
        $logger->error('缺少或无效的判定提示词(judge.txt)，执行默认忽略操作', [], $chatId);
    } else {
        $chatConfig = $config['business']['chat_configs'][$chatId] ?? [];
        $chatModels = $chatConfig['models'] ?? [];
        
        $knowledgeLoadStartTime = $enableTimeCount ? microtime(true) : 0;
        $judgeKnowledgeConfig = $config['business']['judge_uses_knowledge'] ?? ['enabled' => false];
        $knowledgeForJudge = '';
        if ($judgeKnowledgeConfig['enabled']) {
            $knowledgeForJudge = $knowledgeManager->getKnowledge($chatId, [
                'permanent' => $judgeKnowledgeConfig['permanent'] ?? true,
                'group' => $judgeKnowledgeConfig['group'] ?? true,
            ]);
        }
        if ($enableTimeCount) {
            $knowledgeLoadTime = round(microtime(true) - $knowledgeLoadStartTime, 3);
            $logger->debug('Judge知识库加载完成', ['time_spent' => $knowledgeLoadTime . 's', 'size' => strlen($knowledgeForJudge)], $chatId);
        }
        
        $judgeWindowSize = $config['context']['judge_window_size'] ?? 30;
        $recentContext = array_slice($context, -$judgeWindowSize);
        
        $judgeInput = [
            'knowledge_base' => $knowledgeForJudge,
            'chat_history' => $recentContext,
            'latest_message' => $formattedMessage,
        ];
        $userInput = json_encode($judgeInput, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
        $logger->debug('Judge模型上下文优化', [
            'total_context_size' => count($context),
            'judge_context_size' => count($recentContext),
            'window_size' => $judgeWindowSize,
            'input_size' => strlen($userInput)
        ], $chatId);
        
        $judgeMessages = [['role' => 'user', 'content' => $userInput]];

        $judgeApiStartTime = $enableTimeCount ? microtime(true) : 0;
        $rawAction = $ai->generateReply('judge', '', $judgePrompt, $judgeMessages);
        if ($enableTimeCount) {
            $judgeApiTime = round(microtime(true) - $judgeApiStartTime, 3);
            $logger->debug('Judge API调用完成', ['time_spent' => $judgeApiTime . 's', 'function' => 'judge'], $chatId);
        }
        
        if ($rawAction) {
            if (preg_match('/\{[^}]*"ACTION"\s*:\s*"([^"]+)"[^}]*\}/i', $rawAction, $matches)) {
                $action = strtoupper(trim($matches[1]));
                $logger->debug('从JSON格式中提取到action', ['raw' => $rawAction, 'extracted' => $action], $chatId);
            } else {
                $action = trim(strtoupper($rawAction), " \t\n\r\0\x0B`");
            }
            
            if (!in_array($action, ['REJECT', 'REPLY', 'REPLY_PRO', 'IGNORE'])) {
                $logger->warning('Judge返回了无效的action，使用默认值IGNORE', ['invalid_action' => $action, 'raw' => $rawAction], $chatId);
                $action = 'IGNORE';
            }
        } else {
            $action = 'IGNORE';
        }
    }

    if ($enableTimeCount) {
        $judgeTotalTime = round(microtime(true) - $judgeStartTime, 3);
        $logger->debug('安全与决策判定完成', ['action' => $action, 'total_time' => $judgeTotalTime . 's'], $chatId);
    } else {
        $logger->debug('安全与决策判定完成', ['action' => $action], $chatId);
    }

    switch ($action) {
        case 'REJECT':
            $logger->warning('检测到注入攻击，消息已丢弃', ['user_id' => $userId, 'text' => $text], $chatId);
            return;
        case 'REPLY':
        case 'REPLY_PRO':
            $serverResponseTime = round($processingStartTime - $receivedAt, 2);
            $decisionTime = round(microtime(true) - $processingStartTime, 2);
            $replyType = ($action === 'REPLY_PRO') ? '专业' : '普通';
            $logger->info("AI决定{$replyType}回复，服务器响应（{$serverResponseTime} 秒）用时 {$decisionTime} 秒", ['user_id' => $userId, 'action' => $action], $chatId);
            $replyStartTime = microtime(true);
            break;
        case 'IGNORE':
        default:
            if ($action !== 'IGNORE') {
                $logger->warning('收到未知的决策action，执行默认忽略操作', ['action' => $action], $chatId);
            }
            $logger->debug('消息已存入上下文，但AI决定不回复', ['user_id' => $userId], $chatId);
            return;
    }

    $replyPrepStartTime = $enableTimeCount ? microtime(true) : 0;
    
    $currentContext = $contextManager->getContext($chatId);
    
    $localContext = [];
    $currentMessageFound = false;
    
    foreach ($currentContext as $msg) {
        $msgCopy = $msg;
        if (isset($msg['message_id']) && $msg['message_id'] === $messageId) {
            $msgCopy['_is_current_message'] = true;
            $currentMessageFound = true;
        }
        $localContext[] = $msgCopy;
    }
    
    if (!$currentMessageFound) {
        $logger->warning('在上下文中未找到当前消息', [
            'message_id' => $messageId,
            'context_size' => count($currentContext)
        ], $chatId);
    }
    
    $prompt = $personaManager->getPrompt($userId);
    
    try {
        $date = new \DateTime("now", new \DateTimeZone('Asia/Shanghai'));
        $beijingTime = $date->format('Y-m-d H:i:s');
        $prompt = str_replace('{{current_time}}', $beijingTime, $prompt);
    } catch (\Exception $e) {
        $logger->warning('无法获取北京时间，将移除占位符', ['error' => $e->getMessage()], $chatId);
        $prompt = str_replace('{{current_time}}', 'N/A', $prompt);
    }

    $replyKnowledgeStartTime = $enableTimeCount ? microtime(true) : 0;
    $knowledgeForReply = $knowledgeManager->getKnowledge($chatId, ['permanent' => true, 'group' => true]);
    
    if (!empty($knowledgeForReply)) {
        $knowledgeSystemMessage = "You have access to a dossier...\n---{$knowledgeForReply}\n---";
        array_unshift($localContext, ['role' => 'system', 'content' => $knowledgeSystemMessage]);
    }
    
    if ($enableTimeCount) {
        $replyKnowledgeTime = round(microtime(true) - $replyKnowledgeStartTime, 3);
        $replyPrepTime = round(microtime(true) - $replyPrepStartTime, 3);
        $logger->debug('Reply模型准备完成', [
            'prep_time' => $replyPrepTime . 's',
            'knowledge_load_time' => $replyKnowledgeTime . 's',
            'knowledge_size' => strlen($knowledgeForReply),
            'context_size' => count($localContext),
            'current_message_found' => $currentMessageFound
        ], $chatId);
    }

    $sendStartTime = $enableTimeCount ? microtime(true) : 0;
    if (isset($replyText)) {
        $sentMessage = $tg->sendMessage($chatId, $replyText, ['reply_to_message_id' => $replyTargetMessageId ?? $messageId]);
    } elseif ($toolUsed) {
        $fallbackMessage = "我查看了相关信息，但似乎没有找到可以直接回答您问题的要点。";
        $sentMessage = $tg->sendMessage($chatId, $fallbackMessage, ['reply_to_message_id' => $messageId]);
        $replyText = $fallbackMessage;
    } else {
        $sentMessage = null;
    }

    if ($sentMessage) {
        $totalReplyTime = isset($replyStartTime) ? round(microtime(true) - $replyStartTime, 2) : -1;
        $logData = ['user_id' => $userId];
        if ($enableTimeCount) {
            $sendTime = round(microtime(true) - $sendStartTime, 3);
            $logData['send_time'] = $sendTime . 's';
            $logData['message_length'] = mb_strlen($replyText ?? '');
        }
        $logger->info("成功发送最终AI回复，共用时 {$totalReplyTime} 秒", $logData, $chatId);
        
        if (isset($sentMessage['result']['message_id'])) {
            $botMessage = [
                'role' => 'assistant',
                'content' => $rawReply,
                'uid' => $config['telegram']['bot_id'],
                'username' => $botUsername,
                'full_name' => 'Bot',
                'timestamp' => time(),
                'message_id' => $sentMessage['result']['message_id'],
                '_reply_to_message_id' => $finalReplyTargetId,
            ];
            
            $contextManager->addMessage($chatId, $botMessage);
            
            $logger->debug('Bot消息已保存到上下文', [
                'bot_message_id' => $sentMessage['result']['message_id'],
                'reply_to_message_id' => $finalReplyTargetId
            ], $chatId);
        } else {
            $logger->error('发送消息成功但未返回message_id', [
                'sent_message' => $sentMessage
            ], $chatId);
        }
    }
}