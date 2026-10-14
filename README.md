# runomine-php

The PHP library of [runomine](https://runomine.com): cookieless analytics that counts money, not just visits.
It sends pageviews and business events (signups, purchases, revenue) **from your backend**, so the visitor's IP never
leaves your server, and nothing in the browser needs a cookie or a consent banner.

- Framework-agnostic `Runomine\Client`, plus a Laravel integration (service provider, middleware, `Rom` facade).
- PHP 8.2+, `ext-curl` and `ext-json`. No other runtime dependency.
- Never blocks the page and never throws: events are batched in memory and sent after the response.

## Install

From Packagist:

```sh
composer require runomine/runomine-php
```

Or straight from this repository:

```sh
composer config repositories.runomine '{"type":"vcs","url":"https://github.com/runomine/runomine-php"}'
composer require runomine/runomine-php:dev-main
```

### Laravel (10 and up)

```sh
composer require runomine/runomine-php    # or :dev-main from the repository, as above
echo "RUNOMINE_TOKEN=rm_live_..." >> .env
```

That's all. The package is auto-discovered, adds `Runomine\Laravel\TrackPageViews` to the `web` middleware group,
and starts recording pageviews. Then track what matters:

```php
use Rom; // or Runomine\Laravel\Facades\Rom

Rom::track('signup');

Rom::track('purchase', ['plan' => 'rent', 'film' => 'the-third-man'], ['amount' => 4.99, 'currency' => 'EUR']);
```

Inside an HTTP request, `Rom::track()` attaches the visitor hash, the URL, the country and the logged-in user's id.
In queued jobs, commands and webhooks there is no request, so the event goes **without** a `visitor` field (never a
made-up one): pass the user so runomine can link the event to that person's earlier visits, or hand over the visitor
captured during the request with `Rom::visitor()`.

```php
// Your own payment webhook, for payments no connected provider sees (see "Revenue from your payment provider"):
// no browser behind it, but you know who paid.
Rom::track('purchase', ['plan' => 'yearly'], ['amount' => 49, 'currency' => 'EUR'], user: $order->user_id);

// Hand the visitor to a queued job when it should count as the same visit.
ProcessCheckout::dispatch($order, Rom::visitor());
// ...and in the job:
Rom::track('checkout_completed', context: ['visitor' => $this->visitor]);
```

Publish the config if you want to change the defaults:

```sh
php artisan vendor:publish --tag=runomine-config
```

### Plain PHP (no framework)

```php
require 'vendor/autoload.php';

$rom = new Runomine\Client(); // reads RUNOMINE_TOKEN, RUNOMINE_URL, RUNOMINE_ENABLED
// or: new Runomine\Client(token: 'rm_live_...');

// In your front controller, for HTML pages:
$rom->pageview([
    'url' => (($_SERVER['HTTPS'] ?? '') === 'on' ? 'https' : 'http').'://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'],
    'referrer' => $_SERVER['HTTP_REFERER'] ?? null,
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    'ip' => $_SERVER['REMOTE_ADDR'],            // hashed here, never sent
    'country' => $_SERVER['HTTP_CF_IPCOUNTRY'] ?? null,
    'user' => $_SESSION['user_id'] ?? null,
]);

// Anywhere:
$rom->track('purchase', ['plan' => 'rent'], ['amount' => 4.99, 'currency' => 'EUR'], user: '42', context: [
    'url' => 'https://example.com/checkout/success',
    'ip' => $_SERVER['REMOTE_ADDR'],
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
]);

// Optional: send now. Otherwise it flushes at 500 events, after 5 seconds, on destruct and at shutdown.
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request(); // let the visitor go first
}
$rom->flush();
```

Only record what the visitor actually saw: `GET` requests answered `200` with `text/html`. Long-running workers
should call `$rom->flush()` after each unit of work.

## Revenue from your payment provider

runomine takes revenue from your payment provider, not from prices in your code: connect Stripe, Paddle, Lemon
Squeezy, Shopify or PayPal in the site's settings → **Integrations**, and runomine reads every sale, renewal and
refund from it. To attribute a sale to the visit and the person behind it, pass two identifiers when you create the
checkout; runomine reads them back from the provider.

```php
Rom::checkoutMetadata();          // ['runomine_visitor' => '5f9e1bebe31e8bf5', 'runomine_user' => '42']
Rom::checkoutMetadata($team->id); // your own id instead of the logged-in user's

// Plain PHP: ip, user agent, host and user
$rom->checkoutMetadata($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] ?? '', $_SERVER['HTTP_HOST'], $userId);
```

`runomine_visitor` is this request's visitor hash and `runomine_user` your user id, as text. Only keys with a value
are returned: outside an HTTP request or without a token there is no visitor (never a made-up one), and with nobody
logged in there is no user.

Once a provider is connected, **don't also send `revenue`** with `Rom::track('purchase', …)`: it would be counted
twice. Send the event without revenue, or not at all. (The webhook examples above are for payments that don't go
through a connected provider.)

**Stripe Checkout**: `client_reference_id` is used as the user when `runomine_user` is missing. For subscriptions,
also put it on the subscription so renewals carry the user.

```php
$metadata = Rom::checkoutMetadata();

$session = $stripe->checkout->sessions->create([
    'mode' => 'subscription',
    'line_items' => [['price' => 'price_123', 'quantity' => 1]],
    'success_url' => route('billing.done'),
    'client_reference_id' => (string) $user->id,
    'metadata' => $metadata,
    'subscription_data' => ['metadata' => $metadata], // renewals
]);

// Without Checkout, on the PaymentIntent:
$stripe->paymentIntents->create(['amount' => 499, 'currency' => 'eur', 'metadata' => $metadata]);
```

**Paddle Billing**: render the values into the page and pass them as `customData` to Paddle.js, or as `custom_data`
when you create the transaction through the API.

```blade
<script>
    Paddle.Checkout.open({ items: [{ priceId: 'pri_123', quantity: 1 }], customData: @json(Rom::checkoutMetadata()) });
</script>
```

**Lemon Squeezy**: `checkout_data.custom` when you create the checkout through the API, or URL parameters on a
checkout link.

```php
'attributes' => ['checkout_data' => ['custom' => Rom::checkoutMetadata()]],

// or a link:
$url = 'https://store.lemonsqueezy.com/checkout/buy/VARIANT?'.http_build_query(['checkout' => ['custom' => Rom::checkoutMetadata()]]);
```

**Shopify** (headless, Storefront API cart): cart attributes, which become the order's note attributes.

```php
$attributes = collect(Rom::checkoutMetadata())->map(fn ($value, $key) => ['key' => $key, 'value' => $value])->values();
// cartCreate(input: { lines: [...], attributes: $attributes })
```

**PayPal Orders v2**: `custom_id` on the purchase unit, as a query string (127 characters at most).

```php
'purchase_units' => [[
    'amount' => ['currency_code' => 'EUR', 'value' => '4.99'],
    'custom_id' => http_build_query(Rom::checkoutMetadata()),
]],
```

## What is sent, and privacy

Each event goes to `POST https://runomine.com/api/v1/events` with your token as a bearer:

| field | |
|---|---|
| `id` | A UUID v7 made here. runomine stores an event once even if it's sent twice. |
| `type`, `name`, `ts` | `pageview` or `event`, the event's name, and when it happened. |
| `url`, `referrer` | The page and where the visitor came from. runomine keeps the path, `utm_*`/`ref` and the referrer's host. |
| `user_agent` | Pageviews only. Used for the bot filter and device/browser/OS families, then discarded. |
| `visitor` | A daily hash, computed **on your server**. Required for pageviews (one without an IP or visitor is dropped); left out of events with no request behind them. |
| `country` | From `CF-IPCountry` or `CloudFront-Viewer-Country`, when your CDN sends it. |
| `user` | Your own user id, as text, when someone is logged in. |
| `revenue`, `props` | `{amount, currency}` in major units, and flat string/number properties. |

**The IP never leaves your server.** The visitor hash is:

```
salt    = hex(HMAC-SHA256(key = token, message = "runomine:" + UTC date))
visitor = first 16 hex chars of SHA256(salt + "|" + ip + "|" + user_agent + "|" + host)
```

so a person counts once per day and site, and can't be followed across days or sites. It is the same value runomine's
JS beacon produces, so a site using both counts each person once. `Runomine\VisitorHash::compute()` is public if you
need it.

The library does no classification: no source, bot or device logic. runomine does that, once, for every library.

### Behind a proxy or CDN

The hash uses `$request->ip()`, so configure Laravel's trusted proxies (`$middleware->trustProxies(at: '*')` in
`bootstrap/app.php` when your platform's proxy is the only way in). Without that, every visitor looks like your load
balancer. If you are behind Cloudflare and can't configure trusted proxies, set `RUNOMINE_IP_HEADER=CF-Connecting-IP`
(only then: the header is easy to spoof otherwise).

## Configuration

Environment variables (both the plain client and Laravel read them):

| variable | default | |
|---|---|---|
| `RUNOMINE_TOKEN` | | The site's server token (`rm_live_...`). Required: without it nothing is sent. |
| `RUNOMINE_URL` | `https://runomine.com` | |
| `RUNOMINE_ENABLED` | true when the token is set | `false` switches everything off. |
| `RUNOMINE_MIDDLEWARE` | `true` | Laravel: add the pageview middleware to the `web` group. |
| `RUNOMINE_IP_HEADER` | | Laravel: read the client IP from this header instead of `$request->ip()`. |

`config/runomine.php` (Laravel):

| key | default | |
|---|---|---|
| `token`, `url`, `enabled` | from the env | |
| `middleware` | `true` | Set `false` and add `\Runomine\Laravel\TrackPageViews::class` yourself where you want it. |
| `skip` | `api/*`, `up`, `livewire/*`, `build/*`, `storage/*`, `horizon*`, `telescope*`, `pulse*`, `_debugbar/*`, `sanctum/*` | `$request->is()` patterns never recorded as pageviews. Add your admin, e.g. `admin/*`. |
| `user` | `null` | `null`: the authenticated user's id. `false`: never send it. Or an invokable class: `__invoke(?Request $request): string\|int\|null`. |
| `ip_header` | `null` | See above. |
| `timeout` | `2.0` | Seconds for the call to runomine (made after the response is sent). |

At runtime: `Rom::resolveUserUsing(fn (?Request $request) => $request?->user()?->account_id);`

The middleware records a pageview when the request is a `GET`, the answer is `200` with `text/html`, the path isn't
skipped and it isn't a browser prefetch (`Sec-Purpose`/`Purpose: prefetch`). It does so in `terminate()`, after the
response has been sent, and flushes there. Events tracked in commands and queued jobs are flushed when the command
or job finishes. Inertia visits and other client-side navigations answer JSON, so they aren't seen here; add the beacon
for those.

### Client options

`new Runomine\Client(token:, url:, enabled:, sender:, batchSize: 500, retries: 2, flushInterval: 5.0, timeout: 2.0)`

- Batches are at most 500 events. `5xx` and `429` are retried twice (after 0.2 s and 0.4 s), then the batch is
  dropped. `4xx` and network errors are dropped at once. Nothing is ever thrown.
- `sender` is any `Runomine\Http\Sender` (one method: `post(url, headers, body): int`). The default uses ext-curl.

## Testing your app

### Laravel: `Rom::fake()`

```php
use Runomine\Laravel\Facades\Rom;

public function test_buying_tracks_a_purchase(): void
{
    $rom = Rom::fake();

    $this->actingAs($user)->post('/checkout', ['plan' => 'rent']);

    $rom->assertTracked('purchase', fn (array $event) => $event['revenue'] === ['amount' => 4.99, 'currency' => 'EUR']
        && $event['props']['plan'] === 'rent'
        && $event['user'] === (string) $user->id);
    $rom->assertTrackedTimes('purchase', 1);
    $rom->assertNotTracked('refund');
}

public function test_the_home_page_counts(): void
{
    $rom = Rom::fake();

    $this->get('/')->assertOk();

    $rom->assertPageviewRecorded(fn (array $event) => str_ends_with($event['url'], '/'));
}
```

Also: `assertNothingTracked()`, `assertNoPageviews()`, and `events()`, `tracked('name')`, `pageviews()` to inspect the
exact payloads that would have been sent. Nothing leaves the test. To switch runomine off in tests without faking it,
set `RUNOMINE_ENABLED=false` in `phpunit.xml`.

### Plain PHP: `FakeSender`

```php
$sender = new Runomine\Testing\FakeSender();          // or new FakeSender([503, 202]) to script answers
$client = new Runomine\Client(token: 'rm_live_test', sender: $sender);

$client->track('signup');
$client->flush();

$sender->events();   // the events, as sent
$sender->requests(); // url, headers, body of every call
```

## Developing this package

```sh
docker run --rm -v "$PWD":/app -w /app composer:2 sh -c "composer install -q && vendor/bin/phpunit"
```

The tests include the contract's visitor-hash vector, the payload shape, batching, retries, and the Laravel middleware
and facade (with Orchestra Testbench).

## License

MIT. See [LICENSE](LICENSE).
