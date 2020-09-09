<?php
namespace Resque\Job;

use Exception;
use InvalidArgumentException;
use Resque\Resque;
use Resque\ResqueScheduler;
use think\Exception as ThinkException;
use think\exception\ClassNotFoundException;

class SimpleJob
{
    /**
     * 最大重试次数
     */
    const MAX_RETRY_TIMES = 0;

    /**
     * 跟踪状态
     */
    const TRACK_STATUS = true;

    /**
     * 重试间隔
     * @var int|array
     */
    public static $retrySeconds = 0;

    /**
     * 创建自定义任务
     *
     * SimpleJob::default([
     *      'hanlder' => ['\\app\\index\\controller\\Index', 'job'],
     *      'params'  => [
     *          'hello' => 0
     *      ]
     * ], true);
     * @param string $queue 队列名
     * @param array $arguments 参数
     * @return string|bool
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public static function __callStatic($queue, $arguments)
    {
        if (!$arguments) {
            throw new Exception('wrong arguments');
        }
        // 创建任务
        $args = $arguments[0];
        if (!isset($args['hanlder']) && !\is_callable($args['handler'])) {
            throw new Exception('handler is not callable');
        }

        if (isset($args['onError']) && !\is_callable($args['onError'])) {
            throw new Exception('onError is not callable');
        }

        if (isset($args['onSuccess']) && !\is_callable($args['onSuccess'])) {
            throw new Exception('onSuccess is not callable');
        }

        if (isset($arguments[2]) && \is_int($arguments[2])) {
            // 定时任务
            if ($arguments[2] > time()) {
                return ResqueScheduler::enqueueAt($arguments[2], $queue, static::class, $args);
            } else {
                return ResqueScheduler::enqueueIn($arguments[2], $queue, static::class, $args);
            }
        } else {
            return Resque::enqueue($queue, static::class, $args, isset($arguments[1]) ? $arguments[1] : static::TRACK_STATUS);
        }
    }

    /**
     * 创建当前类的任务
     *
     * @param string $queue
     * @param array $params
     * @param array $args
     * @return string|bool
     * @throws InvalidArgumentException
     */
    public static function create($queue, $params, $args = [])
    {
        return Resque::enqueue($queue, static::class, \array_merge([
            'hanlder'      => [static::class, 'handle'],
            'maxRetryTimes'=> static::MAX_RETRY_TIMES,
            'retrySeconds' => static::$retrySeconds,
            'params'       => $params,
        ], $args), static::TRACK_STATUS);
    }

	public function setUp()
    {
        // ... Set up environment for this job
    }

    /**
     * 执行callable
     *
     * @param mixed $handler
     * @param mixed $params
     * @return mixed
     * @throws ThinkException
     * @throws ClassNotFoundException
     * @throws InvalidArgumentException
     */
    public function invoke($handler, $params)
    {
        $this->log(\var_export($handler, true) . "\n" . \var_export($params, true));
        if (\class_exists("\\think\\App")) {
            // TP框架支持参数绑定
            /** @var \think\App $app */
            $app = app();
            return $app->invoke($handler, $params);
        } else {
            // 其余框架不支持参数绑定，直接回调
            return \call_user_func_array($handler, is_array($params) ? array_values($params) : [$params]);
        }
    }

    /**
     * 任务执行逻辑
     *
     * @return mixed
     * @throws ThinkException
     * @throws InvalidArgumentException
     * @throws ClassNotFoundException
     */
    public function handle()
    {
        return $this->invoke($this->args['handler'], $this->args['params']);
    }

    /**
     * 默认成功回调
     * @return void
     */
    public function onSuccess()
    {
        if (isset($this->args['onSuccess'])) {
            return $this->invoke($this->args['onSuccess'], $this);
        }
    }

    /**
     * 默认失败回调
     * @return void
     */
    public function onError()
    {
        if (isset($this->args['onSuccess'])) {
            return $this->invoke($this->args['onSuccess'], $this);
        }
    }

    /**
     * 任务执行
     * @return void
     */
    public function perform()
    {
        date_default_timezone_set("PRC");
        $this->result = false;
        $this->lastError = null;
        $this->isLastRun = true;
        $args = $this->args;
        try {
            $this->result = $this->handle();
        } catch(\Throwable $e) {
            $this->lastError = $e;
            $this->log($e->getMessage() . $e->getTraceAsString());
        } catch(\Exception $e) {
            $this->lastError = $e;
            $this->log($e->getMessage() . $e->getTraceAsString());
        }
        $this->log("result   " . var_export($this->result, true));
        if ($this->lastError) {
            $maxRetryTimes = static::MAX_RETRY_TIMES;
            $args['errorTimes'] = isset($args['errorTimes']) ? $args['errorTimes'] : 0;
            $retryDelay = 0;
            if (isset($args['maxRetryTimes'])) {
                $maxRetryTimes = $args['maxRetryTimes'];
            } else if (isset($args['retrySeconds'])) {
                if (is_array($args['retrySeconds'])) {
                    $maxRetryTimes = count($args['retrySeconds']);
                    $retryDelay = $args['retrySeconds'][$args['errorTimes']];
                } else {
                    $retryDelay = $args['retrySeconds'];
                }
            }

            $args['errorTimes']++;
            $this->args = $args;
            $this->job->payload['args'] = [$args];
            if ($args['errorTimes'] <= $maxRetryTimes) {
                $this->log('Recreate job: ' . $this->job->payload['id']);
                if ($retryDelay) {
                    ResqueScheduler::delayedPush(time() + $retryDelay, [
                        'args'  => $this->job->payload['args'],
			            'class' => $this->job->payload['class'],
                        'queue' => $this->job->payload['queue'],
                        'id'    => $this->job->payload['id'],
                    ]);
                } else {
                    $this->job->recreate(false);
                }

                $this->isLastRun = false;
            }
            $this->onError();
            return ;
        }
        // 任务完成回调
        $this->onSuccess();
    }

    public function log($message){
        $this->job->worker->logger->log('info', $message);
    }

    public function tearDown()
    {
        // ... Remove environment for this job
    }
}