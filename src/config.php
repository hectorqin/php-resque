<?php

use Psr\Log\LogLevel;
use Resque\Listener\StatsListener;

return [
    // 是否守护进程
    'daemonize'       => false,
    // redis 数据库信息
    'redis_backend'   => '',
    // redis 数据库编号
    'redis_database'  => '',
    // worker消费间隔
    'interval'        => 5,
    // worker组
    'worker_group'    => [
        [
            "type"  => "Worker",
            "queue" => "default",
            "nums"  => 1,
        ],
    ],

    // 动态加载文件，使用 glob 函数
    'app_include'     => '',

    // redis存储前缀，默认为 resque
    'prefix'          => '',

    // manager pid文件
    'pidfile'         => './resque.pid',

    // 日志文件
    'log_file'        => './resque.log',

    // status临时文件
    'statistics_file' => './resque.status',

    // 是否不调用pcntl_fork，不调用时只支持一个workerGroup，且只有一个进程
    'no_fork'         => false,

    // 是否记录info日志
    'verbose'         => false,
    // 是否记录debug日志
    'vverbose'        => false,
    // 日志记录级别
    'log_level'       => [
        LogLevel::EMERGENCY,
        LogLevel::ALERT,
        LogLevel::CRITICAL,
        LogLevel::ERROR,
        LogLevel::WARNING,
        LogLevel::NOTICE,
        // LogLevel::INFO,
    ],

    // worker事件监听器
    'listener' => [
        StatsListener::class,
    ],

    // manager进程定时器 ["interval"=>1, "handler"=>callable, "params"=>[], "persistent"=>true]
    // interval 时间间隔，handler 回调函数（可使用闭包），params 回调参数，persistent 是否周期性任务
    'manager_timer' => [

    ],

    // worker 上的 秒级 crontab ["name"=>'mycrontab',"rule"=>"0 * * * * *","handler"=>callable,"params"=>[]]
    // name 名称，rule 定时规则，handler 回调函数(不能使用闭包，闭包可以用opis/closure包装一下)，params 回调参数
    // 必须启动 CrontabWorker 和 SchedulerWorker
    'worker_crontab' => [

    ],

    // 为true时，将不能启动 CrontabWorker 进程，直接使用 Workers 中的 timer 来实现，无法持久化，但是支持闭包handler
    'simple_crontab' => false,
];