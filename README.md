# PHP-Resque

在 [chrisboulton/php-resque](https://github.com/chrisboulton/php-resque) 的基础上进行了如下改造：

- 采用psr-4自动加载规范
- 合并 php-resque-scheduler
- 新增自定义处理方法worker
- 支持自定义worker
- 支持redis扩展及Predis扩展
- 支持Timer定时器功能
- 支持Crontab定时任务功能
- 支持ThinkPHP5/6命令行使用

## 安装

```bash
composer require hectorqin/php-resque
```

## 配置

```php
<?php

use Psr\Log\LogLevel;
use Resque\Listener\StatsListener;
use Resque\WorkerManager;

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
            "queue" => "deault",
            "nums"  => 1,
        ],
        [
            "type"  => "SchedulerWorker",
            "queue" => "default",
            "nums"  => 1,
        ],
        [
            "type"     => "CustomWorker",
            "queue"    => "dump_date",
            'interval' => 5,
            "nums"     => 2,
            // 可使用闭包
            "handler"  => function () {
                WorkerManager::log("current datetime" . date("Y-m-d H:i:s"));
            },
        ],
        [
            "type"        => "CrontabWorker",
            "queue"       => "crontab",
            "nums"        => 2,
            "workerQueue" => "default",
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
];
```

## 使用

### 启动worker

```bash
# 测试
./vendor/bin/resque

# ThinkPHP使用
php think resque start
# 守护进程
php think resque start -d
```

### 投递任务

#### 简单任务

handler为任务执行函数

```php
use Resque\Job\SimpleJob;

// 投递到 default 队列
SimpleJob::default([
    'handler' => ['\\app\\index\\controller\\Index', 'job'],
    'params'  => [
        'hello' => 0
    ], // ThinkPHP 支持参数绑定，其他框架请按照参数顺序传值
], true);

// 投递到 task 队列，延迟10秒执行
SimpleJob::task([
    'handler' => ['\\app\\index\\controller\\Index', 'job'],
    'params'  => [
        'hello' => 0
    ], // ThinkPHP 支持参数绑定，其他框架请按照参数顺序传值
], true, 10);

// 投递到 task 队列，定时执行
SimpleJob::task([
    'handler' => ['\\app\\index\\controller\\Index', 'job'],
    'params'  => [
        'hello' => 0
    ], // ThinkPHP 支持参数绑定，其他框架请按照参数顺序传值
], true, strtotime("2020-12-01"));
```

```php
<?php
namespace app\index\controller;

class Index
{
    function job($hello) {
        echo $hello;
    }
}
```

#### 自定义任务

编写自定义任务类，继承 SimpleJob，在 execute 方法内编写任务逻辑

```php
use Resque\Job\SimpleJob;
use Resque\Job;

/**
 * 自定义任务
 * @property array $args 任务参数
 * @property Job $job 任务对象
 * @property string $queue 队列名称
 */
class MyJob extends SimpleJob
{
    /**
     * 任务执行逻辑
     *
     * TP支持参数绑定，其余框架仅支持数组参数
     *
     * @return mixed
     * @throws ThinkException
     * @throws InvalidArgumentException
     * @throws ClassNotFoundException
     */
    public function execute($hello)
    {
        // 自定义任务逻辑
    }

    /**
     * 成功回调
     *
     * @return void
     */
    public function onSuccess()
    {
    }

    /**
     * 失败回调
     * @return void
     */
    public function onError()
    {
    }
}

// 投递任务
MyJob::enqueue([
    'hello' => 1
]);

// 延迟3秒执行任务
MyJob::enqueueAt([
    'hello' => 1
], 3);

// 一个小时后执行任务
MyJob::enqueueAt([
    'hello' => 1
], time() + 3600);
```

### 监听事件

```php
// Worker、SchedulerWorker、CustomWorker、CrontabWorker 启动事件
Event::listen('onWorkerStart', [$this, 'onWorkerStart']);
// Worker 创建job进程前事件
Event::listen('beforeForkExecutor', [$this, 'beforeForkExecutor']);
// Worker 创建job进程后事件
Event::listen('afterForkExecutor', [$this, 'afterForkExecutor']);
// 任务执行前事件
Event::listen('beforePerformJob', [$this, 'beforePerformJob']);
// 任务执行后事件
Event::listen('afterPerformJob', [$this, 'afterPerformJob']);
// 任务执行失败事件
Event::listen('onJobFailed', [$this, 'onJobFailed']);
// Worker、SchedulerWorker、CustomWorker、CrontabWorker 关闭事件
Event::listen('onWorkerStop', [$this, 'onWorkerStop']);
// 任务入队前事件
Event::listen('beforeEnqueue', [$this, 'beforeEnqueue']);
// 任务入队后事件
Event::listen('afterEnqueue', [$this, 'afterEnqueue']);
// 延迟/定时任务进入定时队列后事件
Event::listen('afterSchedule', [$this, 'afterSchedule']);
// 延迟/定时任务进入执行队列前事件
Event::listen('beforeDelayedEnqueue', [$this, 'beforeDelayedEnqueue']);
```
