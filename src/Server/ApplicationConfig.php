<?php

namespace Swoop\Server;

final class ApplicationConfig
{
    public string $workerClass;

    public int $numWorkers;

    public int $timeOut;
    public string $address;
    public int $timeout;
    public string $procName;
    public bool $preload = false;
    public int $max_requests = 0;
    public $max_requests_jitter = 0;
}
