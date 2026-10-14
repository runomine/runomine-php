<?php

return [

    // The site's server token, from runomine's /app/sites. Without it nothing is sent.
    'token' => env('RUNOMINE_TOKEN'),

    'url' => env('RUNOMINE_URL', 'https://runomine.com'),

    // null: enabled when the token is set. Set RUNOMINE_ENABLED=false to switch it off (tests, local).
    'enabled' => env('RUNOMINE_ENABLED'),

    // Add Runomine\Laravel\TrackPageViews to the "web" middleware group automatically. Set false to place it yourself.
    'middleware' => env('RUNOMINE_MIDDLEWARE', true),

    // Paths never recorded as pageviews ($request->is() patterns). Only GET 200 text/html responses count anyway.
    'skip' => [
        'api/*',
        'up',
        'livewire/*',
        'build/*',
        'storage/*',
        'horizon*',
        'telescope*',
        'pulse*',
        '_debugbar/*',
        'sanctum/*',
    ],

    // Who is logged in, sent as the event's "user" (your own id, as text). null: the authenticated user's id.
    // false: never send it. Or an invokable class name: __invoke(?Illuminate\Http\Request $request): string|int|null.
    // At runtime you can also call Rom::resolveUserUsing(fn ($request) => ...).
    'user' => null,

    // The visitor hash uses $request->ip(), so configure trusted proxies. If your app sits behind Cloudflare and you
    // don't, set this to CF-Connecting-IP (only then: the header is trivially spoofable otherwise).
    'ip_header' => env('RUNOMINE_IP_HEADER'),

    // Seconds for the HTTP call to runomine. It happens after the response is sent.
    'timeout' => 2.0,

];
