<?php

declare(strict_types=1);

namespace Runomine\Http;

use Throwable;

/** The default sender: one ext-curl request with short timeouts. Never throws. */
final class CurlSender implements Sender
{
    public function __construct(
        private readonly float $timeout = 2.0,
        private readonly float $connectTimeout = 1.0,
    ) {
    }

    public function post(string $url, array $headers, string $body): int
    {
        if (! function_exists('curl_init')) {
            return 0;
        }

        try {
            $lines = [];
            foreach ($headers as $name => $value) {
                $lines[] = $name.': '.$value;
            }

            $ch = curl_init($url);
            if ($ch === false) {
                return 0;
            }

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $lines,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS => (int) ($this->timeout * 1000),
                CURLOPT_CONNECTTIMEOUT_MS => (int) ($this->connectTimeout * 1000),
                CURLOPT_NOSIGNAL => true,
                CURLOPT_FOLLOWLOCATION => false,
            ]);

            $ok = curl_exec($ch);
            $status = $ok === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

            return $status;
        } catch (Throwable) {
            return 0;
        }
    }
}
