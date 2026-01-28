<?php

// +----------------------------------------------------------------------
// | 异步 cURL Worker
// | 职责：接收URL，获取网页内容，并进行基础的文本提取
// +----------------------------------------------------------------------

declare(strict_types=1);

function html_to_text(string $html): string
{
    $html = preg_replace('/<script.*?<\/script>/is', '', $html);
    $html = preg_replace('/<style.*?<\/style>/is', '', $html);
    $text = strip_tags($html);
    $text = preg_replace('/\s+/', ' ', $text);
    return mb_substr(trim($text), 0, 2000);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$payloadJson = file_get_contents('php://input');
$payload = json_decode($payloadJson, true);

if (!isset($payload['url']) || !filter_var($payload['url'], FILTER_VALIDATE_URL)) {
    http_response_code(400);
    exit('Invalid URL provided');
}

$url = $payload['url'];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; ChatBot/1.0; +http://yourbot.com)'); // 设置User-Agent
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // 无视SSL证书
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

$html = curl_exec($ch);
$error = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($html === false) {
    http_response_code(500);
    echo json_encode(['error' => "cURL Error: " . $error]);
    exit;
}

if ($httpCode >= 400) {
    http_response_code(502);
    echo json_encode(['error' => "HTTP Error: " . $httpCode]);
    exit;
}

$textContent = html_to_text($html);

header('Content-Type: application/json');
echo json_encode([
    'url' => $url,
    'content' => $textContent,
    'content_length' => mb_strlen($textContent)
]);