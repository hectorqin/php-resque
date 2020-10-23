<?php
namespace Resque\ThinkPHP\Concern;

use Resque\WorkerManager;

trait Controller
{
    public function resque()
    {
        if (PHP_SAPI != 'cli') {
            exit('非法访问');
        }
        $_SESSION = [];

        $config = [];
        if (\defined('THINK_VERSION') && \version_compare(\THINK_VERSION, '3.2', '>=') && \function_exists('C')) {
            $config = \C('RESQUE_CONFIG', []);
        }

        global $argv;

        // 设置配置
        $GLOBALS['RESQUE_CONFIG'] = $config;

        \array_shift($argv);

        $options = \getopt('v', ['vv', 'vvv']);
        if (isset($options['vv']) || isset($options['vvv'])) {
            \putenv('VVERBOSE=1');
        } else if (isset($options['v'])) {
            \putenv('VERBOSE=1');
        }

        try {
            WorkerManager::run();
        } catch (\Throwable $t){
            if (\class_exists('\\Think\\Log')) {
                \Think\Log::record($t->getMessage() . "\n" . $t->getTraceAsString());
            }
            throw $t;
        } catch(\Exception $e) {
            if (\class_exists('\\Think\\Log')) {
                \Think\Log::record($e->getMessage() . "\n" . $e->getTraceAsString());
            }
            throw $e;
        }
    }
}
