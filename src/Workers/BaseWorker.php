<?php

namespace Swoop\Workers;

use Psr\Log\LoggerInterface;
use Swoop\Server\ApplicationConfig;
use Swoop\Server\Interfaces\ApplicationInterface;
use Swoop\Workers\Interfaces\WorkerInterface;

abstract class BaseWorker implements WorkerInterface
{
    public mixed $pid;

    public array $sockets;
    private int $age;
    protected int $ppid;
    private ApplicationInterface $application;
    protected float $timeout;
    private ApplicationConfig $config;
    private bool $booted;
    private bool $aborted;
    /**
     * @var int|mixed
     */
    private mixed $nr;
    private int $maxRequests;
    /**
     * @var true
     */
    protected bool $alive;
    private ?LoggerInterface $log;
    private array|false $pipe;

    public function __construct(int $age, int $ppid, array $sockets,
        ApplicationInterface $application, float $timeout,
        ApplicationConfig $config, ?LoggerInterface $logger)
    {
        $this->age = $age;
        $this->pid = '[booting]';
        $this->ppid = $ppid;
        $this->sockets = $sockets;
        $this->application = $application;
        $this->timeout = $timeout;
        $this->config = $config;
        $this->booted = false;
        $this->aborted = false;
        $this->nr = 0;

        if ($config->max_requests > 0) {
            $jitter = rand(0, $config->max_requests_jitter);
            $this->maxRequests = $config->max_requests + $jitter;
        } else {
            $this->maxRequests = PHP_INT_MAX;
        }

        $this->alive = true;
        $this->log = $logger;
    }

    public function getLogger(): LoggerInterface
    {
        return $this->log;
    }

    protected function initSignals(): void
    {
        pcntl_async_signals(true);
        pcntl_signal(SIGQUIT, [$this, 'handleQuit']);
        pcntl_signal(SIGTERM, [$this, 'handleExit']);
        pcntl_signal(SIGINT, [$this, 'handleQuit']);
        pcntl_signal(SIGWINCH, [$this, 'handleWinch']);
        pcntl_signal(SIGUSR1, [$this, 'handleUsr1']);
        pcntl_signal(SIGABRT, [$this, 'handleAbort']);
    }

    public function handleUsr1(): void
    {
        $this->log->debug('worker: SIGUSR1');
    }

    public function handleWinch(): void
    {
        $this->log->debug('worker: SIGWINCH ignored');
    }

    public function handleExit(): void
    {
        $this->alive = false;
    }

    public function handleQuit(): void
    {
        $this->alive = false;
        sleep(0.1);
        exit(0);
    }

    public function handleAbort(): void
    {
        $this->alive = false;
        exit(1);
    }

    public function init(): void
    {
        $this->pipe = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        foreach ($this->pipe as $pipe) {
            stream_set_blocking($pipe, true);
        }
        $this->initSignals();
        $this->booted = true;
        $this->run();
    }

    /**
     * Return parent process PID.
     *
     * Avoid use POSIX extension only for this.
     */
    protected function getOSPPID(): ?int
    {
        $statusFile = '/proc/self/status';
        if (!file_exists($statusFile)) {
            return null; // Not a Linux-based system or `/proc` is unavailable
        }
        $statusContent = file_get_contents($statusFile);
        if (false === $statusContent) {
            return null; // Failed to read the file
        }
        if (preg_match('/^PPid:\s+(\d+)/m', $statusContent, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    public function __toString(): string
    {
        return "<Worker {$this->pid}>";
    }
}
