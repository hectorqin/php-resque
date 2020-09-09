<?php
namespace Resque\Tests;

use Resque\Resque;

/**
 * Resque test case class. Contains setup and teardown methods.
 *
 * @package        Resque/Tests
 * @author        Chris Boulton <chris@bigcommerce.com>
 * @license        http://www.opensource.org/licenses/mit-license.php
 */
class TestCase extends \PHPUnit_Framework_PHPUnitTestCase
{
    protected $resque;
    protected $redis;

    public static function setUpBeforeClass()
    {
        date_default_timezone_set('UTC');
    }

    public function setUp()
    {
        $config = file_get_contents(REDIS_CONF);
        preg_match('#^\s*port\s+([0-9]+)#m', $config, $matches);
        $this->redis = new \Redis('localhost', $matches[1]);

        Resque::setBackend('redis://localhost:' . $matches[1]);

        // Flush redis
        $this->redis->flushAll();
    }
}
