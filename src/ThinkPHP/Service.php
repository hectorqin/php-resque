<?php
namespace Resque\ThinkPHP;

use think\Service as BaseService;

class Service extends BaseService
{
    public function register()
    {
        $this->commands([
            'resque' => Command\Resque::class,
        ]);

        $redisConfig = \think\facade\Config::get('resque.redis_backend');
        if ($redisConfig) {
            \Resque\Resque::setBackend($redisConfig, isset($redisConfig['database']) ? $redisConfig['database'] : 0);
        }
    }
}
