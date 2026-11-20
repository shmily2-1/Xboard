<?php

namespace App\Support;

use InvalidArgumentException;

/** Shared node-API and admin validation; never accesses the database or firewall. */
final class Hysteria2PortHopping
{
    public static function ports(mixed $value): ?string
    {
        if ((!is_string($value) && !is_int($value)) ||
            !preg_match('/^([0-9]{1,5})(?:-([0-9]{1,5}))?$/D', trim((string) $value), $matches)) {
            throw new InvalidArgumentException('Hysteria2 port must be a single port or start-end range; comma lists are not supported by Xboard subscriptions.');
        }
        $first = (int) $matches[1];
        $last = isset($matches[2]) ? (int) $matches[2] : $first;
        if ($first < 1 || $last > 65535 || $first > $last) {
            throw new InvalidArgumentException('Hysteria2 ports must be an ascending range within 1..65535.');
        }
        return isset($matches[2]) ? "{$first}-{$last}" : null;
    }

    public static function interval(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ((!is_int($value) && !is_string($value)) || filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException('Hysteria2 hop_interval must be an integer number of seconds.');
        }
        $interval = (int) $value;
        if ($interval !== 0 && $interval < 5) {
            throw new InvalidArgumentException('Hysteria2 hop_interval must be at least 5 seconds, or empty/0 for the client default.');
        }
        return $interval;
    }

    public static function listenerPort(mixed $value): int
    {
        $range = self::ports($value);
        if ($range !== null) {
            throw new InvalidArgumentException('Hysteria2 server_port must remain a single listening port, not a range.');
        }
        return (int) trim((string) $value);
    }
}
