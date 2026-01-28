<?php

// +----------------------------------------------------------------------
// | 公共引导文件
// | 职责：为所有命令行和 Cron 脚本提供统一的初始化环境
// +----------------------------------------------------------------------

declare(strict_types=1);

// 1. 设置自动加载
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

// 2. 加载配置
$config = require __DIR__ . '/config.php';

// 3. 初始化日志服务
use ChatBot\Core\Logger;
$logger = new Logger($config['log']);

// 4. 返回核心服务实例，供调用方使用
return [
    'config' => $config,
    'logger' => $logger,
];