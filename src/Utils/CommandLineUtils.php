<?php

namespace Swoop\Utils;

class CommandLineUtils
{
    /**
     * Parse command line arguments (argv) array.
     */
    public static function parseArguments(array $argv): array
    {
        $args = [];
        for ($i = 0; $i < count($argv); ++$i) {
            $arg = $argv[$i];
            if (str_starts_with($arg, '--')) {
                $parts = explode('=', substr($arg, 2), 2);
                $key = $parts[0];
                $value = $parts[1] ?? true;
                $args[$key] = $value;
            } elseif (str_starts_with($arg, '-') && strlen($arg) > 1) {
                $key = substr($arg, 1);
                if (isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '-')) {
                    $value = $argv[++$i];
                } else {
                    $value = true;
                }
                $args[$key] = $value;
            }
        }
        return $args;
    }
}
