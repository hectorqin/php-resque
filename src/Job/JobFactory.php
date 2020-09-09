<?php
namespace Resque\Job;

use Resque\Exception;

class JobFactory implements FactoryInterface
{

    /**
     * @param $className
     * @param array $args
     * @param $queue
     * @return Resque_JobInterface
     * @throws Exception
     */
    public function create($className, $args, $queue)
    {
        if (!class_exists($className)) {
            throw new Exception(
                'Could not find job class ' . $className . '.'
            );
        }

        if (!method_exists($className, 'perform')) {
            throw new Exception(
                'Job class ' . $className . ' does not contain a perform method.'
            );
        }

        $instance        = new $className;
        $instance->args  = $args;
        $instance->queue = $queue;
        return $instance;
    }
}
