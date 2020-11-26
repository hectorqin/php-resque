<?php

namespace Resque\ThinkPHP\Command;

use Resque\Timer;
use Resque\WorkerManager;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\facade\Config;
use think\facade\Hook;
use think\facade\Log;

class Resque extends Command
{
    const OPERATE_START = 'start';
    const OPERATE_RESTART = 'restart';
    const OPERATE_KILL = 'kill';
    const OPERATE_STATUS = 'status';

    public static $frameworkMainVersion = null;

    /**
     * 配置
     *
     * @return void
     */
    protected function configure()
    {
        $this->setName('resque')
            ->setDescription('Resque worker manager')
            ->addArgument('operate', Argument::OPTIONAL, 'operate', self::OPERATE_STATUS)
            ->addOption('daemonize', 'd', Option::VALUE_OPTIONAL, 'run as daemonize')
            ->addOption('status_timeout', 't', Option::VALUE_OPTIONAL, 'timeout for fetch status');

        if (version_compare(\think\App::VERSION, '5.1.0', '>=') && version_compare(\think\App::VERSION, '6.0.0', '<')) {
            self::$frameworkMainVersion = 5;
        } else {
            self::$frameworkMainVersion = 6;
        }
    }

    /**
     * 获取框架配置
     *
     * @return array
     */
    public static function getFrameworkConfig()
    {
        if (self::$frameworkMainVersion == 5) {
            return Config::get('resque.');
        } else {
            return Config::get('resque');
        }
    }

    /**
     * 执行
     *
     * @param Input $input
     * @param Output $output
     * @return void
     */
    protected function execute(Input $input, Output $output)
    {
        global $argv;

        $config = self::getFrameworkConfig();
        // 设置配置
        $GLOBALS['RESQUE_CONFIG'] = $config;

        \array_shift($argv);

        if ($output->isVeryVerbose()) {
            \putenv('VVERBOSE=1');
        } else if ($output->isVerbose()) {
            \putenv('VERBOSE=1');
        }

        if ($input->hasOption('status_timeout')) {
            \putenv('STATUS_TIMEOUT=' . $input->getOption('status_timeout'));
        }

        try {
            Hook::listen('before_resque_start');
            WorkerManager::run();
        } catch (\Throwable $t){
            Log::record($t->getMessage() . "\n" . $t->getTraceAsString());
            throw $t;
        } catch(\Exception $e) {
            Log::record($e->getMessage() . "\n" . $e->getTraceAsString());
            throw $e;
        }
    }
}