<?php

namespace Swoop\Workers;

final class SyncWorker extends BaseWorker
{
    /**
     * @throws \Exception
     */
    public function run(): void
    {
        $timeout = 0.5;
        if (isset($this->timeout)) {
            $timeout = $this->timeout;
        }
        foreach ($this->sockets as $socket) {
            stream_set_blocking($socket, false);
        }

        if (count($this->sockets) > 1) {
            $this->runMultiple($timeout);
        }
        $this->runForOne($timeout);
    }

    /**
     * @throws \Exception
     */
    private function runMultiple(int $timeout): void
    {
        throw new \Exception('Not implemented');
    }

    private function runForOne(int $timeout): void
    {
        $listener = $this->sockets[0];
        while ($this->alive) {
            // $this->notify();
            $this->accept($listener, $timeout);

            if (!$this->isParentAlive()) {
                return;
            }
        }
    }

    private function accept(mixed $listener, int $timeout): void
    {
        $client = @stream_socket_accept($listener, -1, $peer);
        if ($client) {
            stream_set_blocking($client, true);
            $this->handle($listener, $client, $peer);
        }
    }

    private function handle(mixed $listener, mixed $client, string $peer)
    {
        fwrite($client, self::generateResponse('OK'));
        fclose($client);
    }

    public function isParentAlive(): bool
    {
        if ($this->ppid != $this->getOSPPID()) {
            $this->getLogger()->info("Parent changed [{$this->ppid}], shutting down ".$this);

            return false;
        }

        return true;
    }

    public static function generateResponse(string $body = '', array $headers = []): string
    {
        // Default headers
        $defaultHeaders = [
            'Content-Length' => strlen($body),
            'Connection' => 'close',
            'Server' => 'SwoopServer/1.0',
        ];

        // Merge default headers with custom headers
        $headers = $defaultHeaders;

        // Convert headers to string format
        $headerStrings = [];
        foreach ($headers as $name => $value) {
            $headerStrings[] = "$name: $value";
        }
        $headerString = implode("\r\n", $headerStrings);

        // Create the full response
        $response = "HTTP/1.1 200 OK\r\n";
        $response .= $headerString."\r\n\r\n";
        $response .= $body;

        return $response;
    }
}
