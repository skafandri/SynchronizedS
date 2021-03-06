<?php

namespace Skafandri\SynchronizedBundle\Service;

use DateTime;
use Skafandri\SynchronizedBundle\Driver\DriverInterface;

class SynchronizedService
{

    private $originalService;

    /**
     *
     * @var DriverInterface
     */
    private $driver;
    private $synchronizedMethod;
    private $action;
    private $argument;
    private $retryDuration;
    private $retryCount;
    private $retryInfinite;
    private $serviceClass;

    public function __construct($originalService, DriverInterface $driver, $synchronizedMethod, $action, $argument, $retryDuration, $retryCount)
    {
        $this->originalService = $originalService;
        $this->driver = $driver;
        $this->synchronizedMethod = $synchronizedMethod;
        $this->action = $action;
        $this->argument = $argument;
        $this->retryDuration = $retryDuration;
        $this->retryCount = $retryCount;
        $this->retryInfinite = ($retryCount === -1);
        $this->serviceClass = get_class($originalService);
        $this->logDebug(
                sprintf(
                        'Synchronized service instance for %s::%s(%s) [driver:%s][action:%s][retryDuration:%s][retryCount:%s]', get_class($originalService), $synchronizedMethod, $argument, get_class($driver), $action, $retryDuration, $retryCount)
        );
    }

    public function __call($name, $arguments)
    {
        if ($name === $this->synchronizedMethod) {
            $lockName = $this->getLockName($arguments);
            if (!$this->getLock($lockName, $this->retryCount)) {
                $this->logDebug(sprintf('Failed to acquirelock "%s"', $lockName));
                return false;
            }
            $return = call_user_func_array(array($this->originalService, $name), $arguments);
            $this->releaseLock($lockName);
            return $return;
        }
        return call_user_func_array(array($this->originalService, $name), $arguments);
    }

    private function getLock($lockName, $retries)
    {
        if (!$this->retryInfinite && $retries < 0) {
            return false;
        }
        $this->logDebug(sprintf('Trying to get lock "%s"', $lockName));
        if (!$this->driver->getLock($lockName)) {
            $this->logDebug(sprintf('Failed, %s trials left to get lock "%s"', $retries, $lockName));
            usleep($this->retryDuration);
            return $this->getLock($lockName, $retries - 1);
        }
        $this->logDebug(sprintf('lock aquired "%s"', $lockName));
        return true;
    }

    private function releaseLock($lockName)
    {
        $this->driver->releaseLock($lockName);
        $this->logDebug(sprintf('lock released "%s"', $lockName));
    }

    private function getLockName($arguments)
    {
        $lockName = $this->serviceClass . ' ' . $this->synchronizedMethod;
        if (array_key_exists($this->argument, $arguments)) {
            $argumentHash = $this->getHashFromValue($arguments[$this->argument]);

            $lockName .= sprintf('_%s_%s', $this->argument, $argumentHash);
        }
        return $lockName;
    }

    private function getHashFromValue($value)
    {
        if (is_array($value) || is_object($value)) {
            return md5(serialize($value));
        }

        return $value;
    }

    private function logDebug($message)
    {
        $time = new DateTime(date('Y-m-d\TH:i:s') . substr(microtime(), 1, 9));
        echo sprintf("\n<br/>[%s] %s\n<br/>", $time->format('Y-m-d H:i:s.u'), $message);
    }

}
