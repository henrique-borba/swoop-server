<?php

namespace Swoop\Workers\Interfaces;

interface WorkerInterface
{
    public function init(): void;
    public function run(): void;
}