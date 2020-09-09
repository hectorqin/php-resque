<?php
namespace Resque;

use Psr\Log\LogLevel;
use Resque\Listener\ListenerInterface;
use Resque\Listener\StatsListener;

class WorkerManager
{
    const STATUS_STARTING = 1;
    const STATUS_RUNNING  = 2;
    const STATUS_SHUTDOWN = 3;
    const VERSION = 1.0;

    public static $_config = array(
        'DAEMONIZE'       => false,
        'REDIS_BACKEND'   => '',
        'REDIS_DATABASE'  => '',
        'INTERVAL'        => 5,
        'WORKER_GROUP'    => array(
            array(
                "type"    => "Worker",
                "queue"   => "deault",
                "procNum" => 1,
            ),
        ),
        'BLOCKING'        => false,
        'APP_INCLUDE'     => '',
        'PREFIX'          => '',
        'PIDFILE'         => './resque.pid',
        'LOG_FILE'        => './resque.log',
        'STATISTICS_FILE' => './resque.status',

        'NO_FORK'         => false,

        'VERBOSE'         => false,
        'VVERBOSE'        => false,
        'LOG_LEVEL'       => [
            LogLevel::EMERGENCY,
            LogLevel::ALERT,
            LogLevel::CRITICAL,
            LogLevel::ERROR,
            LogLevel::WARNING,
            LogLevel::NOTICE,
            // LogLevel::INFO,
        ],

        'LISTENER' => [
            StatsListener::class,
        ],
    );

    public static $logger      = null;
    public static $workerPids  = [];

    /**
     * Status info of current worker process.
     *
     * @var array
     */
    protected static $_globalStatistics = array(
        'startTimestamp'  => 0,
        'workerExitInfo' => array()
    );
    public static $_status = self::STATUS_STARTING;

    public static $managerPid   = null;

    public static $_maxWorkerTypeLength = 25;

    public static $_maxQueueNameLength = 25;

    public static function getEnv($key)
    {
        $lowerKey = \strtolower($key);
        $envValue = \getenv($key) ?: \getenv($lowerKey);
        if ($envValue !== false) {
            return $envValue;
        }
        $config = isset($GLOBALS['RESQUE_CONFIG']) ? $GLOBALS['RESQUE_CONFIG'] : [];
        return isset($config[$key]) ? $config[$key] : (isset($config[$lowerKey]) ? $config[$lowerKey] : null);
    }

    public static function setConf($key, $value)
    {
        static::$_config[$key] = $value;
        return true;
    }

    public static function getConf($key, $default = null)
    {
        return isset(static::$_config[$key]) ? static::$_config[$key] : $default;
    }

    public static function run()
    {
        // 初始化环境变量
        static::init();
        // 解析命令
        static::parseCommand();
        // 尝试以守护进程模式运行
        static::daemonize();
        // 配置环境
        static::initEnv();
        // 初始化所有worker实例
        static::initWorkers();
        //  初始化所有信号处理函数
        static::installSignal();
        // 注册错误处理函数
        static::installErrorHandler();
        // 监控所有子进程（worker进程）
        static::monitorWorkers();
    }

    public static function init()
    {
        foreach (static::$_config as $key => $value) {
            if (!is_null($tmp = static::getEnv($key))) {
                static::setConf($key, $tmp);
            }
        }

        if (!function_exists('pcntl_fork')) {
            static::log("*** Do not support pcntl_fork, falldown to NO_FORK");
            static::setConf('NO_FORK', true);
            static::setConf('DAEMONIZE', false);
        }

        static::$_status = static::STATUS_STARTING;
        static::$_globalStatistics['startTimestamp'] = time();

        Timer::init();
        // print_r(static::$_config);
    }

    public static function parseCommand()
    {
        // 检查运行命令的参数
        global $argv;
        $start_file = $argv[0];
        if (!isset($argv[1])) {
            exit("Usage: php $start_file {start|stop|restart|kill}\n");
        }

        // 命令
        $command = trim($argv[1]);

        // 子命令，目前只支持-d
        $command2 = isset($argv[2]) ? $argv[2] : '';

        // 检查主进程是否在运行
        if (is_file(static::getConf('PIDFILE'))) {
            $master_pid      = @file_get_contents(static::getConf('PIDFILE'));
            $master_is_alive = $master_pid && @posix_kill($master_pid, 0);
        } else {
            $master_is_alive = false;
        }
        if ($master_is_alive) {
            if ($command === 'start') {
                static::log("*** Worker[$start_file] already running");
                exit;
            }
        } elseif ($command !== 'start' && $command !== 'restart') {
            static::log("*** Worker[$start_file] not run");
            exit;
        }

        // 根据命令做相应处理
        switch ($command) {
            case 'kill':
                exec("ps aux | grep resque | grep -v grep | awk '{print $2}' |xargs kill -SIGINT");
                exec("ps aux | grep resque | grep -v grep | awk '{print $2}' |xargs kill -SIGKILL");
                break;
            // 启动 worker
            case 'start':
                if ($command2 === '-d') {
                    static::setConf('DAEMONIZE', true);
                }
                break;
            case 'status':
                $statisticsFile = static::getConf('STATISTICS_FILE');
                if (is_file($statisticsFile)) {
                    @unlink($statisticsFile);
                }
                $master_pid && posix_kill($master_pid, SIGUSR2);
                while (!is_file($statisticsFile)) {
                    sleep(1);
                }
                static::log(file_get_contents($statisticsFile));
                exit(0);
                break;
            // 重启 worker
            case 'restart':
            // 停止 workeran
            case 'stop':
                static::log("*** Worker[$start_file] is stoping ...");
                // 想主进程发送SIGINT信号，主进程会向所有子进程发送SIGINT信号
                $master_pid && posix_kill($master_pid, SIGINT);
                $start_time = time();
                while (1) {
                    // 检查主进程是否存活
                    $master_is_alive = $master_pid && posix_kill($master_pid, 0);
                    if ($master_is_alive) {
                        static::log("*** Worker[$start_file] is waiting for job done ...");
                        usleep(1000000);
                        continue;
                    }
                    static::log("*** Worker[$start_file] stop success");
                    if ($command === 'stop') {
                        exit(0);
                    }
                    // 是restart命令
                    // -d 说明是以守护进程的方式启动
                    if ($command2 === '-d') {
                        static::setConf('DAEMONIZE', true);
                    }
                    break;
                }
                break;
            // 未知命令
            default:
                exit("Usage: php $start_file {start|stop|restart|kill}\n");
        }
    }

    /**
     * 尝试以守护进程的方式运行
     * @throws Exception
     */
    public static function daemonize()
    {
        if (static::getConf('DAEMONIZE')) {
            $pid = pcntl_fork();
            if (-1 === $pid) {
                throw new Exception('fork fail');
            } elseif ($pid > 0) {
                exit(0); //让终端启动的进程退出
            }
            umask(0);
            // 建立一个有别于终端的新session以脱离终端
            if (-1 === posix_setsid()) {
                throw new Exception("setsid fail");
            }
            // fork again avoid SVR4 system regain the control of terminal
            $pid = pcntl_fork();
            if (-1 === $pid) {
                throw new Exception("fork fail");
            } elseif (0 !== $pid) {
                exit(0);
            }
        }
        if (!static::getConf('NO_FORK') && false === @file_put_contents(static::getConf('PIDFILE'), \getmypid())) { //主进程保存pid文件
            throw new Exception('can not save pid to ' . static::getConf('PIDFILE'));
        }
        static::$managerPid = \getmypid();
        chmod(static::getConf('PIDFILE'), 0777);
        //主程序终止对 STDOUT STDERR 的占用
        if (static::getConf('DAEMONIZE')) {
            //关闭标准I/O流
            @fclose(STDIN);
            @fclose(STDOUT);
            @fclose(STDERR);
        }
    }

    public static function initEnv()
    {
        $APP_INCLUDE = static::getConf('APP_INCLUDE');
        foreach (glob($APP_INCLUDE) as $file) {
            if (file_exists($file)) {
                require_once $file;
            }
        }

        if (!empty(static::getConf('REDIS_BACKEND'))) {
            Resque::setBackend(static::getConf('REDIS_BACKEND'), static::getConf('REDIS_DATABASE'));
        }

        $logLevel = static::getConf('LOG_LEVEL');

        if (static::getConf('VVERBOSE')) {
            $logLevel[] = LogLevel::INFO;
            $logLevel[] = LogLevel::DEBUG;
        } else if (static::getConf('VERBOSE')) {
            $logLevel[] = LogLevel::INFO;
        }
        $logger = new Log($logLevel);
        if (static::getConf('DAEMONIZE')) {
            $logger->logFile = static::getConf('LOG_FILE');
        }
        static::$logger = $logger;

        if (static::getConf('PREFIX')) {
            static::log("*** Prefix set to " . static::getConf('PREFIX'));
            Redis::prefix(static::getConf('PREFIX'));
        }
        unset($APP_INCLUDE, $logLevel, $logger);
        static::setProcessTitle("resque-1.2: resque worker manager process");
    }

    public static function initWorkers()
    {
        $WORKER_GROUP = static::getConf('WORKER_GROUP');

        if (empty($WORKER_GROUP) || count($WORKER_GROUP) == 0) {
            throw new Exception('please set the WORKER_GROUP options');
        }

        if (static::getConf('NO_FORK') && count($WORKER_GROUP) > 1) {
            throw new Exception('only support one WORKER_GROUP');
        }

        // 注册监听事件
        Event::clearListeners();
        $eventListers = static::getConf('LISTENER');
        if ($eventListers) {
            foreach ($eventListers as $event => $listener) {
                if (is_string($listener) && \class_exists($listener)) {
                    $listener = new $listener();
                    if ($listener instanceof ListenerInterface) {
                        $listener->init();
                    }
                } else if (\is_callable($listener)) {
                    Event::listen($event, $listener);
                }
            }
        }

        foreach ($WORKER_GROUP as $groupId => $worker) {
            if (isset($worker['queue'])) {
                static::log("*** Init workerGroup {$groupId}, procNum {$worker['procNum']}, working on {$worker['queue']}");
            } else {
                $worker['queue'] = '';
                static::log("*** Init workerGroup {$groupId}, procNum {$worker['procNum']}");
            }
            for ($i = 0; $i < (int) $worker['procNum']; ++$i) {
                $interval = isset($worker['interval']) ? $worker['interval'] : static::getConf('INTERVAL');
                $blocking = isset($worker['blocking']) ? $worker['blocking'] : static::getConf('BLOCKING');
                static::initOneWorker($worker['queue'], $worker['type'], [
                    'interval' => $interval,
                    'blocking' => $blocking,
                    'groupID'  => $groupId,
                ] + $worker);
            }
        }
    }

    /**
     * 实例化一个worker
     * @param  string $queue 队列名称
     * @param  string $type worker类型
     * @return null
     */
    public static function initOneWorker($queue, $type = 'Worker', $option)
    {
        if (\strpos($type, '\\') === false) {
            $type = "\\Resque\\$type";
        }
        if (!\class_exists($type)) {
            static::stopAll();
            static::log("*** Worker class [$type] not found");
            return;
        }
        $queueArr = [];
        if ($queue) {
            static::log("*** Init one worker[$type], working on " . $queue);
            $queueArr = explode(',', $queue);
        } else {
            static::log("*** Init one worker[$type]");
        }
        if (static::getConf('NO_FORK')) {
            $worker = new $type($queueArr);
            if (!$worker instanceof \Resque\WorkerInterface) {
                static::stopAll();
                static::log("*** Worker class [$type] not implements \\Resque\\WorkerInterface");
                return;
            }
            if (\method_exists($worker, 'setOption')) {
                $worker->setOption($option);
            }
            $worker->setLogger(static::$logger);
            static::log('*** Starting worker ' . $worker);
            if ($worker instanceof \Resque\Worker) {
                $worker->work($option['interval'], $option['blocking']);
            } else {
                $worker->work($option['interval']);
            }
            static::exitNow();
        }
        $pid = pcntl_fork();
        if ($pid == -1) {
            static::log("*** Could not fork worker");
            exit();
        } else if ($pid > 0) { // master, record the child pid
            static::$workerPids[$pid] = array(
                "queue"    => $queue,
                "type"     => $type,
                "option"   => $option,
            );
        } else if ($pid === 0) { // Child, start the worker 子进程开启worker
            $worker = new $type($queueArr);
            if (!$worker instanceof \Resque\WorkerInterface) {
                static::stopAll();
                static::log("*** Worker class [$type] not implements \\Resque\\WorkerInterface");
                return;
            }
            if (\method_exists($worker, 'setOption')) {
                $worker->setOption($option);
            }
            $worker->setLogger(static::$logger);
            static::log('*** Starting worker ' . $worker);
            static::installErrorHandler();
            if ($worker instanceof \Resque\Worker) {
                $worker->work($option['interval'], $option['blocking']);
            } else {
                $worker->work($option['interval']);
            }
            exit(255);
        }
    }

    /**
     * 安装信号处理函数
     * @return void
     */
    protected static function installSignal()
    {
        static::log("*** Install signal handle pid " . getmypid());
        // stop
        pcntl_signal(SIGINT, array(static::class, 'signalHandler'), false);
        pcntl_signal(SIGTERM, array(static::class, 'signalHandler'), false);
        pcntl_signal(SIGQUIT, array(static::class, 'signalHandler'), false);
        // ignore
        pcntl_signal(SIGPIPE, SIG_IGN, false);
        pcntl_signal(SIGUSR2, array(static::class, 'signalHandler'), false);
    }

    /**
     * 信号处理函数
     * @param int $signal
     */
    public static function signalHandler($signal)
    {
        static::log("*** Get signals $signal pid " . getmypid());
        switch ($signal) {
            // stop
            case SIGINT:
            case SIGTERM:
            case SIGQUIT:
                static::stopAll();
                break;
            case SIGUSR2:
                static::writeStatisticsToStatusFile();
                // $content = var_export(static::$workerPids, true);
                // $content .= "\n\n" . var_export(static::$_config, true);
                // file_put_contents(static::getConf('STATISTICS_FILE'), $content);
                break;
        }
    }

    public static function writeStatisticsToStatusFile()
    {
        $statisticsFile = static::getConf('STATISTICS_FILE');
        $workerGroup = static::getConf('WORKER_GROUP');

        // file_put_contents($statisticsFile, json_encode(self::$workerPids)."\n", FILE_APPEND);

        $loadavg = function_exists('sys_getloadavg') ? array_map('round', sys_getloadavg(), array(2)) : array('-', '-', '-');
        file_put_contents($statisticsFile,
            "----------------------------------------------GLOBAL STATUS----------------------------------------------------\n", FILE_APPEND);
        file_put_contents($statisticsFile,
            'PHP-Resque version:' . static::VERSION . "          PHP version:" . PHP_VERSION . "\n", FILE_APPEND);
        file_put_contents($statisticsFile, 'start time:' . date('Y-m-d H:i:s',
                static::$_globalStatistics['startTimestamp']) . '   run ' . floor((time() - static::$_globalStatistics['startTimestamp']) / (24 * 60 * 60)) . ' days ' . floor(((time() - static::$_globalStatistics['startTimestamp']) % (24 * 60 * 60)) / (60 * 60)) . " hours   \n",
            FILE_APPEND);
        $load_str = 'load average: ' . implode(", ", $loadavg);
        file_put_contents($statisticsFile, str_pad($load_str, 33) . "\n", FILE_APPEND);
        file_put_contents($statisticsFile,
            count($workerGroup) . ' workers       ' . count(static::$workerPids) . " processes\n",
            FILE_APPEND);
        file_put_contents($statisticsFile,
            str_pad('worker_type', static::$_maxWorkerTypeLength) . " exit_status      exit_count\n", FILE_APPEND);
        foreach ($workerGroup as $groupID => $workerInfo) {
            if (isset(static::$_globalStatistics['workerExitInfo'][$groupID])) {
                foreach (static::$_globalStatistics['workerExitInfo'][$groupID] as $worker_exit_status => $worker_exit_count) {
                    file_put_contents($statisticsFile,
                        str_pad($workerInfo['type'], static::$_maxWorkerTypeLength) . " " . str_pad($worker_exit_status,
                            16) . " $worker_exit_count\n", FILE_APPEND);
                }
            } else {
                file_put_contents($statisticsFile,
                    str_pad($workerInfo['type'], static::$_maxWorkerTypeLength) . " " . str_pad(0, 16) . " 0\n",
                    FILE_APPEND);
            }
        }
        file_put_contents($statisticsFile,
            "----------------------------------------------PROCESS STATUS---------------------------------------------------\n",
            FILE_APPEND);
        file_put_contents($statisticsFile,
            "pid\tmemory  " . str_pad('worker_type',
                static::$_maxWorkerTypeLength) . " " . str_pad('queue', static::$_maxQueueNameLength) . " " . str_pad('total_loops', 13) . " ". str_pad('total_jobs', 13) . " status\n", FILE_APPEND);

        chmod($statisticsFile, 0722);

        foreach (static::$workerPids as $workerPid => $workerInfo) {
            posix_kill($workerPid, SIGUSR2);
        }
    }

    /**
     * 执行关闭流程
     * @return void
     */
    public static function stopAll()
    {
        static::$_status = static::STATUS_SHUTDOWN;
        static::log("*** Workers Stopping ...");
        // 向所有子进程发送SIGINT信号，表明关闭服务
        foreach (static::$workerPids as $workerPid => $workerInfo) {
            static::log("*** Stopping $workerPid ...");
            posix_kill($workerPid, SIGQUIT);
        }
    }

    /**
     * 设置当前进程的名称，在ps aux命令中有用
     * 注意 需要php>=5.5或者安装了protitle扩展
     * @param string $title
     * @return void
     */
    public static function setProcessTitle($title)
    {
        // >=php 5.5
        if (function_exists('cli_set_process_title')) {
            @cli_set_process_title($title);
        }
        // 需要扩展
        elseif (extension_loaded('proctitle') && function_exists('setproctitle')) {
            @setproctitle($title);
        }
    }

    /**
     * 监控所有子进程的退出事件及退出码
     * @return void
     */
    public static function monitorWorkers()
    {
        static::$_status = static::STATUS_RUNNING;
        while (1) {
            // 如果有信号到来，尝试触发信号处理函数
            pcntl_signal_dispatch();
            // 挂起进程，直到有子进程退出或者被信号打断
            $status = 0;
            $pid    = pcntl_wait($status, WUNTRACED);
            // 如果有信号到来，尝试触发信号处理函数
            pcntl_signal_dispatch();
            // 有子进程退出
            if ($pid > 0) {
                if (!isset(static::$workerPids[$pid])) {
                    static::log("*** Worker $pid has been stopped with status $status, but can not find this worker belongs to which workergroup, can not restarting...");
                } else {
                    $workerInfo = static::$workerPids[$pid];
                    unset(static::$workerPids[$pid]);
                }
                // 如果不是关闭状态，则补充新的进程
                if (static::$_status !== static::STATUS_SHUTDOWN) {
                    if ($workerInfo) {
                        if ($status) {
                            $groupID = $workerInfo['option']['groupID'];
                            if (!isset(static::$_globalStatistics['workerExitInfo'][$groupID][$status])) {
                                static::$_globalStatistics['workerExitInfo'][$groupID][$status] = 0;
                            }
                            static::$_globalStatistics['workerExitInfo'][$groupID][$status]++;
                        }
                        static::log("*** Worker $pid has been stopped with status $status, type【{$workerInfo['type']}】, queue【{$workerInfo['queue']}】, restarting...");
                        static::initOneWorker($workerInfo['queue'], $workerInfo['type'], $workerInfo['option']);
                    }
                } else {
                    static::log("*** Worker $pid has been stopped");
                    // 如果是关闭状态，并且所有进程退出完毕，则主进程退出
                    if (count(static::$workerPids) == 0) {
                        static::exitNow();
                    }
                }
            } else {
                // 如果是关闭状态，并且所有进程退出完毕，则主进程退出
                if (static::$_status === static::STATUS_SHUTDOWN && count(static::$workerPids) == 0) {
                    static::exitNow();
                }
            }
        }
    }

    /**
     * 退出当前进程
     * @return void
     */
    public static function exitNow()
    {
        if (static::getConf('PIDFILE')) {
            @unlink(static::getConf('PIDFILE'));
        }
        if (static::getConf('STATISTICS_FILE')) {
            @unlink(static::getConf('STATISTICS_FILE'));
        }
        static::log("*** Workers has been stopped");
        static::log("*** WorkerManager exit");
        exit(0);
    }

    /**
     * 对象转字符串
     *
     * @param mixed $value
     * @return string|mixed
     * @throws InvalidArgumentException
     */
    public static function convertToString($value, $echo = false)
    {
        if ($value instanceof \Throwable) {
            return sprintf("%s in %s(%s)\n%s", $value->getMessage(), $value->getFile(), $value->getLine(), $value->getTraceAsString());
        } else if (is_object($value) && \method_exists($value, 'getAttributes')) {
            return $echo ? \var_export($value->getAttributes(), true) : \json_encode($value->getAttributes(), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        } else if (!\is_string($value)) {
            if (\method_exists($value, '__toString')) {
                return $value->__toString();
            } else {
                return $echo ? \var_export($value, true) : \json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
            }
        } else {
            return $value;
        }
    }

    public static function log($msg, $extra = array(), $level = 'info')
    {
        if (static::$logger) {
            static::$logger->log($level, '[WorkerManager:' . static::$managerPid . '] ' . static::convertToString($msg), $extra);
        } else if (!function_exists('posix_isatty') || (get_resource_type(STDOUT) == 'stream' && posix_isatty(STDOUT))) {
            echo static::convertToString($msg), PHP_EOL;
        }
    }

    public static function fatalError()
    {
        $error = error_get_last();
        if ($error !== null) {
            error_log("PID: " . \getmypid() . "  " .  static::convertToString($error) . "\n", 3, __DIR__ . "/resque-error.log");
        }
    }

    public static function installErrorHandler()
    {
        register_shutdown_function("\\Resque\\WorkerManager::fatalError");
    }
}
