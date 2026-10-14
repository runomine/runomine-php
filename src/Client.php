<?php

declare(strict_types=1);

namespace Runomine;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Runomine\Http\CurlSender;
use Runomine\Http\Sender;
use Throwable;
use WeakReference;

/**
 * The framework-agnostic runomine client.
 *
 * Events are queued in memory and sent in batches of at most 500 to POST {url}/api/v1/events: on flush(), when the
 * queue is full, when the oldest queued event is older than $flushInterval seconds, on destruct and at shutdown.
 * 5xx and 429 answers are retried with a short backoff, then the batch is dropped. Nothing here ever throws.
 *
 * The library sends raw facts only (url, referrer, user agent, visitor hash, country, user, props). Source, bot and
 * device classification happen in runomine. The visitor's IP is used to compute the hash and is never sent.
 *
 * Samples (an app's own ops numbers) go to POST {url}/api/v1/samples at once, not through the queue.
 */
class Client
{
    public const MAX_BATCH = 500;

    public const DEFAULT_URL = 'https://runomine.com';

    private readonly string $token;

    private readonly string $endpoint;

    private readonly string $samplesEndpoint;

    private readonly bool $enabled;

    private readonly Sender $sender;

    private readonly int $batchSize;

    /** @var Closure(float): void */
    private readonly Closure $sleep;

    /** @var Closure(): DateTimeInterface */
    private readonly Closure $clock;

    /** @var list<array<string, mixed>> */
    private array $queue = [];

    private ?float $queuedAt = null;

    private bool $flushing = false;

    /**
     * Every argument left null is read from the environment: RUNOMINE_TOKEN, RUNOMINE_URL (default
     * https://runomine.com) and RUNOMINE_ENABLED (default true when the token is set).
     *
     * @param  int  $retries  Extra attempts after a 5xx or 429 answer.
     * @param  float  $flushInterval  Flush when the oldest queued event is this many seconds old (0 = only on size).
     * @param  (callable(float): void)|null  $sleep  Backoff sleeper, in seconds. Injectable for tests.
     * @param  (Closure(): DateTimeInterface)|null  $clock  Current time, for ts and the visitor hash's day.
     */
    public function __construct(
        ?string $token = null,
        ?string $url = null,
        ?bool $enabled = null,
        ?Sender $sender = null,
        int $batchSize = self::MAX_BATCH,
        private readonly int $retries = 2,
        private readonly float $flushInterval = 5.0,
        ?callable $sleep = null,
        ?Closure $clock = null,
        float $timeout = 2.0,
    ) {
        $this->token = trim((string) ($token ?? self::env('RUNOMINE_TOKEN') ?? ''));
        $url = trim((string) ($url ?? self::env('RUNOMINE_URL') ?? ''));
        $base = rtrim($url !== '' ? $url : self::DEFAULT_URL, '/');
        $this->endpoint = $base.'/api/v1/events';
        $this->samplesEndpoint = $base.'/api/v1/samples';
        $enabled ??= self::bool(self::env('RUNOMINE_ENABLED')) ?? true;
        $this->enabled = $enabled && $this->token !== '';
        $this->sender = $sender ?? new CurlSender($timeout);
        $this->batchSize = max(1, min($batchSize, self::MAX_BATCH));
        $this->sleep = $sleep !== null
            ? Closure::fromCallable($sleep)
            : static function (float $seconds): void {
                usleep((int) ($seconds * 1_000_000));
            };
        $this->clock = $clock ?? static fn (): DateTimeInterface => new DateTimeImmutable('now', new DateTimeZone('UTC'));

        // Flush whatever is left when the script ends, without keeping the client alive just for that.
        $ref = WeakReference::create($this);
        register_shutdown_function(static function () use ($ref): void {
            $ref->get()?->flush();
        });
    }

    /** A client configured from RUNOMINE_TOKEN, RUNOMINE_URL and RUNOMINE_ENABLED. */
    public static function fromEnv(): self
    {
        return new self();
    }

    public function __destruct()
    {
        $this->flush();
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function endpoint(): string
    {
        return $this->endpoint;
    }

    /** Today's visitor hash for this client's token (see VisitorHash). */
    public function visitor(string $ip, string $userAgent, string $host): string
    {
        return VisitorHash::compute($this->token, $ip, $userAgent, $host, ($this->clock)());
    }

    /**
     * Identifiers to pass to your payment provider when creating a checkout (Stripe metadata, Paddle custom_data,
     * Lemon Squeezy custom data, Shopify cart attributes, PayPal custom_id). runomine reads them back from the
     * provider to attribute the sale to this visitor and user.
     *
     * Only keys with a value: runomine_visitor (today's visitor hash; left out when the client is disabled or there
     * is no IP) and runomine_user (your user id, as a string). Never throws.
     *
     * @return array<string, string>
     */
    public function checkoutMetadata(
        ?string $ip,
        ?string $userAgent = null,
        ?string $host = null,
        string|int|null $user = null,
    ): array {
        $metadata = [];

        try {
            if ($this->enabled && ($ip = self::str($ip)) !== null) {
                $metadata['runomine_visitor'] = $this->visitor($ip, (string) $userAgent, (string) $host);
            }
        } catch (Throwable) {
        }
        if (($user = self::str($user)) !== null) {
            $metadata['runomine_user'] = $user;
        }

        return $metadata;
    }

    /**
     * Queue a pageview from raw request data:
     *  - url (required): the full URL of the page
     *  - referrer, user_agent, country, user: optional
     *  - ip + user_agent (+ host, default the url's host): used to compute the visitor hash, never sent
     *  - visitor: a precomputed hash instead of ip
     * A pageview needs a visitor, so one with neither ip nor visitor is dropped.
     *  - ts: DateTimeInterface, ISO 8601 string or Unix seconds (default now)
     *  - props: like track()'s, flat, up to 20 keys, string or number values. runomine stores them as the page
     *    view's dims, each key a breakdown and a filter in Traffic (`area` feeds the Areas breakdown).
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>|null The queued event, or null when disabled or invalid.
     */
    public function pageview(array $request): ?array
    {
        try {
            if (! $this->enabled) {
                return null;
            }
            $url = self::str($request['url'] ?? null);
            if ($url === null) {
                return null;
            }

            $event = $this->event('pageview', null, $request, withUserAgent: true);
            if (! isset($event['visitor'])) {
                return null; // required for pageviews: without ip (or visitor) there is no one to count
            }
            if (is_array($request['props'] ?? null) && ($props = self::props($request['props'])) !== []) {
                $event['props'] = $props;
            }

            return $this->push($event);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Queue a business event: signup, checkout_started, purchase…
     *
     * @param  array<string, string|int|float|bool>  $props  Flat, up to 20 keys, string or number values.
     * @param  array{amount: int|float|string, currency: string}|null  $revenue  Amount in major units (4.99).
     * @param  array<string, mixed>|null  $context  Same keys as pageview(): url, referrer, ip, user_agent, host,
     *                                              visitor, country, user, ts. With neither ip nor visitor (a
     *                                              webhook, a job), the event has no visitor field at all.
     *                                              runomine attributes it through user.
     * @return array<string, mixed>|null The queued event, or null when disabled or invalid.
     */
    public function track(
        string $name,
        array $props = [],
        ?array $revenue = null,
        ?string $user = null,
        ?array $context = null,
    ): ?array {
        try {
            if (! $this->enabled || trim($name) === '') {
                return null;
            }
            $context ??= [];
            if ($user !== null && $user !== '') {
                $context['user'] = $user;
            }

            $event = $this->event('event', trim($name), $context, withUserAgent: false);
            if ($revenue !== null && ($money = self::revenue($revenue)) !== null) {
                $event['revenue'] = $money;
            }
            if (($props = self::props($props)) !== []) {
                $event['props'] = $props;
            }

            return $this->push($event);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Send one sample of the app's own ops numbers (queue depth, CPU, people on the site right now) to
     * POST /api/v1/samples, for runomine's Live page. Nested arrays flatten to dotted keys there ("queue.pending"),
     * booleans count as 0/1 and anything that isn't a number is dropped. One sample per second: sending the same ts
     * again replaces it.
     *
     * Sent at once, not queued (samples come from a scheduler, a few a minute), with the same retries as events.
     * Returns true when runomine took the request, false when disabled or it failed. Never throws.
     *
     * @param  array<string, mixed>  $data
     * @param  DateTimeInterface|string|int|null  $ts  When it was measured (default now). Any age is fine.
     */
    public function sample(array $data, DateTimeInterface|string|int|null $ts = null): bool
    {
        return $this->samples([['data' => $data, 'ts' => $ts]]);
    }

    /**
     * Send several samples, e.g. a catch-up after an outage: each ['data' => [...], 'ts' => ...], in calls of at
     * most 500. True when runomine took every call (the ones it judges invalid are reported in its answer, not
     * here). Never throws.
     *
     * @param  iterable<array{data: array<string, mixed>, ts?: DateTimeInterface|string|int|null}>  $samples
     */
    public function samples(iterable $samples): bool
    {
        try {
            if (! $this->enabled) {
                return false;
            }
            $rows = [];
            foreach ($samples as $sample) {
                if (! is_array($sample) || ! is_array($sample['data'] ?? null)) {
                    continue;
                }
                $rows[] = ['ts' => $this->iso($sample['ts'] ?? null), 'data' => $sample['data'] === [] ? new \stdClass() : $sample['data']];
            }
            $ok = true;
            foreach (array_chunk($rows, self::MAX_BATCH) as $chunk) {
                $ok = $this->post($this->samplesEndpoint, ['samples' => $chunk]) && $ok;
            }

            return $ok;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Events queued and not sent yet.
     *
     * @return list<array<string, mixed>>
     */
    public function pending(): array
    {
        return $this->queue;
    }

    /**
     * Send everything queued, in batches of at most 500. Returns how many events runomine accepted the request for.
     * Never throws: a batch that still fails after the retries is dropped.
     */
    public function flush(): int
    {
        if ($this->flushing || $this->queue === []) {
            return 0;
        }

        $this->flushing = true;
        $sent = 0;

        try {
            $events = $this->queue;
            $this->queue = [];
            $this->queuedAt = null;

            foreach (array_chunk($events, $this->batchSize) as $batch) {
                if ($this->post($this->endpoint, ['events' => $batch])) {
                    $sent += count($batch);
                }
            }
        } catch (Throwable) {
            // Never break the app because of analytics.
        } finally {
            $this->flushing = false;
        }

        return $sent;
    }

    /** @param array<string, list<mixed>> $payload */
    private function post(string $url, array $payload): bool
    {
        $body = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION,
        );
        if ($body === false) {
            return false;
        }

        $headers = [
            'Authorization' => 'Bearer '.$this->token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'runomine-php/1',
        ];

        for ($attempt = 0; ; $attempt++) {
            try {
                $status = $this->sender->post($url, $headers, $body);
            } catch (Throwable) {
                $status = 0;
            }

            if ($status >= 200 && $status < 300) {
                return true;
            }
            $retryable = $status === 429 || $status >= 500;
            if (! $retryable || $attempt >= $this->retries) {
                return false; // 4xx, network failure, or out of retries: drop quietly.
            }

            try {
                ($this->sleep)(0.2 * (2 ** $attempt)); // 0.2s, 0.4s
            } catch (Throwable) {
            }
        }
    }

    /** @param array<string, mixed> $event */
    private function push(array $event): array
    {
        $this->queue[] = $event;
        $this->queuedAt ??= microtime(true);

        if (count($this->queue) >= $this->batchSize
            || ($this->flushInterval > 0 && microtime(true) - $this->queuedAt >= $this->flushInterval)) {
            $this->flush();
        }

        return $event;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function event(string $type, ?string $name, array $data, bool $withUserAgent): array
    {
        $ts = $this->iso($data['ts'] ?? null);

        $url = self::str($data['url'] ?? null);
        $ua = self::str($data['user_agent'] ?? null);
        $visitor = self::str($data['visitor'] ?? null);

        if ($visitor === null && ($ip = self::str($data['ip'] ?? null)) !== null) {
            $host = self::str($data['host'] ?? null)
                ?? ($url !== null ? self::str(parse_url($url, PHP_URL_HOST)) : null)
                ?? '';
            $visitor = $this->visitor($ip, $ua ?? '', $host);
        }

        $country = strtoupper((string) self::str($data['country'] ?? null));

        $event = [
            'id' => Uuid::v7(),
            'type' => $type,
            'name' => $name,
            'ts' => $ts,
            'url' => $url,
            'referrer' => self::str($data['referrer'] ?? null),
            'user_agent' => $withUserAgent ? $ua : null,
            'visitor' => $visitor,
            'country' => preg_match('/^[A-Z]{2}$/', $country) && $country !== 'XX' ? $country : null,
            'user' => self::str($data['user'] ?? null),
        ];

        return array_filter($event, static fn ($v) => $v !== null);
    }

    /** @return array{amount: float, currency: string}|null */
    private static function revenue(array $revenue): ?array
    {
        $amount = $revenue['amount'] ?? null;
        $currency = strtoupper(trim((string) ($revenue['currency'] ?? '')));

        if (! is_numeric($amount) || $currency === '') {
            return null;
        }

        return ['amount' => (float) $amount, 'currency' => $currency];
    }

    /**
     * Keep it flat: string keys with string or number values. Booleans become "true"/"false"; nulls, arrays and
     * objects are dropped.
     *
     * @return array<string, string|int|float>
     */
    private static function props(array $props): array
    {
        $flat = [];
        foreach ($props as $key => $value) {
            if (! is_string($key) || $key === '') {
                continue;
            }
            if (is_bool($value)) {
                $flat[$key] = $value ? 'true' : 'false';
            } elseif (is_int($value) || is_float($value) || is_string($value)) {
                $flat[$key] = $value;
            } elseif ($value instanceof \Stringable) {
                $flat[$key] = (string) $value;
            } elseif ($value instanceof \BackedEnum) {
                $flat[$key] = $value->value;
            }
        }

        return $flat;
    }

    /** A time as UTC ISO 8601 with milliseconds; now when missing or unreadable. */
    private function iso(mixed $ts): string
    {
        $ts = self::time($ts) ?? ($this->clock)();

        return DateTimeImmutable::createFromInterface($ts)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    private static function time(mixed $ts): ?DateTimeInterface
    {
        try {
            return match (true) {
                $ts instanceof DateTimeInterface => $ts,
                is_int($ts) || is_float($ts) => (new DateTimeImmutable('@'.(int) $ts)),
                is_string($ts) && $ts !== '' => new DateTimeImmutable($ts),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    private static function str(mixed $value): ?string
    {
        if ($value === null || $value === false || is_array($value) || (is_object($value) && ! $value instanceof \Stringable)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function env(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function bool(?string $value): ?bool
    {
        return $value === null ? null : filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    }
}
