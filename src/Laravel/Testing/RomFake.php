<?php

declare(strict_types=1);

namespace Runomine\Laravel\Testing;

use Illuminate\Contracts\Container\Container;
use PHPUnit\Framework\Assert as PHPUnit;
use Runomine\Client;
use Runomine\Laravel\Tracker;
use Runomine\Testing\FakeSender;

/**
 * Returned by Rom::fake(). Everything goes through the real event building (so payloads are exactly what would be
 * sent), into an in-memory sender instead of the network.
 */
class RomFake extends Tracker
{
    private FakeSender $sender;

    public static function make(Container $app): self
    {
        $sender = new FakeSender();
        $token = (string) ($app['config']->get('runomine.token') ?: 'rm_live_fake');
        $client = new Client(token: $token, url: 'https://runomine.test', enabled: true, sender: $sender, flushInterval: 0);

        $fake = new self($client, $app);
        $fake->sender = $sender;

        return $fake;
    }

    /**
     * Every event recorded so far, as it would be sent.
     *
     * @return list<array<string, mixed>>
     */
    public function events(): array
    {
        $this->client->flush();

        return $this->sender->events();
    }

    /** @return list<array<string, mixed>> */
    public function tracked(?string $name = null, ?callable $callback = null): array
    {
        return array_values(array_filter(
            $this->events(),
            fn (array $e) => $e['type'] === 'event'
                && ($name === null || $e['name'] === $name)
                && ($callback === null || $callback($e)),
        ));
    }

    /** @return list<array<string, mixed>> */
    public function pageviews(?callable $callback = null): array
    {
        return array_values(array_filter(
            $this->events(),
            fn (array $e) => $e['type'] === 'pageview' && ($callback === null || $callback($e)),
        ));
    }

    /**
     * Every sample sent so far, as it was sent: ['ts' => ISO 8601, 'data' => [...]].
     *
     * @return list<array{ts: string, data: array<string, mixed>}>
     */
    public function sampled(?callable $callback = null): array
    {
        return array_values(array_filter(
            $this->sender->samples(),
            fn (array $s) => $callback === null || $callback($s),
        ));
    }

    /** @param (callable(array{ts: string, data: array<string, mixed>}): bool)|null $callback */
    public function assertSampled(?callable $callback = null): static
    {
        PHPUnit::assertNotEmpty($this->sampled($callback), 'The expected sample was not sent.');

        return $this;
    }

    public function assertNothingSampled(): static
    {
        PHPUnit::assertEmpty($this->sampled(), 'Samples were sent unexpectedly.');

        return $this;
    }

    /** @param (callable(array<string, mixed>): bool)|null $callback receives the event array */
    public function assertTracked(string $name, ?callable $callback = null): static
    {
        PHPUnit::assertNotEmpty($this->tracked($name, $callback), "The expected [{$name}] event was not tracked.");

        return $this;
    }

    public function assertTrackedTimes(string $name, int $times): static
    {
        $count = count($this->tracked($name));
        PHPUnit::assertSame($times, $count, "The [{$name}] event was tracked {$count} times instead of {$times}.");

        return $this;
    }

    public function assertNotTracked(string $name, ?callable $callback = null): static
    {
        PHPUnit::assertEmpty($this->tracked($name, $callback), "The unexpected [{$name}] event was tracked.");

        return $this;
    }

    public function assertNothingTracked(): static
    {
        PHPUnit::assertEmpty($this->tracked(), 'Events were tracked unexpectedly.');

        return $this;
    }

    public function assertPageviewRecorded(?callable $callback = null): static
    {
        PHPUnit::assertNotEmpty($this->pageviews($callback), 'The expected pageview was not recorded.');

        return $this;
    }

    public function assertNoPageviews(): static
    {
        PHPUnit::assertEmpty($this->pageviews(), 'Pageviews were recorded unexpectedly.');

        return $this;
    }
}
