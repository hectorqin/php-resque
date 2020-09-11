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
        foreach ($this->crontabManager->parse() as $crontab) {
            $this->run($crontab);
        }
    }

    /**
     * 执行crontab
     * @param Crontab $crontab
     * @return void
     */
    public function run($crontab)
    {
        if (!$crontab instanceof Crontab || !$crontab->getExecuteTime()) {
            $this->log(LogLevel::DEBUG, "not crontab");
            return;
        }
        $executeTime = $crontab->getExecuteTime();
        $executeTime = $executeTime instanceof \Carbon\Carbon ? $executeTime->getTimestamp() : $executeTime;

        // 限制worker并发，默认限制当前分钟内只执行一次
        $lockKey  = "crontab-" . sha1($crontab->getName() . $crontab->getRule() . $executeTime);
        $isLocked = Resque::redis()->set($lockKey, \getmypid(), ['NX', 'EX' => $crontab->getMutexExpires() ?: 60 - date('s', time())]);
        if (!$isLocked) {
            $this->log(LogLevel::DEBUG, "Crontab {$crontab->getName()} loop {$executeTime} lock failed");
            return;
        }

        // 单例任务
        $singletonLockKey = '';
        if ($crontab->isSingleton()) {
            $singletonLockKey = "crontab-singleton-" . sha1($crontab->getName() . $crontab->getRule());
        }

        $this->log(LogLevel::DEBUG, "Crontab {$crontab->getName()} loop {$executeTime} lock succeed");

        $diff     = $executeTime - time();
        $callback = $crontab->getCallback();
        $params   = $crontab->getParams();

        // 使用队列
        SimpleJob::{$this->workerQueue}([
            'handler'             => $callback,
            'params'              => $params,
            'executeTime'         => $executeTime,
            'executeDateTime'     => date("Y-m-d H:i:s", $executeTime),
            'lockKey'             => $lockKey,
            'singletonLockKey'    => $singletonLockKey,
            'singletonLockExpire' => $crontab->getMutexExpires() ?: (60 - date('s', time())),
            'beforeHandle'        => [static::class, 'clearCrontabJobLock'],
            'onComplete'          => [static::class, 'clearCrontabJobSingletonLock'],
        ], true, $diff);
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
    public function sleep()
    {
        $current = date('s', time());
        $sleep   = 60 - $current;
        $this->log(LogLevel::DEBUG, 'Crontab dispatcher sleep ' . $sleep . 's.');
        $sleep > 0 && sleep($sleep);
    }
}
