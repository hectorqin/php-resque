<?php

use Psr\Log\LogLevel;
use Resque\Listener\StatsListener;

return [
    'daemonize'       => false,
    'redis_backend'   => '',
    'redis_database'  => '',
    'interval'        => 5,
    'worker_group'    => [
        [
            "type"    => "Worker",
            "queue"   => "deault",
            "procnum" => 1,
        ],
    ],
    'blocking'        => false,
    'app_include'     => '',
    'prefix'          => '',
    'pidfile'         => './resque.pid',
    'log_file'        => './resque.log',
    'statistics_file' => './resque.status',

    'no_fork'         => false,

    'verbose'         => false,
    'vverbose'        => false,
    'log_level'       => [
        LogLevel::EMERGENCY,
        LogLevel::ALERT,
        LogLevel::CRITICAL,
        LogLevel::ERROR,
        LogLevel::WARNING,
        LogLevel::NOTICE,
        // LogLevel::INFO,
    ],

    'listener' => [
        StatsListener::class,
    ],
];