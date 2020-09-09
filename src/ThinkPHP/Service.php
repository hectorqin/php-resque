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
    }
}
