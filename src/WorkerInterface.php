<?php
namespace Resque;

interface WorkerInterface
{
    /**
     * start loop
     * @param int $interval
     * @return mixed
     */
    public function work($interval = Resque::DEFAULT_INTERVAL);

    /**
     * set options
     * @param array $options
     * @return $this
     */
    public function setOption($options);


    /**
     * Inject the logging object into the worker
     *
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function setLogger(\Psr\Log\LoggerInterface $logger);
}
