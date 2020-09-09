<?php
declare (ticks = 1);
namespace Resque;

use Psr\Log\LogLevel;

/**
 * ResqueScheduler worker to handle scheduling of delayed tasks.
 *
 * @package        ResqueScheduler
 * @author        Chris Boulton <chris@bigcommerce.com>
 * @copyright    (c) 2012 Chris Boulton
 * @license        http://www.opensource.org/licenses/mit-license.php
 */
class CustomWorker implements WorkerInterface
{
    const LOG_NONE    = 0;
    const LOG_NORMAL  = 1;
    const LOG_VERBOSE = 2;
    /**
     * @var LoggerInterface Logging object that impliments the PSR-3 LoggerInterface
     */
    public $logger;
    /**
     * @var int Current log level of this worker.
     */
    public $logLevel = 0;

    /**
     * @var int Interval to sleep for between checking schedules.
     */
    protected $interval = 5;
    /**
     * @var boolean True if this worker is paused.
     */
    private $paused = false;
    /**
     * @var boolean True if on the next iteration, the worker should shutdown.
     */
    private $shutdown = false;

    /**
     * 回调函数
     * @var mixed
     */
    private $handler = null;

    private $name = '';

    private $totalLoop = 0;
    private $totalJob = 0;
    private $busy = false;

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
        $this->logger = new Log();

        $this->hostname = php_uname('n');

        $this->name = (is_array($name) ? \implode(',', $name) : $name);
        $this->id = $this->hostname . ':' . getmypid() . ':custom_worker:' . $this->name;

        $this->logTag = 'CustomWorker:' . $this->name . ':' . getmypid();
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
                    if ($this->handler) {
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
            $this->sleep();
        }
        Event::trigger('beforeStop', $this);
        $this->unregisterWorker();
    }

    /**
     * Sleep for the defined interval.
     */
    protected function sleep()
    {
        $this->log(LogLevel::DEBUG, 'Sleeping for  ' . $this->interval);
        usleep($this->interval * 1000000);
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
        $processTitle = 'resque-scheduler-' . ResqueScheduler::VERSION . ': ' . $status;
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
        $this->logger->log($level, "[" . $this->logTag . "] " . $message, $context);
    }

    /**
     * Perform necessary actions to start a worker.
     */
    private function startup()
    {
        $this->registerSigHandlers();
        Event::trigger('beforeFirstFork', $this);
        $this->registerWorker();
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
        $workerStatusStr = posix_getpid() . "\t" . str_pad(round(memory_get_usage(true) / (1024 * 1024), 2) . "M", 7)
            . " " . str_pad(static::class, WorkerManager::$_maxWorkerTypeLength)
            . " ";
        $workerStatusStr .= str_pad($this->name, WorkerManager::$_maxQueueNameLength)
            . " " .  str_pad($this->totalLoop, 13)
            . " " . str_pad($this->totalJob, 13)
            . " " . str_pad($this->busy ? '[busy]' : '[idle]', 6) . "\n";
        file_put_contents($statisticsFile, $workerStatusStr, FILE_APPEND);
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
