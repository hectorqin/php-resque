<?php

namespace Resque\Crontab;

use Carbon\Carbon;
use Resque\Exception;
use Resque\Job\SimpleJob;
use Resque\WorkerManager;

class Crontab
{
    /**
     * @var null|string
     */
    protected $name;

    /**
     * @var string
     */
    protected $type = 'callback';

    /**
     * @var null|string
     */
    protected $rule;

    /**
     * @var bool
     */
    protected $singleton = false;

    /**
     * @var string
     */
    protected $mutexPool = 'default';

    /**
     * @var int
     */
    protected $mutexExpires = 3600;

    /**
     * @var bool
     */
    protected $onOneServer = false;

    /**
     * @var mixed
     */
    protected $callback;

    /**
     * @var array
     */
    protected $params;

    /**
     * @var null|string
     */
    protected $memo;

    /**
     * @var null|int|\Carbon\Carbon
     */
    protected $executeTime;

    /**
     *
     * @param array $config crontab 配置
     * @return void
     * @throws Exception
     */
    public function __construct($config)
    {
        if (!isset($config['name']) || !$config['name']) {
            throw new Exception('Crontab must have a name');
        }
        if (!isset($config['rule']) || !$config['rule']) {
            throw new Exception('Crontab must have time rule');
        }
        if (!isset($config['handler']) || !$config['handler']) {
            throw new Exception('Crontab must have handler');
        }
        try {
            \serialize($config['handler']);
        } catch (\Throwable $th) {
            throw new Exception('Crontab handler must can be serialized');
        }

        if (!SimpleJob::getCallable($config['handler'])) {
            throw new Exception('Crontab handler must can be callable');
        }
        $this->setName($config['name']);
        $this->setRule($config['rule']);
        $this->setCallback($config['handler']);

        foreach ($config as $key => $value) {
            if (\property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): Crontab
    {
        $this->name = $name;
        return $this;
    }

    public function getRule(): ?string
    {
        return $this->rule;
    }

    public function setRule(?string $rule): Crontab
    {
        $this->rule = $rule;
        return $this;
    }

    public function isSingleton(): bool
    {
        return $this->singleton;
    }

    public function setSingleton(bool $singleton): Crontab
    {
        $this->singleton = $singleton;
        return $this;
    }

    public function getMutexPool(): string
    {
        return $this->mutexPool;
    }

    public function setMutexPool(string $mutexPool): Crontab
    {
        $this->mutexPool = $mutexPool;
        return $this;
    }

    public function getMutexExpires(): int
    {
        return $this->mutexExpires;
    }

    public function setMutexExpires(int $mutexExpires): Crontab
    {
        $this->mutexExpires = $mutexExpires;
        return $this;
    }

    public function isOnOneServer(): bool
    {
        return $this->onOneServer;
    }

    public function setOnOneServer(bool $onOneServer): Crontab
    {
        $this->onOneServer = $onOneServer;
        return $this;
    }

    public function getCallback()
    {
        return $this->callback;
    }

    public function setCallback($callback): Crontab
    {
        $this->callback = $callback;
        return $this;
    }

    public function getParams(): array
    {
        return $this->params ? (array)$this->params : [];
    }

    public function setParams($params): Crontab
    {
        $this->params = $params;
        return $this;
    }

    public function getMemo(): ?string
    {
        return $this->memo;
    }

    public function setMemo(?string $memo): Crontab
    {
        $this->memo = $memo;
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): Crontab
    {
        $this->type = $type;
        return $this;
    }

    public function getExecuteTime()
    {
        return $this->executeTime;
    }

    public function setExecuteTime($executeTime): Crontab
    {
        $this->executeTime = $executeTime;
        return $this;
    }
}
