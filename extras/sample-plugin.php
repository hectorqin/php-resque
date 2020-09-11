<?php
// Somewhere in our application, we need to register:

use Resque\Event;

Event::listen('afterEnqueue', array('My_Resque_Plugin', 'afterEnqueue'));
Event::listen('onWorkerStart', array('My_Resque_Plugin', 'onWorkerStart'));
Event::listen('beforeForkExecutor', array('My_Resque_Plugin', 'beforeForkExecutor'));
Event::listen('afterForkExecutor', array('My_Resque_Plugin', 'afterForkExecutor'));
Event::listen('beforePerformJob', array('My_Resque_Plugin', 'beforePerformJob'));
Event::listen('afterPerformJob', array('My_Resque_Plugin', 'afterPerformJob'));
Event::listen('onJobFailed', array('My_Resque_Plugin', 'onJobFailed'));

class My_Resque_Plugin
{
    public static function afterEnqueue($class, $arguments)
    {
        echo "Job was queued for " . $class . ". Arguments:";
        print_r($arguments);
    }

    public static function onWorkerStart($worker)
    {
        echo "Worker started. Listening on queues: " . implode(', ', $worker->queues(false)) . "\n";
    }

    public static function beforeFork($job)
    {
        echo "Just about to fork to run " . $job;
    }

    public static function afterFork($job)
    {
        echo "Forked to run " . $job . ". This is the child process.\n";
    }

    public static function beforePerformJob($job)
    {
        echo "Cancelling " . $job . "\n";
        //    throw new Resque_Job_DontPerform;
    }

    public static function afterPerformJob($job)
    {
        echo "Just performed " . $job . "\n";
    }

    public static function onJobFailed($exception, $job)
    {
        echo $job . " threw an exception:\n" . $exception;
    }
}
