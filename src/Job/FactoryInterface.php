<?php
namespace Resque\Job;

interface FactoryInterface
{
    /**
     * @param $className
     * @param array $args
     * @param $queue
     * @return JobInterface
     */
    public function create($className, $args, $queue);
}
