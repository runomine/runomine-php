<?php

declare(strict_types=1);

namespace Runomine\Laravel;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Runomine\Client;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * What the Rom facade points at: the Client plus the current request. Inside an HTTP request, track() attaches the
 * visitor hash, the URL, the country and the logged-in user. In queues and the console there is no visitor, and the
 * user is whoever the app says (or nobody).
 */
class Tracker
{
    /** @var (callable(?Request): (string|int|null))|null */
    private $userResolver = null;

    private ?Request $request = null;

    public function __construct(
        protected Client $client,
        protected Container $app,
    ) {
    }

    /**
     * @param  array<string, string|int|float|bool>  $props
     * @param  array{amount: int|float|string, currency: string}|null  $revenue
     * @param  array<string, mixed>|null  $context  Overrides: url, referrer, visitor, country, user, ts, ip, user_agent.
     * @return array<string, mixed>|null
     */
    public function track(
        string $name,
        array $props = [],
        ?array $revenue = null,
        string|int|null $user = null,
        ?array $context = null,
    ): ?array {
        try {
            $request = $this->currentRequest();
            $base = $request ? $this->fromRequest($request) : ['user' => $this->user(null)];
            unset($base['referrer']); // an event's referrer is just the previous page of the form; not useful

            $context = array_merge($base, $context ?? []);

            return $this->client->track($name, $props, $revenue, $user === null ? null : (string) $user, $context);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Record the request as a pageview. The middleware does this for you; call it yourself for pages it doesn't see
     * (a redirect that is really a landing, say).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>|null
     */
    public function pageview(?Request $request = null, array $overrides = []): ?array
    {
        try {
            $request ??= $this->currentRequest();
            if ($request === null) {
                return null;
            }

            return $this->client->pageview(array_merge($this->fromRequest($request), $overrides));
        } catch (Throwable) {
            return null;
        }
    }

    /** The current request's visitor hash, or null outside an HTTP request. Handy to pass on to a queued job. */
    public function visitor(?Request $request = null): ?string
    {
        try {
            $request ??= $this->currentRequest();
            if ($request === null || ! $this->client->isEnabled()) {
                return null;
            }
            $ip = $this->ip($request);

            return $ip === null ? null : $this->client->visitor($ip, (string) $request->userAgent(), $request->getHost());
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * What to pass to your payment provider when creating a checkout, so runomine can attribute the sale:
     * runomine_visitor (this request's visitor hash) and runomine_user ($user, else the logged-in user). Only keys
     * with a value; outside an HTTP request or without a token there is no visitor key. Never throws.
     *
     * @return array<string, string>
     */
    public function checkoutMetadata(string|int|null $user = null): array
    {
        $metadata = [];

        try {
            $request = $this->currentRequest();
            if (($visitor = $this->visitor($request)) !== null) {
                $metadata['runomine_visitor'] = $visitor;
            }
            $user = $user === null || $user === '' ? $this->user($request) : (string) $user;
            if ($user !== null && $user !== '') {
                $metadata['runomine_user'] = $user;
            }
        } catch (Throwable) {
        }

        return $metadata;
    }

    public function flush(): int
    {
        return $this->client->flush();
    }

    public function client(): Client
    {
        return $this->client;
    }

    public function isEnabled(): bool
    {
        return $this->client->isEnabled();
    }

    /** @param (callable(?Request): (string|int|null))|null $resolver */
    public function resolveUserUsing(?callable $resolver): static
    {
        $this->userResolver = $resolver;

        return $this;
    }

    /** GET, answered 200 with text/html, not a skipped path, not a prefetch. */
    public function shouldRecordPageview(Request $request, Response $response): bool
    {
        if (! $this->isEnabled() || ! $request->isMethod('GET') || $response->getStatusCode() !== 200) {
            return false;
        }
        if (! str_contains(strtolower((string) $response->headers->get('Content-Type')), 'text/html')) {
            return false;
        }
        $skip = (array) $this->app['config']->get('runomine.skip', []);
        if ($skip !== [] && $request->is(...$skip)) {
            return false;
        }
        foreach (['Sec-Purpose', 'Purpose', 'X-Purpose', 'X-Moz'] as $header) {
            if (preg_match('/prefetch|preview/i', (string) $request->headers->get($header))) {
                return false;
            }
        }

        return true;
    }

    /**
     * The HTTP request being handled, or null in the console, queue workers and after the request has ended. The
     * service provider sets it when a route matches and clears it when the app terminates.
     */
    public function currentRequest(): ?Request
    {
        return $this->request;
    }

    public function setRequest(?Request $request): void
    {
        $this->request = $request;
    }

    /** @return array<string, mixed> */
    protected function fromRequest(Request $request): array
    {
        return [
            'url' => $request->fullUrl(),
            'referrer' => $request->headers->get('referer'),
            'user_agent' => $request->userAgent(),
            'ip' => $this->ip($request),          // only for the hash; Client never sends it
            'host' => $request->getHost(),
            'country' => $request->headers->get('CF-IPCountry') ?? $request->headers->get('CloudFront-Viewer-Country'),
            'user' => $this->user($request),
        ];
    }

    protected function ip(Request $request): ?string
    {
        $header = $this->app['config']->get('runomine.ip_header');
        if (is_string($header) && $header !== '' && ($value = trim((string) $request->headers->get($header))) !== '') {
            return trim(explode(',', $value)[0]);
        }

        return $request->ip();
    }

    protected function user(?Request $request): ?string
    {
        try {
            $config = $this->app['config']->get('runomine.user');
            $resolver = $this->userResolver;

            if ($resolver === null && $config === false) {
                return null;
            }
            if ($resolver === null && is_string($config) && $config !== '') {
                $resolver = $this->app->make($config);
            }

            if ($resolver !== null) {
                $id = $resolver($request);
            } elseif ($request !== null) {
                $id = $request->user()?->getAuthIdentifier();
            } else {
                $auth = $this->app['auth'];
                $id = $auth->hasUser() ? $auth->user()?->getAuthIdentifier() : null;
            }

            return $id === null || $id === '' ? null : (string) $id;
        } catch (Throwable) {
            return null;
        }
    }
}
