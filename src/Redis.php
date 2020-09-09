<?php
namespace Resque;

use Resque\Exception\RedisException;

/**
 * Wrap Credis to add namespace support and various helper methods.
 *
 * @package        Resque/Redis
 * @author        Chris Boulton <chris@bigcommerce.com>
 * @license        http://www.opensource.org/licenses/mit-license.php
 */
class Redis
{
    /**
     * Redis namespace
     * @var string
     */
    private static $defaultNamespace = 'resque:';

    /**
     * A default host to connect to
     */
    const DEFAULT_HOST = 'localhost';

    /**
     * The default Redis port
     */
    const DEFAULT_PORT = 6379;

    /**
     * The default Redis Database number
     */
    const DEFAULT_DATABASE = 0;

    /**
     * @var array List of all commands in Redis that supply a key as their
     *    first argument. Used to prefix keys with the Resque namespace.
     */
    private $keyCommands = array(
        'exists',
        'del',
        'type',
        'keys',
        'expire',
        'ttl',
        'move',
        'set',
        'setex',
        'get',
        'getset',
        'setnx',
        'incr',
        'incrby',
        'decr',
        'decrby',
        'rpush',
        'lpush',
        'llen',
        'lrange',
        'ltrim',
        'lindex',
        'lset',
        'lrem',
        'lpop',
        'blpop',
        'rpop',
        'sadd',
        'srem',
        'spop',
        'scard',
        'sismember',
        'smembers',
        'srandmember',
        'zadd',
        'zrem',
        'zrange',
        'zrevrange',
        'zrangebyscore',
        'zcard',
        'zscore',
        'zremrangebyscore',
        'sort',
        'rename',
        'rpoplpush',
    );
    // sinterstore
    // sunion
    // sunionstore
    // sdiff
    // sdiffstore
    // sinter
    // smove
    // mget
    // msetnx
    // mset
    // renamenx

    private $driver = null;

    /**
     * Set Redis namespace (prefix) default: resque
     * @param string $namespace
     */
    public static function prefix($namespace)
    {
        if (substr($namespace, -1) !== ':' && $namespace != '') {
            $namespace .= ':';
        }
        self::$defaultNamespace = $namespace;
    }

    /**
     * @param string|array $server A DSN or array
     * @param int $database A database number to select. However, if we find a valid database number in the DSN the
     *                      DSN-supplied value will be used instead and this parameter is ignored.
     * @param object $client Optional Credis_Cluster or Credis_Client instance instantiated by you
     */
    public function __construct($server, $database = null, $client = null)
    {
        try {
            if (is_object($client)) {
                $this->driver = $client;
            } else if (\is_array($server)) {
                $config = $server;
            } else if (\is_string($server)) {
                list($host, $port, $database, $user, $password, $options) = self::parseDsn($server);
                // $user is not used, only $password
                $config = \compact([
                    'host', 'port', 'database', 'password',
                ]) + $options;
            } else {
                throw new \Exception('redis config is required');
            }

            if ($this->driver) {
                return;
            }

            if (extension_loaded('redis')) {
                $this->driver = new \Redis;

                // 建立连接
                $this->driver->pconnect($config['host'], $config['port'], isset($config['timeout']) ? $config['timeout'] : 0);

                if (isset($config['password']) && '' != $config['password']) {
                    $this->driver->auth($config['password']);
                }

                if (isset($config['database']) && 0 != $config['database']) {
                    $this->driver->select($config['database']);
                }
            } elseif (class_exists('\Predis\Client')) {
                $params = [];
                foreach ($config as $key => $val) {
                    if (in_array($key, ['aggregate', 'cluster', 'connections', 'exceptions', 'prefix', 'profile', 'replication'])) {
                        $params[$key] = $val;
                        unset($config[$key]);
                    }
                }
                $this->handler = new \Predis\Client($config, $params);
            } else {
                throw new \Exception('not support: redis');
            }
        } catch (\Exception $e) {
            throw new RedisException('Error communicating with Redis: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Parse a DSN string, which can have one of the following formats:
     *
     * - host:port
     * - redis://user:pass@host:port/db?option1=val1&option2=val2
     * - tcp://user:pass@host:port/db?option1=val1&option2=val2
     * - unix:///path/to/redis.sock
     *
     * Note: the 'user' part of the DSN is not used.
     *
     * @param string $dsn A DSN string
     * @return array An array of DSN compotnents, with 'false' values for any unknown components. e.g.
     *               [host, port, db, user, pass, options]
     */
    public static function parseDsn($dsn)
    {
        if ($dsn == '') {
            // Use a sensible default for an empty DNS string
            $dsn = 'redis://' . self::DEFAULT_HOST;
        }
        if (substr($dsn, 0, 7) === 'unix://') {
            return array(
                $dsn,
                null,
                false,
                null,
                null,
                null,
            );
        }
        $parts = parse_url($dsn);

        // Check the URI scheme
        $validSchemes = array('redis', 'tcp');
        if (isset($parts['scheme']) && !in_array($parts['scheme'], $validSchemes)) {
            throw new \InvalidArgumentException("Invalid DSN. Supported schemes are " . implode(', ', $validSchemes));
        }

        // Allow simple 'hostname' format, which `parse_url` treats as a path, not host.
        if (!isset($parts['host']) && isset($parts['path'])) {
            $parts['host'] = $parts['path'];
            unset($parts['path']);
        }

        // Extract the port number as an integer
        $port = isset($parts['port']) ? intval($parts['port']) : self::DEFAULT_PORT;

        // Get the database from the 'path' part of the URI
        $database = false;
        if (isset($parts['path'])) {
            // Strip non-digit chars from path
            $database = intval(preg_replace('/[^0-9]/', '', $parts['path']));
        }

        // Extract any 'user' and 'pass' values
        $user = isset($parts['user']) ? $parts['user'] : false;
        $pass = isset($parts['pass']) ? $parts['pass'] : false;

        // Convert the query string into an associative array
        $options = array();
        if (isset($parts['query'])) {
            // Parse the query string into an array
            parse_str($parts['query'], $options);
        }

        return array(
            $parts['host'],
            $port,
            $database,
            $user,
            $pass,
            $options,
        );
    }

    /**
     * Magic method to handle all function requests and prefix key based
     * operations with the {self::$defaultNamespace} key prefix.
     *
     * @param string $name The name of the method called.
     * @param array $args Array of supplied arguments to the method.
     * @return mixed Return value from Resident::call() based on the command.
     */
    public function __call($name, $args)
    {
        if (in_array($name, $this->keyCommands)) {
            if (is_array($args[0])) {
                foreach ($args[0] as $i => $v) {
                    $args[0][$i] = self::$defaultNamespace . $v;
                }
            } else {
                $args[0] = self::$defaultNamespace . $args[0];
            }
        }
        try {
            return $this->driver->{$name}(...$args);
        } catch (\Exception $e) {
            throw new RedisException('Error communicating with Redis: ' . $e->getMessage(), 0, $e);
        }
    }

    public static function getPrefix()
    {
        return self::$defaultNamespace;
    }

    public static function removePrefix($string)
    {
        $prefix = self::getPrefix();

        if (substr($string, 0, strlen($prefix)) == $prefix) {
            $string = substr($string, strlen($prefix), strlen($string));
        }
        return $string;
    }
}
