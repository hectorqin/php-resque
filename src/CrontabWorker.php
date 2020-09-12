<?php
declare (ticks = 1);
namespace Resque;

use InvalidArgumentException;
use Psr\Log\LogLevel;
use Resque\Crontab\Crontab;
use Resque\Crontab\CrontabManager;
use Resque\Job\DontPerform;
use Resque\Job\SimpleJob;

/**
 * CrontabWorker to handle crontab tasks.
 */
class CrontabWorker extends CustomWorker
{
    protected $crontabManager = null;

    private $queue;

    private $isFirstRun = true;

    public $workerGroupCount = 1;
    public $workerIndex = 0;

    public function __construct($queue = '')
    {
        $this->queue = is_array($queue) ? $queue[0] : $queue;
        parent::__construct($this->queue);

        $this->id     = $this->hostname . ':' . getmypid() . ':crontab_worker:' . $this->queue;
        $this->logTag = 'CrontabWorker:' . $this->queue . ':' . getmypid();

        $this->crontabManager = CrontabManager::instance();
    }

    /**
     * set options
     * @param array $options
     * @return $this
     */
    public function setOption($options)
    {
        if (isset($options['workerQueue'])) {
            $this->workerQueue = $options['workerQueue'];
        }
        if (isset($options['workerGroupCount'])) {
            $this->workerGroupCount = $options['workerGroupCount'];
        }
        if (isset($options['workerIndex'])) {
            $this->workerIndex = $options['workerIndex'];
        }
        return $this;
    }

    /**
     * 执行逻辑
     * @return void
     * @throws InvalidArgumentException
     */
    public function execute()
    {
        // 第一次运行默认等待到下一分钟
        if ($this->isFirstRun) {
            $this->isFirstRun = false;
            return;
        }
        $workerQueue = $this->workerQueue;

        foreach ($this->crontabManager->parse() as $crontab) {
            static::runCrontab($crontab, $workerQueue);
        }
    }

    /**
     * 执行crontab
     * @param Crontab $crontab 定时任务对象
     * @param string $workerQueue 使用队列执行定时任务时需要队列名称
     * @param bool $useTimer 是否使用timer
     * @param mixed $currentWorker 当前worker
     * @return void
     */
    public static function runCrontab($crontab, $workerQueue, $useTimer = false, $currentWorker = null)
    {
        if (!$crontab instanceof Crontab || !$crontab->getExecuteTime()) {
            WorkerManager::log("not crontab", LogLevel::DEBUG);
            return;
        }
        $executeTime = $crontab->getExecuteTime();
        $executeTime = $executeTime instanceof \Carbon\Carbon ? $executeTime->getTimestamp() : $executeTime;

        // 限制worker并发，默认限制当前分钟内只执行一次
        $lockKey  = "crontab-" . sha1($crontab->getName() . $crontab->getRule() . $executeTime);
        $isLocked = Resque::redis()->set($lockKey, \getmypid(), ['NX', 'EX' => $crontab->getMutexExpires() ?: 60 - date('s', time())]);
        if (!$isLocked) {
            WorkerManager::log("Crontab {$crontab->getName()} loop {$executeTime} lock failed", LogLevel::DEBUG);
            return;
        }

        // 单例任务
        $singletonLockKey = '';
        if ($crontab->isSingleton()) {
            $singletonLockKey = "crontab-singleton-" . sha1($crontab->getName() . $crontab->getRule());
        }

        WorkerManager::log("Crontab {$crontab->getName()} loop {$executeTime} lock succeed", LogLevel::DEBUG);

        $diff     = $executeTime - time();
        $callback = $crontab->getCallback();
        $params   = $crontab->getParams();

        $args = [
            'handler'             => $callback,
            'params'              => $params,
            'executeTime'         => $executeTime,
            'executeDateTime'     => date("Y-m-d H:i:s", $executeTime),
            'lockKey'             => $lockKey,
            'singletonLockKey'    => $singletonLockKey,
            'singletonLockExpire' => $crontab->getMutexExpires() ?: (60 - date('s', time())),
            'beforeHandle'        => [static::class, 'clearCrontabJobLock'],
            'onComplete'          => [static::class, 'clearCrontabJobSingletonLock'],
        ];
        if ($useTimer) {
            $handler = function() use($args, $workerQueue, $currentWorker) {
                $job = new Job($workerQueue, [
                    'id'    => time(),
                    'class' => SimpleJob::class,
                    'args'  => [$args],
                    'queue' => $workerQueue
                ]);
                $job->worker = $currentWorker;
                $job->perform();
            };
            WorkerManager::$currentWorkerCrontabCount++;
            if ($diff <= 0) {
                try {
                    \call_user_func_array($handler, []);
                } catch (\Throwable $th) {
                    WorkerManager::log($th, LogLevel::CRITICAL);
                }
            } else {
                Timer::add($diff, $handler, [], false);
            }
            // 当前进程最多只能处理 WorkerManager::$maxOneWorkerCrontabCount 个任务
            WorkerManager::log("Current worker crontab count " . WorkerManager::$currentWorkerCrontabCount . " /" . WorkerManager::$maxOneWorkerCrontabCount, LogLevel::DEBUG);
            if (WorkerManager::$currentWorkerCrontabCount >= WorkerManager::$maxOneWorkerCrontabCount) {
                // 强制休眠一轮
                $currentWorker->sleep(false, true);
            }
            return true;
        } else {
            // 使用队列
            SimpleJob::{$workerQueue}($args, true, $diff);
            return true;
        }
    }

    /**
     * 删除crontab单例锁
     * @param mixed $key
     * @return mixed
     */
    public static function clearCrontabJobLock($job)
    {
        if (isset($job->args['lockKey']) && $job->args['lockKey']) {
            WorkerManager::log("clear crontab lock {$job->args['lockKey']}");
            Resque::redis()->del($job->args['lockKey']);
        }

        if (isset($job->args['singletonLockKey']) && $job->args['singletonLockKey']) {
            if (!Resque::redis()->set($job->args['singletonLockKey'], \getmypid(), ['NX', 'EX' => $job->args['singletonLockExpire']])) {
                throw new DontPerform('Crontab job singleton lock failed');
            }
        }
    }

    /**
     * 删除crontab单例锁
     * @param mixed $key
     * @return mixed
     */
    public static function clearCrontabJobSingletonLock($job)
    {
        if (isset($job->args['singletonLockKey']) && $job->args['singletonLockKey']) {
            WorkerManager::log("clear crontab lock {$job->args['singletonLockKey']}");
            return Resque::redis()->del($job->args['singletonLockKey']);
        }
    }

    /**
     * 睡眠至当前分钟结束
     * @return void
     */
    public function sleep($start = true, $force = false)
    {
        $current = date('s', time());
        $sleep   = 60 - $current;
        $this->log(LogLevel::DEBUG, 'Crontab dispatcher sleep ' . $sleep . 's.');
        $sleep > 0 && sleep($sleep);
    }
}
