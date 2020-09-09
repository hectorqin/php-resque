<?php
namespace Resque\Listener;

use Resque\Event;
use Resque\Resque;
use Resque\Stat;

class StatsListener implements ListenerInterface
{
    public function init()
    {
        Event::listen('beforeFirstFork', [$this, 'beforeFirstFork']);
        Event::listen('beforeFork', [$this, 'beforeFork']);
        Event::listen('afterFork', [$this, 'afterFork']);
        Event::listen('beforePerform', [$this, 'beforePerform']);
        Event::listen('afterPerform', [$this, 'afterPerform']);
        Event::listen('onFailure', [$this, 'onFailure']);
        Event::listen('beforeStop', [$this, 'beforeStop']);
    }

    public function beforeFirstFork($worker)
    {
        // echo "Worker started. Listening on queues: " . implode(', ', $worker->queues(false)) . "\n";
        Stat::incr('forked:worker');
    }

    public function beforeFork($job)
    {
        // echo "Just about to fork to run " . $job;
    }

    public function afterFork($job)
    {
        // echo "Forked to run " . $job . ". This is the child process.\n";
    }

    public function beforePerform($job)
    {
        // echo "Cancelling " . $job . "\n";
        //  throw new Resque_Job_DontPerform;
    }

    public function afterPerform($job)
    {
        // echo "Just performed " . $job . "\n";
        Stat::incr('processed:' . $job->queue);
    }

    public function onFailure($exception, $job)
    {
        // echo $job . " threw an exception:\n" . $exception;
        Stat::incr('failed:' . $job->queue);
    }

    public function beforeStop($worker)
    {
        Stat::incr('stoped:worker');
        $workerId = (string) $worker;
        $redis    = Resque::redis();
        $redis->rpush("workers:history", json_encode(array(
            'name'      => $workerId,
            'started'   => $redis->get('worker:' . $workerId . ':started'),
            'failed'    => $redis->get('stat:failed:' . $workerId) ?: 0,
            'processed' => $redis->get('stat:processed:' . $workerId) ?: 0,
            'stoped'    => strftime('%a %b %d %H:%M:%S %Z %Y'),
        )));
    }
}
