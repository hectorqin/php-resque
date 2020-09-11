<?php
namespace Resque\Listener;

use Resque\Event;
use Resque\Resque;
use Resque\Stat;

class StatsListener implements ListenerInterface
{
    public function init()
    {
        Event::listen('onWorkerStart', [$this, 'onWorkerStart']);
        Event::listen('beforeForkExecutor', [$this, 'beforeForkExecutor']);
        Event::listen('afterForkExecutor', [$this, 'afterForkExecutor']);
        Event::listen('beforePerformJob', [$this, 'beforePerformJob']);
        Event::listen('afterPerformJob', [$this, 'afterPerformJob']);
        Event::listen('onJobFailed', [$this, 'onJobFailed']);
        Event::listen('onWorkerStop', [$this, 'onWorkerStop']);
    }

    public function onWorkerStart($worker)
    {
        // echo "Worker started. Listening on queues: " . implode(', ', $worker->queues(false)) . "\n";
        Stat::incr('forked:worker');
    }

    public function beforeForkExecutor($job)
    {
        // echo "Just about to fork to run " . $job;
    }

    public function afterForkExecutor($job)
    {
        // echo "Forked to run " . $job . ". This is the child process.\n";
    }

    public function beforePerformJob($job)
    {
        // echo "Cancelling " . $job . "\n";
        //  throw new Resque_Job_DontPerform;
    }

    public function afterPerformJob($job)
    {
        // echo "Just performed " . $job . "\n";
        Stat::incr('processed:' . $job->queue);
    }

    public function onJobFailed($exception, $job)
    {
        // echo $job . " threw an exception:\n" . $exception;
        Stat::incr('failed:' . $job->queue);
    }

    public function onWorkerStop($worker)
    {
        Stat::incr('stoped:worker');
        $workerId = (string) $worker;
        $redis    = Resque::redis();
        $redis->rpush("workers:history", \serialize(array(
            'name'      => $workerId,
            'started'   => $redis->get('worker:' . $workerId . ':started'),
            'failed'    => $redis->get('stat:failed:' . $workerId) ?: 0,
            'processed' => $redis->get('stat:processed:' . $workerId) ?: 0,
            'stoped'    => strftime('%a %b %d %H:%M:%S %Z %Y'),
        )));
    }
}
