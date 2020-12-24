<?php
declare (ticks = 1);
namespace Resque;

use Psr\Log\LogLevel;

/**
 * CustomWorker to handle custom tasks.
 */
class CustomWorker implements WorkerInterface
{
    /**
     * @var LoggerInterface Logging object that impliments the PSR-3 LoggerInterface
     */
    public $logger;

    /**
     * @var int Interval to sleep for between checking schedules.
     */
    protected $interval = 5;
    /**
     * @var boolean True if this worker is paused.
     */
    protected $paused = false;
    /**
     * @var boolean True if on the next iteration, the worker should shutdown.
     */
    protected $shutdown = false;

    /**
     * 回调函数
     * @var mixed
     */
    protected $handler = null;

    protected $name = '';

    protected $totalLoop = 0;
    protected $totalJob = 0;
    protected $busy = false;

    protected $workerPid = 0;

    protected $sleepUntil = 0;

    public $workerGroupCount = 1;
    public $workerIndex = 0;

    public $logTag = '';

    /**
     * Instantiate a new worker, given a list of queues that it should be working
     * on. The list of queues should be supplied in the priority that they should
     * be checked for jobs (first come, first served)
     *
     * Passing a single '*' allows the worker to work on all queues in alphabetical
     * order. You can easily add new queues dynamically and have them worked on using
     * this method.
     *
     * @param string|array $queues String with a single queue name, array with multiple.
     */
    public function __construct($name = '')
    {
        $this->hostname = php_uname('n');

        $this->name = (is_array($name) ? \implode(',', $name) : $name);
        $this->id = $this->hostname . ':' . getmypid() . ':custom_worker:' . $this->name;

        $this->logTag = 'CustomWorker:' . $this->name . ':' . getmypid();

        $this->workerPid = \getmypid();
    }

    /**
     * set options
     * @param array $options
     * @return $this
     */
    public function setOption($options)
    {
        if (isset($options['handler'])) {
            $this->handler = $options['handler'];
        }
        if (isset($options['workerGroupCount'])) {
            $this->workerGroupCount = $options['workerGroupCount'];
        }
        if (isset($options['workerIndex'])) {
            $this->workerIndex = $options['workerIndex'];
        }
        return $this;
    }

    /**
     * The primary loop for a worker.
     *
     * Every $interval (seconds), the scheduled queue will be checked for jobs
     * that should be pushed to Resque.
     *
     * @param int $interval How often to check schedules.
     */
    public function work($interval = Resque::DEFAULT_INTERVAL)
    {
        if (!is_int($interval) || $interval <= 0) {
            throw new Exception("interval must be a number that greater than zero");
        }
        $this->interval = $interval;
        $this->startup();
        $this->updateProcLine('Starting');
        while (true) {
            if ($this->shutdown) {
                break;
            }
            $this->totalLoop++;
            if ($this->paused) {
                $this->updateProcLine('Paused');
            } else {
                $this->updateProcLine('Running');
                try {
                    $this->log(LogLevel::DEBUG, "Running custom handler");
                    if (\method_exists($this, 'execute')) {
                        $this->execute();
                    } else if ($this->handler) {
                        $this->totalJob++;
                        \call_user_func_array($this->handler, [$this]);
                    }
                } catch (\Throwable $th) {
                    WorkerManager::log($th);
                    throw $th;
                } catch (\Exception $e) {
                    WorkerManager::log($e);
                    throw $e;
                }
            }
            $this->log(LogLevel::DEBUG, 'Sleeping for  ' . $this->interval);
            $this->sleep();
        }
        Event::trigger('onWorkerStop', $this);
        $this->unregisterWorker();
    }


    /**
     * 休眠
     * @param bool $start 是否开始新一轮休眠.
     * @param bool $force 是否强制休眠.
     * @return void
     */
    public function sleep($start = true, $force = false)
    {
        if ($start) {
            $this->sleepUntil = time() + $this->interval;
            \usleep($this->interval * 1000000);
        } else {
            if ($this->sleepUntil > time()) {
                \usleep(($this->sleepUntil - time()) * 1000000);
            } else if ($force) {
                $this->sleep();
            }
        }
    }

    /**
     * On supported systems (with the PECL proctitle module installed), update
     * the name of the currently running process to indicate the current state
     * of a worker.
     *
     * @param string $status The updated process title.
     */
    private function updateProcLine($status)
    {
        $processTitle = "resque-" . Resque::VERSION . ": " . $this->logTag . ' ' . $status;
        if (function_exists('cli_set_process_title') && PHP_OS !== 'Darwin') {
            @cli_set_process_title($processTitle);
        } else if (function_exists('setproctitle')) {
            @setproctitle($processTitle);
        }
    }

    /**
     * Output a given log message to STDOUT.
     *
     * @param string $message Message to output.
     */
    public function log($level, $message, $context = [])
    {
        if (!$this->logger) {
            $this->logger = new Log();
        }
        $this->logger->log($level, "[" . $this->logTag . "] " . $message, $context);
    }

    /**
     * Perform necessary actions to start a worker.
     */
    private function startup()
    {
        $this->registerSigHandlers();
        Event::trigger('onWorkerStart', $this);
        $this->registerWorker();
        \register_shutdown_function(function() {
            if ($this->workerPid == \getmypid()) {
                $this->unregisterWorker();
            }
        });
    }

    /**
     * Register signal handlers that a worker should respond to.
     *
     * TERM: Shutdown immediately and stop processing jobs.
     * INT: Shutdown immediately and stop processing jobs.
     * QUIT: Shutdown after the current job finishes processing.
     * USR1: Kill the forked child immediately and continue processing jobs.
     */
    private function registerSigHandlers()
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        pcntl_signal(SIGTERM, array($this, 'shutdownNow'));
        pcntl_signal(SIGINT, array($this, 'shutdownNow'));
        pcntl_signal(SIGQUIT, array($this, 'shutdownNow'));
        pcntl_signal(SIGUSR1, array($this, 'pauseProcessing'));
        pcntl_signal(SIGUSR2, array($this, 'writeStatistics'));
        pcntl_signal(SIGCONT, array($this, 'unPauseProcessing'));
        $this->log(LogLevel::DEBUG, 'Registered signals');
    }

    /**
     * Register this worker in Redis.
     */
    public function registerWorker()
    {
        Resque::redis()->sadd('workers', (string) $this);
        Resque::redis()->set('worker:' . (string) $this . ':started', strftime('%a %b %d %H:%M:%S %Z %Y'));
    }

    public function writeStatistics()
    {
        $statisticsFile = WorkerManager::getConf('STATISTICS_FILE');

        file_put_contents($statisticsFile,
            str_pad(posix_getpid(), 10) .
            str_pad(round(memory_get_usage(true) / (1024 * 1024), 2) . "M", 8) .
            str_pad(static::class, WorkerManager::$_maxWorkerTypeLength) .
            str_pad($this->name, WorkerManager::$_maxQueueNameLength) .
            str_pad(Timer::count(), 8) .
            str_pad($this->totalLoop, 13) .
            str_pad($this->totalJob, 13) .
            str_pad($this->busy ? '[busy]' : '[idle]', 6) . "\n", FILE_APPEND);
    }

    /**
     * Unregister this worker in Redis. (shutdown etc)
     */
    public function unregisterWorker()
    {
        $id = (string) $this;
        Resque::redis()->srem('workers', $id);
        Resque::redis()->del('worker:' . $id);
        Resque::redis()->del('worker:' . $id . ':started');
    }

    /**
     * Signal handler callback for USR2, pauses processing of new jobs.
     */
    public function pauseProcessing()
    {
        $this->log(LogLevel::DEBUG, 'USR2 received; pausing job processing');
        $this->paused = true;
    }

    /**
     * Signal handler callback for CONT, resumes worker allowing it to pick
     * up new jobs.
     */
    public function unPauseProcessing()
    {
        $this->log(LogLevel::DEBUG, 'CONT received; resuming job processing');
        $this->paused = false;
    }

    /**
     * Schedule a worker for shutdownNow. Will finish processing the current job
     * and when the timeout interval is reached, the worker will shut down.
     */
    public function shutdownNow()
    {
        $this->log(LogLevel::DEBUG, 'Shutting down');
        $this->shutdown = true;
    }

    /**
     * Inject the logging object into the worker
     *
     * @param Psr\Log\LoggerInterface $logger
     */
    public function setLogger(\Psr\Log\LoggerInterface $logger)
    {
        $this->logger = $logger;
        return $this;
    }

    /**
     * Generate a string representation of this worker.
     *
     * @return string String identifier for this worker instance.
     */
    public function __toString()
    {
        return $this->id;
    }
}
