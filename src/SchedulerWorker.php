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
class SchedulerWorker implements WorkerInterface
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
    private $paused = false;
    /**
     * @var boolean True if on the next iteration, the worker should shutdown.
     */
	private $shutdown = false;

    private $totalLoop = 0;
    private $totalJob = 0;
    private $busy = false;

    private $workerPid = 0;

    private $sleepUntil = 0;

    public $workerGroupCount = 1;
    public $workerIndex = 0;

    /**
     * Instantiate a new worker, given a list of queues that it should be working
     * on. The list of queues should be supplied in the priority that they should
     * be checked for jobs (first come, first served)
     *
     * Passing a single '*' allows the worker to work on all queues in alphabetical
     * order. You can easily add new queues dynamically and have them worked on using
     * this method.
     *
     * @param string $queues String with a single queue name.
     */
    public function __construct()
    {
        $this->hostname = php_uname('n');

        $this->id = $this->hostname . ':' . getmypid() . ':' . ResqueScheduler::$delayQueueName;

        $this->logTag = 'SchedulerWorker:' . ResqueScheduler::$delayQueueName . ':' . \getmypid();

        $this->workerPid = \getmypid();
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
                $this->updateProcLine('Waiting for Delayed Items');
                $this->handleDelayedItems();
            }
            $this->log(LogLevel::DEBUG, 'Sleeping for  ' . $this->interval);
            $this->sleep();
        }
        Event::trigger('onWorkerStop', $this);
        $this->unregisterWorker();
    }

    /**
     * Handle delayed items for the next scheduled timestamp.
     *
     * Searches for any items that are due to be scheduled in Resque
     * and adds them to the appropriate job queue in Resque.
     *
     * @param DateTime|int $timestamp Search for any items up to this timestamp to schedule.
     */
    public function handleDelayedItems($timestamp = null)
    {
        $this->busy = true;
        while (($oldestJobTimestamp = ResqueScheduler::nextDelayedTimestamp($timestamp)) !== false) {
            $this->updateProcLine('Processing Delayed Items');
            $this->enqueueDelayedItemsForTimestamp($oldestJobTimestamp);
        }
        $this->busy = false;
    }

    /**
     * Schedule all of the delayed jobs for a given timestamp.
     *
     * Searches for all items for a given timestamp, pulls them off the list of
     * delayed jobs and pushes them across to Resque.
     *
     * @param DateTime|int $timestamp Search for any items up to this timestamp to schedule.
     */
    public function enqueueDelayedItemsForTimestamp($timestamp)
    {
        $item = null;
        while ($item = ResqueScheduler::nextItemForTimestamp($timestamp)) {
            $this->totalJob++;
            $this->log(LogLevel::INFO, 'queueing ' . $item['class'] . ' in ' . $item['queue'] . ' [delayed]');

            Event::trigger('beforeDelayedEnqueue', array(
                'queue' => $item['queue'],
                'class' => $item['class'],
                'args'  => $item['args'],
            ));

            $payload = array_merge(array($item['queue'], $item['class']), $item['args'], array(true, isset($item['id']) ? $item['id'] : null));
            call_user_func_array('\\Resque\\Resque::enqueue', $payload);
        }
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

    public function writeStatistics()
    {
        $statisticsFile = WorkerManager::getConf('STATISTICS_FILE');

        file_put_contents($statisticsFile,
            str_pad(posix_getpid(), 10) .
            str_pad(round(memory_get_usage(true) / (1024 * 1024), 2) . "M", 8) .
            str_pad(static::class, WorkerManager::$_maxWorkerTypeLength) .
            str_pad(ResqueScheduler::$delayQueueName, WorkerManager::$_maxQueueNameLength) .
            str_pad(Timer::count(), 8) .
            str_pad($this->totalLoop, 13) .
            str_pad($this->totalJob, 13) .
            str_pad($this->busy ? '[busy]' : '[idle]', 6) . "\n", FILE_APPEND);
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
     * set options
     * @param array $options
     * @return $this
     */
    public function setOption($options)
    {
        if (isset($options['workerGroupCount'])) {
            $this->workerGroupCount = $options['workerGroupCount'];
        }
        if (isset($options['workerIndex'])) {
            $this->workerIndex = $options['workerIndex'];
        }
        return $this;
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
