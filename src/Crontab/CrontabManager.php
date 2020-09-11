<?php

namespace Resque\Crontab;

class CrontabManager
{
    /**
     * @var Crontab[]
     */
    protected $crontabs = [];

    /**
     * @var Parser
     */
    protected $parser;

    public static $instance = null;

    public function __construct(Parser $parser)
    {
        $this->parser = $parser;
    }

    /**
     * 获取实例
     * @return static
     */
    public static function instance()
    {
        if (!static::$instance) {
            static::$instance = new static(new Parser());
        }
        return static::$instance;
    }

    public function register(Crontab $crontab): bool
    {
        if (! $this->isValidCrontab($crontab)) {
            return false;
        }
        $this->crontabs[$crontab->getName()] = $crontab;
        return true;
    }

    public function parse(): array
    {
        $result = [];
        $crontabs = $this->getCrontabs();
        // $last = time();
        // 取整
        $last = strtotime(date("Y-m-d H:i:0"));
        if (date("s") >= 30) {
            // 如果延迟了30秒，那就取下一周期
            $last += 60;
        }
        foreach ($crontabs ?? [] as $key => $crontab) {
            if (! $crontab instanceof Crontab) {
                unset($this->crontabs[$key]);
                continue;
            }
            $time = $this->parser->parse($crontab->getRule(), $last);
            if ($time) {
                foreach ($time as $t) {
                    $result[] = clone $crontab->setExecuteTime($t);
                }
            }
        }
        return $result;
    }

    public function getCrontabs(): array
    {
        return $this->crontabs;
    }

    private function isValidCrontab(Crontab $crontab): bool
    {
        return $crontab->getName() && $crontab->getRule() && $crontab->getCallback() && $this->parser->isValid($crontab->getRule());
    }
}
