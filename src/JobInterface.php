<?php
namespace Resque;

interface JobInterface
{
    /**
     * @return bool
     */
    public function perform();
}
