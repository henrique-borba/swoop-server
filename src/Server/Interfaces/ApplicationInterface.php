<?php

namespace Swoop\Server\Interfaces;

use Swoop\Server\ApplicationConfig;

interface ApplicationInterface
{
    public function run($argv): void;
    public function getConfig(): ApplicationConfig;
}