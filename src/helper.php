<?php

if (class_exists("\\think\\App")) {
    if (\defined('\\think\\App::VERSION')) {
        // TP5 / 6 配置
        if (version_compare(\think\App::VERSION, '5.1.0', '>=') && version_compare(\think\App::VERSION, '6.0.0', '<')) {
            // 兼容TP5
            \think\Console::addDefaultCommands([
                \Resque\ThinkPHP\Command\Resque::class,
            ]);
        }
        // TP5/6 自动注入 redis 配置
        $redisConfig = \think\facade\Config::get('resque.redis_backend');
        if ($redisConfig) {
            \Resque\Resque::setBackend($redisConfig, isset($redisConfig['database']) ? $redisConfig['database'] : 0);
        }
    }
}