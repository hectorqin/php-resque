<?php

// 兼容TP5
if (class_exists("\\think\\App")) {
    if (version_compare(\think\App::VERSION, '5.1.0', '>=') && version_compare(\think\App::VERSION, '6.0.0', '<')) {
        \think\Console::addDefaultCommands([
            \Resque\ThinkPHP\Command\Resque::class,
        ]);
    }
}