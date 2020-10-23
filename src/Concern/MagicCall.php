<?php
namespace Resque\Concern;

use Resque\Job\SimpleJob;

/**
 * 在 controller 内 use 本 trait 文件，可快捷调用队列任务
 *
 *
 *  Class Test
 *  {
 *      use \Resque\Concern\MagicCall;
 *
 *      function testResque($name)
 *      {
 *          trace('Hello ' . $name);
 *      }
 *
 *      function test()
 *      {
 *          $this->setDefaultQueue('default');
 *          $this->testResqueAsync('hector');
 *          $this->delay(5)->testResqueAsync('hector');
 *          $this->at(time() + 10)->testResqueAsync('hector');
 *          $this->at(time() + 15)->handler([\get_class(), 'testResque'], [
 *              'hector'
 *          ])->send();
 *
 *
 *          // 同步执行
 *          $this->sync(true)->testResqueAsync('hector');
 *          // 设置为默认同步执行
 *          $this->setSync(true);
 *      }
 *  }
 *
 *
 * @method static delay(int $seconds) 延迟执行
 * @method static at(int $timestamp) 定时执行
 * @method static queue(string $queue) 设置队列名
 * @method static job(string $jobClass) 设置JobClass
 * @method static handler(string $handler, array $params = []) 设置handler
 * @method static send() 提交任务
 * @method static ${method}Async() 提交 $method 任务
 */
trait MagicCall
{
    protected $__options = [];

    protected $__methods = ['delay', 'at', 'queue', 'sync', 'job', 'handler'];

    protected $defaultQueueName = null;

    protected $defaultJobClass = SimpleJob::class;

    protected $async = true;

    function setDefaultQueue($queueName)
    {
        $this->defaultQueueName = $queueName;
        return $this;
    }

    function setDefaultJob($jobClass)
    {
        $this->defaultJobClass = $jobClass;
        return $this;
    }

    function setSync($sync)
    {
        $this->async = !$sync;
        return $this;
    }

    function __call($name, $arguments)
    {
        return $this->__magicCall($name, $arguments);
    }

    function __magicCall($name, $arguments)
    {
        if (in_array($name, $this->__methods)) {
            if ($name == 'handler') {
                $this->__options['handler'] = $arguments;
            } else {
                $this->__options[$name] = $arguments[0];
            }
            return $this;
        } else if(\stripos($name, 'async') >= 0) {
            $name = \str_ireplace('async', '', $name);
            if (!\method_exists($this, $name)) {
                throw new \Exception('method does not exist');
            }
            $this->__options['handler'] = [
                [\get_class(), $name], // handler
                $arguments, // params
            ];
            return $this->send();
        }
        throw new \Exception('method does not exist');
    }

    function send()
    {
        $options = $this->__options;
        $this->__options = [];
        if (!$this->async || (isset($options['sync']) && $options['sync'])) {
            return \call_user_func_array($options['handler'][0], $options['handler'][1]);
        }

        $jobClass = isset($options['job']) ? $options['job'] : $this->defaultJobClass;

        if (!\is_a($jobClass, SimpleJob::class, true)) {
            throw new \Exception('Job must be a subclass of Resque\\Job\\SimpleJob');
        }

        if (!isset($options['handler'])) {
            throw new \Exception('handler must be specificed');
        }

        $queue = isset($options['queue']) ? $options['queue'] : (\defined($jobClass . '::QUEUE_NAME') ? $jobClass::QUEUE_NAME : $this->defaultQueueName);
        if (!$queue) {
            throw new \Exception('Please provide queue name');
        }

        return $jobClass::{$queue}([
            'handler' => $options['handler'][0],
            'params'  => $options['handler'][1],
        ], true, $options['at'] ?? $options['delay'] ?? null);
    }
}