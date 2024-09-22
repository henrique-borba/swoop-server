<?php

namespace Swoop\Workers\Interfaces;

interface WorkerInterface
{
    public function init(): void;
    public function run(): void;
    public function isAborted(): bool;
    public function setAborted(bool $value): void;
}
