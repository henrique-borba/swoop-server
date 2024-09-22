<?php

namespace Swoop;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Swoop\Server\Interfaces\ApplicationInterface;
use Swoop\Sockets\UnixSocket;
use Swoop\Utils\CommandLineUtils;
use Swoop\Workers\BaseWorker;
use Swoop\Workers\Interfaces\WorkerInterface;

class Arbiter
{
    private ApplicationInterface $application;
    private ?int $numWorkers;
    /**
     * @var int|mixed
     */
    private mixed $masterPid;
    private string $masterName;
    private int $workerAge;
    private bool $systemd;
    private ?string $pidfile;
    private int $reexecPid;
    private array $args;
    private LoggerInterface $log;
    private string $workerClass;
    private string $address;
    private int $timeout;
    private string $procName;
    private int $pid;
    private array $pipe = [];

    /**
     * @var WorkerInterface[]
     */
    private array $workers = [];
    private array $listeners;

    private array $signals = [
        SIGHUP,
        SIGQUIT,
        SIGINT,
        SIGTERM,
        SIGTTIN,
        SIGTTOU,
        SIGUSR1,
        SIGUSR2,
        SIGWINCH,
    ];

    private array $signalsString = [
        SIGHUP => 'SIGHUP',
        SIGQUIT => 'SIGQUIT',
        SIGINT => 'SIGINT',
        SIGTERM => 'SIGTERM',
        SIGTTIN => 'SIGTTIN',
        SIGTTOU => 'SIGTTOU',
        SIGUSR1 => 'SIGUSR1',
        SIGUSR2 => 'SIGUSR2',
        SIGWINCH => 'SIGWINCH',
    ];

    private array $sigQueue = [];

    public function __construct(ApplicationInterface $application, $argv)
    {
        $this->numWorkers = null;
        $this->masterPid = 0;
        $this->masterName = 'Master';
        $this->workerAge = 0;
        $this->systemd = false;
        $this->pidfile = null;
        $this->reexecPid = 0;
        $this->args = CommandLineUtils::parseArguments($argv);
        $this->setup($application);
    }

    /**
     * Master Loop.
     */
    public function run(): void
    {
        $this->start();
        cli_set_process_title("swoop master [{$this->procName}]");
        try {
            $this->manageWorkers();
            while (true) {
                $this->maybePromote();
                $sig = null;
                if (count($this->sigQueue)) {
                    $sig = array_shift($this->sigQueue);
                }
                if (!isset($sig)) {
                    $this->sleep();
                    $this->murderWorkers();
                    $this->manageWorkers();
                    continue;
                }
                if (!array_key_exists($sig, $this->signals)) {
                    $this->log->info("Ignoring unknown signal [{$sig}]");
                    continue;
                }
                $this->log->info("SIGQUEUE");
                $this->wakeup();
            }
        } catch (\Exception $e) {
            $this->log->error($e->getMessage());
            exit(-1);
        }
    }

    private function setup(ApplicationInterface $application): void
    {
        $this->application = $application;
        if (!isset($this->log)) {
            $this->log = new Logger(self::class);
            $this->log->pushHandler(new StreamHandler('php://stdout'));
        }

        $this->workerClass = $this->application->getConfig()->workerClass;
        $this->address = $this->application->getConfig()->address;
        $this->numWorkers = $this->application->getConfig()->numWorkers;
        $this->timeout = $this->application->getConfig()->timeout;
        $this->procName = $this->application->getConfig()->procName;

        $this->log->info('Current Configuration: '.json_encode($this->application->getConfig(), JSON_PRETTY_PRINT));

        if ($this->application->getConfig()->preload) {
            $this->log->info('Preloading configuration');
        }
    }

    /**
     * Initialize the Arbiter.
     */
    private function start(): void
    {
        $this->log->info('Starting Swoop');

        if (getenv('SWOOP_PID')) {
            $this->masterPid = (int) getenv('SWOOP_PID');
            $this->procName = $this->procName.'.2';
            $this->masterName = 'Master.2';
        }

        $this->pid = getmypid();
        $this->initSignals();

        if (!isset($this->listeners)) {
            $this->listeners = UnixSocket::createSockets($this->application->getConfig(), $this->log);
        }

        $this->log->debug('Arbiter booted');
        $this->log->info("Listening at: ({$this->pid})");
        $this->log->info("Using worker: {$this->workerClass}");
    }

    /**
     * Initialize master signal handling. Most of the signals
     * are queued. Child signals only wake up the master.
     *
     * @return void
     */
    private function initSignals()
    {
        pcntl_async_signals(true);
        foreach ($this->pipe as $pipe) {
            fclose($pipe);
        }
        $this->pipe = $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        foreach ($pair as $pipe) {
            stream_set_blocking($pipe, true);
        }

        foreach ($this->signals as $signal) {
            pcntl_signal($signal, [$this, 'signal']);
        }
        pcntl_signal(SIGCHLD, [$this, 'handleChld']);
    }

    public function signal(int $signal): void
    {
        if ($signal == SIGINT) {
            $this->murderWorkers();
            exit($signal);
        }
        if (count($this->sigQueue) < 5) {
            $this->sigQueue[] = $signal;
            $this->wakeup();
        }
    }

    public function handleChld(int $signal): void
    {
        $this->reapWorkers();
        $this->wakeup();
    }

    /**
     * Maintain the number of workers by spawning or killing
     * as required.
     *
     * @return void
     *
     * @throws \Exception
     */
    private function manageWorkers()
    {
        if (count($this->workers) < $this->numWorkers) {
            $this->spawnWorkers();
        }
    }

    /**
     * Spawn new workers as needed.
     *
     * This is where a worker process leaves the main loop
     * of the master process.
     *
     * @return void
     *
     * @throws \Exception
     */
    private function spawnWorkers()
    {
        print_r(count($this->workers));
        while (count($this->workers) < $this->numWorkers) {
            $this->spawnWorker();
            sleep(0.1 * (rand(0, 1) / 2));
        }
    }

    /**
     * @throws \Exception
     */
    private function spawnWorker(): int
    {
        ++$this->workerAge;
        /**
         * @var BaseWorker $worker
         */
        $worker = new $this->workerClass(
            $this->workerAge,
            $this->pid,
            $this->listeners,
            $this->application,
            $this->timeout / 2.0,
            $this->application->getConfig(),
            $this->log
        );

        $pid = pcntl_fork();

        if (-1 == $pid) {
            throw new \Exception('Failed to fork worker class');
        }

        if (0 != $pid) {
            $worker->pid = $pid;
            $this->workers[$pid] = $worker;

            return $pid;
        }

        $worker->pid = getmypid();
        try {
            cli_set_process_title("swoop worker [{$this->procName}]");
            $this->log->info("Booting worker with PID: {$worker->pid}");
            $worker->init();
            exit(0);
        } catch (\Exception $e) {
            throw new \Exception('Exception while loading application. '.$e->getMessage());
        }
    }

    private function maybePromote(): void
    {
        if (0 === $this->masterPid) {
            return;
        }

        if ($this->masterPid != getmypid()) {
            $this->log->info('Master has been promoted.');
            $this->masterName = 'Master';
            $this->masterPid = 0;
            $this->procName = 'master';
            putenv('SWOOP_PID');
            cli_set_process_title("swoop master [{$this->procName}]");
        }
    }

    /**
     * Wake up the arbiter by writing to the PIPE.
     *
     * @throws \Exception
     */
    private function wakeup(): void
    {
        try {
            fwrite($this->pipe[1], '.');
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Reap workers to avoid zombie processes.
     *
     * @return void
     */
    private function reapWorkers()
    {
        while (true) {
            $wpid = pcntl_waitpid(-1, $status, WNOHANG);
            if (!$wpid) {
                break;
            }
            if ($this->reexecPid == $wpid) {
                $this->reexecPid = 0;
            } else {
                $exitCode = $status >> 8;
                if (0 != $exitCode) {
                    $this->log->error("Worker (pid {$wpid}) exited with code {$exitCode}");
                } elseif ($status > 0) {
                    if (array_key_exists($status, $this->signalsString)) {
                        $sig_name = $this->signalsString[$status];
                    } else {
                        $sig_name = "code {$status}";
                    }
                    $msg = "Worker (pid: {$wpid} was sent {$sig_name})!";
                    if (SIGKILL == $status) {
                        $msg .= ' Perhaps out of memory?';
                    }
                    $this->log->error($msg);
                }
            }
            if (!array_key_exists($wpid, $this->workers)) {
                continue;
            }
            unset($this->workers[$wpid]);
        }
    }

    private function sleep(): void
    {
        sleep(1);
        /**
        try {
            // Set up the read end of the pipe
            $read = [$this->pipe[0]];
            $write = [];
            $except = [];

            // Use stream_select to wait until pipe[0] is readable or timeout
            $ready = stream_select($read, $write, $except, 1, 0); // 1 second timeout
            if (false === $ready) throw new \Exception('Error during stream_select.');
            if (0 === $ready) {
                return;
            }
            while ($data = fread($this->pipe[0], 1)) {
            }
        } catch (Exception $e) {
            throw $e;
        }**/
    }

    /**
     * @return void
     */
    private function murderWorkers(): void
    {
        if (!$this->timeout) {
            return;
        }
        foreach ($this->workers as $pid => $worker) {
            if (!$worker->isAborted()) {
                $this->log->critical("WORKER TIMEOUT (pid: {$pid})");
                $worker->setAborted(true);
                $this->killWorker($pid, SIGABRT);
            } else {
                $this->killWorker($pid, SIGKILL);
            }
        }
    }

    private function killWorker(int|string $pid, int $signal)
    {
        exec("kill -$signal $pid");
        unset($this->workers[$pid]);
    }
}
