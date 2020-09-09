<?php
namespace Resque;

use Psr\Log\LogLevel;

/**
 * Resque default logger PSR-3 compliant
 *
 * @package        Resque/Stat
 * @author        Chris Boulton <chris@bigcommerce.com>
 * @license        http://www.opensource.org/licenses/mit-license.php
 */
class Log extends \Psr\Log\AbstractLogger
{
    public $verbose;
    public $logLevel;
    public $logFile = null;

    public function __construct($logLevel = [])
    {
        $this->verbose = \in_array(LogLevel::DEBUG, $logLevel);
        $this->logLevel = $logLevel;
    }

    /**
     * Logs with an arbitrary level.
     *
     * @param mixed   $level    PSR-3 log level constant, or equivalent string
     * @param string  $message  Message to log, may contain a { placeholder }
     * @param array   $context  Variables to replace { placeholder }
     * @return null
     */
    public function log($level, $message, array $context = array())
    {
        if (!empty($this->logLevel) && !\in_array($level, $this->logLevel)) {
            return;
        }
        if ($this->logFile) {
            file_put_contents($this->logFile, "[ $level ][ " . date('Y-m-d H:i:s') . " ] " . $this->interpolate($message, $context) . PHP_EOL, FILE_APPEND | LOCK_EX);
            return;
        }
        if ($this->verbose) {
            fwrite(
                STDOUT,
                '[' . $level . '] [' . strftime('%T %Y-%m-%d') . '] ' . $this->interpolate($message, $context) . PHP_EOL
            );
            return;
        }

        fwrite(
            STDOUT,
            '[' . $level . '] ' . $this->interpolate($message, $context) . PHP_EOL
        );
    }

    /**
     * Fill placeholders with the provided context
     * @author Jordi Boggiano j.boggiano@seld.be
     *
     * @param  string  $message  Message to be logged
     * @param  array   $context  Array of variables to use in message
     * @return string
     */
    public function interpolate($message, array $context = array())
    {
        // build a replacement array with braces around the context keys
        $replace = array();
        foreach ($context as $key => $val) {
            $replace['{' . $key . '}'] = $val;
        }

        // interpolate replacement values into the message and return
        return strtr($message, $replace);
    }
}
