<?php
namespace Resque\Job;

use Exception;
use InvalidArgumentException;
use Resque\Concern\MagicCall;
use Resque\Job;
use Resque\Resque;
use Resque\ResqueScheduler;
use Resque\WorkerManager;
use think\Exception as ThinkException;
use think\exception\ClassNotFoundException;

/**
 * 简单任务
 *
 * 支持传入 callable 作为handler (使用serialize序列化，不支持资源类型参数，也尽量不要使用对象参数，对象序列化存在风险，如类本身发生变化，可能导致反序列化出错)。如果要使用闭包的话，可以使用 https://packagist.org/packages/opis/closure 扩展库， 传入 new \Opis\Closure\SerializableClosure(function(){}) 作为 handler。
 * @property array $args 任务参数
 * @property Job $job 任务对象
 * @property string $queue 队列名称
 */
class SimpleJob
{
    use MagicCall;
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
     *      'handler' => ['\\app\\index\\controller\\Index', 'job'],
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
        if (!isset($args['handler']) || !static::getCallable($args['handler'])) {
            throw new Exception('handler is not callable');
        }

        if (isset($args['onError']) && !static::getCallable($args['onError'])) {
            throw new Exception('onError is not callable');
        }

        if (isset($args['onSuccess']) && !static::getCallable($args['onSuccess'])) {
            throw new Exception('onSuccess is not callable');
        }

        if (isset($args['beforeHandle']) && !static::getCallable($args['beforeHandle'])) {
            throw new Exception('beforeHandle is not callable');
        }

        if (isset($args['onComplete']) && !static::getCallable($args['onComplete'])) {
            throw new Exception('onComplete is not callable');
        }

        if (isset($arguments[2]) && \is_int($arguments[2]) && $arguments[2] > 0) {
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
    public static function enqueue($params, $queue = null, $args = [])
    {
        if (\is_null($queue) && !\defined('static::QUEUE_NAME')) {
            throw new Exception('please input queue');
        }
        return Resque::enqueue($queue, static::class, \array_merge([
            'maxRetryTimes'=> static::MAX_RETRY_TIMES,
            'retrySeconds' => static::$retrySeconds,
            'params'       => $params,
        ], $args), static::TRACK_STATUS);
    }

    /**
     * 创建当前类的任务
     *
     * @param array $params 任务参数
     * @param int $time 延迟秒数或者时间戳
     * @param string $queue 队列名，默认为 static::QUEUE_NAME 的值
     * @param array $options 自定义任务选项
     * @return string|bool
     * @throws InvalidArgumentException
     */
    public static function enqueueAt($params, $time, $queue = null, $options = [])
    {
        if (\is_null($queue) && !\defined('static::QUEUE_NAME')) {
            throw new Exception('please input queue');
        }
        if (!is_int($time)) {
            throw new Exception('time must be interger');
        }
        $job =  \array_merge([
            'maxRetryTimes'=> static::MAX_RETRY_TIMES,
            'retrySeconds' => static::$retrySeconds,
            'params'       => $params,
        ], $options);

        // 定时任务
        if ($time > time()) {
            // 定时
            return ResqueScheduler::enqueueAt($time, $queue, static::class, $job);
        } else {
            // 延迟
            return ResqueScheduler::enqueueIn($time, $queue, static::class, $job);
        }
        return Resque::enqueue($queue, static::class, $job, static::TRACK_STATUS);
    }

    /**
     * 获取可执行的函数
     *
     * @param mixed $callable
     * @return false|callable
     */
    public static function getCallable($callable)
    {
        if (!$callable) {
            return false;
        }
        if (\is_callable($callable)) {
            return $callable;
        }

        if (is_array($callable) && \is_string($callable[0])) {
            $callable[0] = new $callable[0]();
        } else if (\is_object($callable) && \method_exists($callable, 'getClosure')) {
            // 支持 Opis\Closure\SerializableClosure 对象获取的闭包
            $callable = $callable->getClosure();
        } else {
            return false;
        }
        return \is_callable($callable) ? $callable : false;
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
        if (!$handler instanceof self && !$params instanceof self) {
            $this->log(\var_export($handler, true) . "\n" . \var_export($params, true));
        }
        if (class_exists("\\think\\App") && \defined('\\think\\App::VERSION')) {
            // TP5/6 框架支持参数绑定
            /** @var \think\App $app */
            $app = app();
            return $app->invoke($handler, (array)$params);
        } else {
            // 其余框架不支持参数绑定，直接回调
            $callable = static::getCallable($handler);
            if ($callable) {
                return \call_user_func_array($callable, is_array($params) ? array_values($params) : [$params]);
            }
        }
    }

    /**
     * 默认处理前回调
     * @return mixed
     * @throws ThinkException
     * @throws ClassNotFoundException
     * @throws InvalidArgumentException
     */
    public function beforeHandle()
    {
        if (isset($this->args['beforeHandle'])) {
            return $this->invoke($this->args['beforeHandle'], $this);
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
        if (\method_exists($this, 'execute')) {
            return $this->invoke([$this, 'execute'], $this->args['params'] ?? []);
        }
        if (isset($this->args['handler'])) {
            return $this->invoke($this->args['handler'], $this->args['params'] ?? []);
        }
    }

    /**
     * 默认完成回调
     * @return void
     */
    public function onComplete()
    {
        if (isset($this->args['onComplete'])) {
            return $this->invoke($this->args['onComplete'], $this);
        }
    }

    /**
     * 默认成功回调
     * @return void
     */
    public function onSuccess()
    {
        $this->onComplete();

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
        $this->onComplete();

        // 自定义错误处理
        if (isset($this->args['onError'])) {
            return $this->invoke($this->args['onError'], $this);
        }

        // 最后一次执行并且报错了
        if ($this->isLastRun && $this->lastError) {
            throw $this->lastError;
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
            $this->beforeHandle();
            $this->result = $this->handle();
        } catch(DontPerform $e) {
            // 允许取消执行
            throw $e;
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
        WorkerManager::log($message);
    }

    public function tearDown()
    {
        // ... Remove environment for this job
    }
}