<?php

declare(strict_types=1);

namespace Runomine\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Runomine\Client;
use Runomine\Http\CurlSender;
use Runomine\Http\Sender;
use Runomine\Testing\FakeSender;

final class ClientTest extends TestCase
{
    private const TS = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/';

    /** @var list<float> */
    private array $slept = [];

    protected function tearDown(): void
    {
        foreach (['RUNOMINE_TOKEN', 'RUNOMINE_URL', 'RUNOMINE_ENABLED'] as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    private function client(FakeSender $sender, array $options = []): Client
    {
        return new Client(...array_merge([
            'token' => VisitorHashTest::TOKEN,
            'sender' => $sender,
            'flushInterval' => 0,
            'sleep' => function (float $s): void {
                $this->slept[] = $s;
            },
            'clock' => fn () => new DateTimeImmutable('2026-10-13T18:04:05.123Z'),
        ], $options));
    }

    public function test_pageview_payload_matches_the_contract(): void
    {
        $sender = new FakeSender();
        $client = $this->client($sender);

        $client->pageview([
            'url' => 'https://example.com/pricing?utm_source=newsletter&utm_campaign=october',
            'referrer' => 'https://www.google.com/',
            'user_agent' => VisitorHashTest::UA,
            'ip' => VisitorHashTest::IP,
            'country' => 'es',
            'user' => 42,
        ]);
        self::assertSame(1, $client->flush());

        $request = $sender->requests()[0];
        self::assertSame('https://runomine.com/api/v1/events', $request['url']);
        self::assertSame('Bearer '.VisitorHashTest::TOKEN, $request['headers']['Authorization']);
        self::assertSame('application/json', $request['headers']['Content-Type']);
        self::assertSame(['events'], array_keys($request['payload']));

        $event = $request['payload']['events'][0];
        self::assertSame(['id', 'type', 'ts', 'url', 'referrer', 'user_agent', 'visitor', 'country', 'user'], array_keys($event));
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $event['id']);
        self::assertSame('pageview', $event['type']);
        self::assertSame('2026-10-13T18:04:05.123Z', $event['ts']);
        self::assertSame('https://example.com/pricing?utm_source=newsletter&utm_campaign=october', $event['url']);
        self::assertSame('https://www.google.com/', $event['referrer']);
        self::assertSame(VisitorHashTest::UA, $event['user_agent']);
        self::assertSame(VisitorHashTest::VISITOR, $event['visitor']); // host taken from the url
        self::assertSame('ES', $event['country']);
        self::assertSame('42', $event['user']);

        self::assertStringNotContainsString(VisitorHashTest::IP, $request['body'], 'The IP never leaves the server');
        self::assertStringNotContainsString('\/', $request['body']);
    }

    public function test_event_payload_matches_the_contract(): void
    {
        $sender = new FakeSender();
        $client = $this->client($sender);

        $client->track('purchase', ['plan' => 'rent', 'film' => 'the-third-man', 'qty' => 2], ['amount' => 4.99, 'currency' => 'eur'], '42', [
            'url' => 'https://example.com/checkout/success',
            'ip' => VisitorHashTest::IP,
            'user_agent' => VisitorHashTest::UA,
        ]);
        $client->flush();

        $event = $sender->events()[0];
        self::assertSame(['id', 'type', 'name', 'ts', 'url', 'visitor', 'user', 'revenue', 'props'], array_keys($event));
        self::assertSame('event', $event['type']);
        self::assertSame('purchase', $event['name']);
        self::assertMatchesRegularExpression(self::TS, $event['ts']);
        self::assertSame(VisitorHashTest::VISITOR, $event['visitor']);
        self::assertSame('42', $event['user']);
        self::assertSame(['amount' => 4.99, 'currency' => 'EUR'], $event['revenue']);
        self::assertSame(['plan' => 'rent', 'film' => 'the-third-man', 'qty' => 2], $event['props']);
        self::assertStringContainsString('"revenue":{"amount":4.99,"currency":"EUR"}', $sender->requests()[0]['body']);
        self::assertStringContainsString('"props":{', $sender->requests()[0]['body']);
    }

    public function test_an_event_without_request_context_has_no_visitor(): void
    {
        $sender = new FakeSender();
        $client = $this->client($sender);

        $client->track('signup', user: '7');
        $client->track('renewal', revenue: ['amount' => '10', 'currency' => 'EUR']);
        $client->flush();

        [$signup, $renewal] = $sender->events();
        self::assertSame(['id', 'type', 'name', 'ts', 'user'], array_keys($signup));
        self::assertSame(['id', 'type', 'name', 'ts', 'revenue'], array_keys($renewal));
        self::assertStringContainsString('"amount":10.0', $sender->requests()[0]['body']);
    }

    public function test_an_explicit_visitor_is_sent_as_is(): void
    {
        $sender = new FakeSender();
        $client = $this->client($sender);

        $client->track('checkout_completed', user: '7', context: ['visitor' => VisitorHashTest::VISITOR]);
        $client->flush();

        self::assertSame(VisitorHashTest::VISITOR, $sender->events()[0]['visitor']);
        self::assertSame(VisitorHashTest::VISITOR, $client->visitor(VisitorHashTest::IP, VisitorHashTest::UA, 'example.com'));
    }

    public function test_a_pageview_without_ip_or_visitor_is_dropped(): void
    {
        $sender = new FakeSender();
        $client = $this->client($sender);

        self::assertNull($client->pageview(['url' => 'https://example.com/', 'user_agent' => VisitorHashTest::UA]));
        self::assertNotNull($client->pageview(['url' => 'https://example.com/', 'visitor' => VisitorHashTest::VISITOR]));
        $client->flush();

        self::assertCount(1, $sender->events());
        self::assertSame(VisitorHashTest::VISITOR, $sender->events()[0]['visitor']);
    }

    public function test_props_are_kept_flat_and_bad_revenue_is_dropped(): void
    {
        $sender = new FakeSender();
        $client = $this->client($sender);

        $client->track('x', ['ok' => 'a', 'n' => 1.5, 'yes' => true, 'nested' => ['a' => 1], 'none' => null, 0 => 'list'], ['amount' => 'abc', 'currency' => 'EUR']);
        $client->flush();

        $event = $sender->events()[0];
        self::assertSame(['ok' => 'a', 'n' => 1.5, 'yes' => 'true'], $event['props']);
        self::assertArrayNotHasKey('revenue', $event);
    }

    public function test_ts_can_be_given(): void
    {
        $sender = new FakeSender();
        $client = $this->client($sender);

        $client->track('x', context: ['ts' => new DateTimeImmutable('2026-10-13T20:06:00+02:00')]);
        $client->flush();

        self::assertSame('2026-10-13T18:06:00.000Z', $sender->events()[0]['ts']);
    }

    public function test_pageview_without_url_is_ignored(): void
    {
        $sender = new FakeSender();
        $client = $this->client($sender);

        self::assertNull($client->pageview(['user_agent' => 'x']));
        self::assertSame([], $client->pending());
    }

    public function test_batches_are_at_most_500_events(): void
    {
        $sender = new FakeSender();
        $client = $this->client($sender, ['batchSize' => 5000]);

        for ($i = 0; $i < 1201; $i++) {
            $client->track('e'.$i);
        }
        // Auto-flushed each time the queue reached 500.
        self::assertCount(201, $client->pending());
        $client->flush();

        self::assertSame([500, 500, 201], array_map(fn ($r) => count($r['payload']['events']), $sender->requests()));
        self::assertCount(1201, array_unique(array_column($sender->events(), 'id')));
        self::assertSame([], $client->pending());
    }

    public function test_one_flush_of_a_big_queue_is_chunked(): void
    {
        $sender = new FakeSender();
        $client = $this->client($sender, ['batchSize' => 3]);
        for ($i = 0; $i < 7; $i++) {
            $client->track('e');
        }
        $client->flush();

        self::assertSame([3, 3, 1], array_map(fn ($r) => count($r['payload']['events']), $sender->requests()));
    }

    public function test_it_flushes_when_the_oldest_event_is_old_enough(): void
    {
        $sender = new FakeSender();
        $client = $this->client($sender, ['flushInterval' => 0.05]);

        $client->track('a');
        self::assertCount(0, $sender->requests());
        usleep(60_000);
        $client->track('b');

        self::assertCount(1, $sender->requests());
        self::assertCount(2, $sender->events());
    }

    public function test_5xx_and_429_are_retried_with_backoff(): void
    {
        $sender = new FakeSender([503, 429, 202]);
        $client = $this->client($sender);

        $client->track('a');
        self::assertSame(1, $client->flush());

        self::assertCount(3, $sender->requests());
        self::assertSame([0.2, 0.4], $this->slept);
        self::assertSame($sender->requests()[0]['body'], $sender->requests()[2]['body'], 'The same ids are resent');
    }

    public function test_it_gives_up_quietly_after_two_retries(): void
    {
        $sender = new FakeSender([], 500);
        $client = $this->client($sender);

        $client->track('a');
        self::assertSame(0, $client->flush());
        self::assertCount(3, $sender->requests());
        self::assertSame([], $client->pending(), 'Dropped, not kept forever');

        $client->track('b');
        $client->flush();
        self::assertCount(6, $sender->requests());
    }

    public function test_4xx_and_network_failures_are_not_retried(): void
    {
        foreach ([400, 401, 0] as $status) {
            $sender = new FakeSender([$status]);
            $client = $this->client($sender);
            $client->track('a');

            self::assertSame(0, $client->flush());
            self::assertCount(1, $sender->requests(), "status {$status}");
        }
        self::assertSame([], $this->slept);
    }

    public function test_it_never_throws_when_the_sender_does(): void
    {
        $sender = new class implements Sender {
            public int $calls = 0;

            public function post(string $url, array $headers, string $body): int
            {
                $this->calls++;
                throw new RuntimeException('boom');
            }
        };
        $client = new Client(token: 'rm_live_x', sender: $sender, flushInterval: 0);

        $client->track('a');
        self::assertSame(0, $client->flush());
        self::assertSame(1, $sender->calls);
    }

    public function test_curl_against_a_closed_port_fails_quietly(): void
    {
        $client = new Client(token: 'rm_live_x', url: 'http://127.0.0.1:9', sender: new CurlSender(1.0, 0.5), flushInterval: 0);
        $client->track('a');

        $started = microtime(true);
        self::assertSame(0, $client->flush());
        self::assertLessThan(2.0, microtime(true) - $started);
    }

    public function test_disabled_without_a_token(): void
    {
        $sender = new FakeSender();
        $client = new Client(token: '', sender: $sender);

        self::assertFalse($client->isEnabled());
        self::assertNull($client->track('a'));
        self::assertNull($client->pageview(['url' => 'https://example.com/']));
        $client->flush();
        self::assertSame([], $sender->requests());
    }

    public function test_config_from_the_environment(): void
    {
        putenv('RUNOMINE_TOKEN=rm_live_env');
        putenv('RUNOMINE_URL=https://eu.runomine.test/');
        $client = new Client(sender: new FakeSender());

        self::assertTrue($client->isEnabled());
        self::assertSame('https://eu.runomine.test/api/v1/events', $client->endpoint());

        putenv('RUNOMINE_ENABLED=false');
        self::assertFalse((new Client(sender: new FakeSender()))->isEnabled());
    }

    public function test_checkout_metadata_matches_the_contract_vector(): void
    {
        $client = $this->client(new FakeSender());

        self::assertSame(
            ['runomine_visitor' => VisitorHashTest::VISITOR, 'runomine_user' => '42'],
            $client->checkoutMetadata(VisitorHashTest::IP, VisitorHashTest::UA, 'Example.com:443', 42),
        );
        self::assertSame(
            ['runomine_visitor' => VisitorHashTest::VISITOR],
            $client->checkoutMetadata(VisitorHashTest::IP, VisitorHashTest::UA, 'example.com'),
        );
        self::assertSame('7', $client->checkoutMetadata(VisitorHashTest::IP, VisitorHashTest::UA, 'example.com', 7)['runomine_user']);
    }

    public function test_checkout_metadata_leaves_out_what_is_unknown(): void
    {
        $client = $this->client(new FakeSender());

        // No IP (a job, a webhook): no visitor, never a made-up one.
        self::assertSame(['runomine_user' => 'acct_9'], $client->checkoutMetadata(null, VisitorHashTest::UA, 'example.com', 'acct_9'));
        self::assertSame(['runomine_user' => '0'], $client->checkoutMetadata('', null, null, 0));
        self::assertSame([], $client->checkoutMetadata(null, null, null, ''));

        // Disabled: the user still goes, the visitor doesn't.
        $off = $this->client(new FakeSender(), ['token' => '']);
        self::assertSame(['runomine_user' => '42'], $off->checkoutMetadata(VisitorHashTest::IP, VisitorHashTest::UA, 'example.com', 42));
        $disabled = $this->client(new FakeSender(), ['enabled' => false]);
        self::assertSame([], $disabled->checkoutMetadata(VisitorHashTest::IP, VisitorHashTest::UA, 'example.com'));
    }

    public function test_it_flushes_on_destruct(): void
    {
        $sender = new FakeSender();
        $client = $this->client($sender);
        $client->track('a');
        unset($client);

        self::assertCount(1, $sender->events());
    }
}
