<?php

declare(strict_types=1);

namespace Runomine;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * The daily visitor hash, identical in every runomine library and in runomine itself (docs/contract.md).
 *
 *   day     = the UTC date, "YYYY-MM-DD"
 *   salt    = hex(HMAC-SHA256(key = token, message = "runomine:" + day))
 *   visitor = first 16 hex chars of hex(SHA256(salt + "|" + ip + "|" + user_agent + "|" + host))
 *   host    = the request's hostname, lowercase, without port
 *
 * It runs on your server: the IP is only an input here and is never sent anywhere.
 */
final class VisitorHash
{
    public static function compute(
        string $token,
        string $ip,
        string $userAgent,
        string $host,
        ?DateTimeInterface $day = null,
    ): string {
        $salt = self::salt($token, $day);

        return substr(hash('sha256', $salt.'|'.$ip.'|'.$userAgent.'|'.self::normalizeHost($host)), 0, 16);
    }

    /** hex(HMAC-SHA256(token, "runomine:" + UTC day)). */
    public static function salt(string $token, ?DateTimeInterface $day = null): string
    {
        $day = DateTimeImmutable::createFromInterface($day ?? new DateTimeImmutable())
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d');

        return hash_hmac('sha256', 'runomine:'.$day, $token);
    }

    /** Lowercase, without port. Accepts "Example.com:8080" and "[::1]:8080". */
    public static function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));

        if (str_starts_with($host, '[')) {
            $end = strpos($host, ']');

            return $end === false ? $host : substr($host, 0, $end + 1);
        }

        // Only strip a port when there is exactly one colon: a bare IPv6 address has several.
        if (substr_count($host, ':') === 1) {
            $host = substr($host, 0, (int) strpos($host, ':'));
        }

        return $host;
    }
}
