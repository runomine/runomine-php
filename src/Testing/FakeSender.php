<?php

declare(strict_types=1);

namespace Runomine\Testing;

use Runomine\Http\Sender;

/**
 * An in-memory Sender for tests: records every request and answers with the queued statuses (202 by default).
 *
 *   $sender = new FakeSender([503, 202]);
 *   $client = new Runomine\Client(token: 'rm_live_x', sender: $sender);
 */
final class FakeSender implements Sender
{
    /** @var list<array{url: string, headers: array<string, string>, body: string, payload: mixed}> */
    private array $requests = [];

    /** @param list<int> $statuses Answers, in order. When they run out, $default is used. */
    public function __construct(private array $statuses = [], private readonly int $default = 202)
    {
    }

    public function post(string $url, array $headers, string $body): int
    {
        $this->requests[] = [
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
            'payload' => json_decode($body, true),
        ];

        return array_shift($this->statuses) ?? $this->default;
    }

    /** @return list<array{url: string, headers: array<string, string>, body: string, payload: mixed}> */
    public function requests(): array
    {
        return $this->requests;
    }

    /**
     * Every event in every request, in order (a retried batch appears once per attempt).
     *
     * @return list<array<string, mixed>>
     */
    public function events(): array
    {
        $events = [];
        foreach ($this->requests as $request) {
            foreach ($request['payload']['events'] ?? [] as $event) {
                $events[] = $event;
            }
        }

        return $events;
    }
}
