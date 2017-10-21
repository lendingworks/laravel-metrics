<?php

namespace STS\EventMetrics;

use InfluxDB\Client;
use InfluxDB\Database;
use InfluxDB\Driver\UDP;
use InfluxDB\Point;

/**
 * Class InfluxDB
 * @package STS\EventMetrics
 */
class InfluxDB
{
    /**
     * @var string
     */
    protected $username;
    /**
     * @var string
     */
    protected $password;
    /**
     * @var string
     */
    protected $host;
    /**
     * @var string
     */
    protected $database;
    /**
     * @var int
     */
    protected $tcpPort;
    /**
     * @var int
     */
    protected $udpPort;
    /**
     * @var array
     */
    protected $points = [];
    /**
     * @var Database
     */
    protected $tcpConnection;
    /**
     * @var Database
     */
    protected $udpConnection;
    /**
     * @var array
     */
    protected $defaultTags = [];
    /**
     * @var array
     */
    protected $defaultFields = [];

    /**
     * InfluxDB constructor.
     *
     * @param $username
     * @param $password
     * @param $host
     * @param $database
     * @param $tcpPort
     * @param $udpPort
     */
    public function __construct($username, $password, $host, $database, $tcpPort = 8086, $udpPort = null)
    {
        $this->username = $username;
        $this->password = $password;
        $this->host = $host;
        $this->database = $database;
        $this->tcpPort = $tcpPort;
        $this->udpPort = $udpPort;
    }

    /**
     * Queue up a new point
     *
     * @param string $measurement the name of the measurement ... 'this-data'
     * @param null   $value       measurement value ... 15
     * @param array  $tags        measurement tags  ... ['host' => 'server01', 'region' => 'us-west']
     * @param array  $fields      measurement fields ... ['cpucount' => 10, 'free' => 2]
     * @param null   $timestamp   timestamp in nanoseconds on Linux ONLY
     *
     * @return $this
     */
    public function add($measurement, $value = null, array $tags = [], array $fields = [], $timestamp = null)
    {
        $this->points[] = new Point(
            $measurement,
            $value,
            array_merge($this->defaultTags, $tags),
            array_merge($this->defaultFields, $fields),
            $this->getNanoSecondTimestamp($timestamp)
        );

        return $this;
    }

    /**
     * A public way tog et the nanosecond precision we desire.
     *
     * @param null $timestamp
     *
     * @return int|null
     */
    public function getNanoSecondTimestamp($timestamp = null)
    {
        if (strlen($timestamp) == 19) {
            // Looks like it is already nanosecond precise!
            return $timestamp;
        }

        if (strlen($timestamp) == 10) {
            // This appears to be in seconds
            return $timestamp * 1000000000;
        }

        if (preg_match("/\d{10}\.\d{4}/", $timestamp)) {
            // This looks like a microtime float
            return (int)($timestamp * 1000000000);
        }

        // We weren't given a valid timestamp, generate.
        return (int)(microtime(true) * 1000000000);
    }

    /**
     * @return $this
     */
    public function flush()
    {
        if (empty($this->points)){
            return $this;
        }

        $this->getWriteConnection()->writePoints($this->points);
        $this->points = [];

        return $this;
    }

    /**
     * @return Database
     */
    public function getWriteConnection()
    {
        return is_null($this->udpPort)
            ? $this->getTcpConnection()
            : $this->getUdpConnection();
    }

    /**
     * @return Database
     */
    public function getTcpConnection()
    {
        if(!$this->tcpConnection) {
            $this->setTcpConnection(
                (new Client($this->host, $this->tcpPort, $this->username, $this->password))->selectDB($this->database)
            );
        }

        return $this->tcpConnection;
    }

    /**
     * @param Database $connection
     */
    public function setTcpConnection(Database $connection)
    {
        $this->tcpConnection = $connection;
    }

    /**
     * @return Database
     */
    public function getUdpConnection()
    {
        if(!$this->udpConnection) {
            $client = new Client($this->host, $this->udpPort, $this->username, $this->password);
            $client->setDriver(new UDP($this->host, $this->udpPort));
            $this->setUdpConnection($client->selectDB($this->database));
        }

        return $this->udpConnection;
    }

    /**
     * @param Database $connection
     */
    public function setUdpConnection(Database $connection)
    {
        $this->udpConnection = $connection;
    }

    /**
     * Pass through to the Influx client anything we don't handle. Use TCP so we provide reading and writing.
     *
     * @param $method
     * @param $parameters
     *
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return $this->getTcpConnection()->$method(...$parameters);
    }
}