<?php

declare(strict_types=1);

namespace Runomine\Laravel;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Records a pageview for every GET answered 200 with text/html (minus the skipped paths and prefetches), after the
 * response has been sent, then flushes the queue. Added to the "web" group automatically unless
 * config('runomine.middleware') is false. A controller attaches props to its page view with
 * $request->attributes->set('runomine.props', ['section' => 'news']).
 */
class TrackPageViews
{
    public function handle(Request $request, Closure $next): mixed
    {
        return $next($request);
    }

    public function terminate(Request $request, mixed $response): void
    {
        try {
            $tracker = app(Tracker::class);

            if ($response instanceof Response
                && ! $request->attributes->get('runomine.pageview')
                && $tracker->shouldRecordPageview($request, $response)) {
                $request->attributes->set('runomine.pageview', true); // once, even if registered twice
                // Props a controller set for this page: $request->attributes->set('runomine.props', [...]).
                $props = $request->attributes->get('runomine.props');
                $tracker->pageview($request, is_array($props) ? ['props' => $props] : []);
            }

            $tracker->flush();
        } catch (Throwable) {
            // Analytics never breaks a request.
        }
    }
}
