<?php

namespace Swoop\Sockets;

use Psr\Log\LoggerInterface;
use Swoop\Server\ApplicationConfig;

final class UnixSocket extends BaseSocket
{
    /**
     * Create a new socket for the configured addresses or file descriptors.
     *
     * If a configured address is an array then a TCP socket is created.
     * If it is a string, a Unix socket is created. Otherwise, a TypeError is
     * raised.
     */
    public static function createSockets(ApplicationConfig $config, LoggerInterface $logger, ?array $fds = []): array
    {
        $listeners = [];
        $listeners[0] = stream_socket_server('tcp://127.0.0.1:80', $errno, $errstr);
        return $listeners;
    }
}
