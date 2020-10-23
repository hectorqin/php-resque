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
            $config = \C('RESQUE_CONFIG', null, []);
        }

        global $argv;

        // 设置配置
        $GLOBALS['RESQUE_CONFIG'] = $config;

        \array_shift($argv);

        if (in_array('-vv', $argv) || in_array('-vvv', $argv)) {
            \putenv('VVERBOSE=1');
        } else if (in_array('-v', $argv)) {
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
