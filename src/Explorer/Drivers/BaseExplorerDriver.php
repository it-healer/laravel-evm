<?php

namespace ItHealer\LaravelEvm\Explorer\Drivers;

use ItHealer\LaravelEvm\Explorer\Contracts\ExplorerDriverInterface;

abstract class BaseExplorerDriver implements ExplorerDriverInterface
{
    protected function formatProxy(?string $proxy): ?string
    {
        if (!$proxy) {
            return null;
        }

        if (preg_match('/^(socks4|socks5|https?|http):\/\/(([^:]+):([^@]+)@)?([^:\/]+)(:\d+)?$/', $proxy, $matches)) {
            $protocol = $matches[1];
            $username = $matches[3] ?? null;
            $password = $matches[4] ?? null;
            $host = $matches[5];
            $port = $matches[6] ?? '';

            if ($username && $password) {
                return "{$protocol}://{$username}:{$password}@{$host}{$port}";
            }

            return "{$protocol}://{$host}{$port}";
        }

        throw new \InvalidArgumentException('Invalid proxy format. Supported formats: socks4|socks5|http|https.');
    }
}
