<?php

declare(strict_types=1);

namespace Runomine\Http;

/**
 * Sends one HTTP POST. Implementations must not throw: they return the status code, or 0 when the request could not
 * be made at all (DNS, connection refused, timeout).
 */
interface Sender
{
    /** @param array<string, string> $headers */
    public function post(string $url, array $headers, string $body): int;
}
