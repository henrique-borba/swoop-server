<?php

namespace Swoop\Server;

use Swoop\Arbiter;
use Swoop\Server\Interfaces\ApplicationInterface;
use Swoop\Workers\SyncWorker;

abstract class BaseApplication implements ApplicationInterface
{
    private ApplicationConfig $config;

    public function __construct()
    {
        $this->loadConfig();
    }

    public function run($argv): void
    {
        $arbiter = new Arbiter($this, $argv);
        $arbiter->run();
    }

    private function loadConfig()
    {
        $this->config = new ApplicationConfig();
        $this->config->numWorkers = 24;
        $this->config->workerClass = SyncWorker::class;
        $this->config->address = 'localhost';
        $this->config->timeout = 0;
        $this->config->procName = 'threaded_worker';
    }

    public function getConfig(): ApplicationConfig
    {
        return $this->config;
    }
}
