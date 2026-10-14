<?php

declare(strict_types=1);

namespace Runomine\Laravel;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\ServiceProvider;
use Runomine\Client;
use Runomine\Http\CurlSender;
use Throwable;

class RunomineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/runomine.php', 'runomine');

        $this->app->singleton(Client::class, function ($app) {
            $config = $app['config']->get('runomine', []);
            $enabled = $config['enabled'] ?? null;

            return new Client(
                token: (string) ($config['token'] ?? ''),
                url: (string) ($config['url'] ?? Client::DEFAULT_URL),
                enabled: $enabled === null || $enabled === '' ? null : filter_var($enabled, FILTER_VALIDATE_BOOL),
                sender: new CurlSender((float) ($config['timeout'] ?? 2.0)),
            );
        });

        $this->app->singleton(Tracker::class, fn ($app) => new Tracker($app->make(Client::class), $app));
        $this->app->alias(Tracker::class, 'runomine');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../../config/runomine.php' => $this->app->configPath('runomine.php')], 'runomine-config');
        }

        if (filter_var($this->app['config']->get('runomine.middleware', true), FILTER_VALIDATE_BOOL)) {
            $this->registerMiddleware();
        }

        // Rom::track() attaches the visitor only while an HTTP request is being handled.
        $this->app['events']->listen(RouteMatched::class, function (RouteMatched $event): void {
            $this->app->make(Tracker::class)->setRequest($event->request);
        });

        // Send what's left at the end of every request, command and queued job.
        $flush = function (): void {
            if ($this->app->resolved(Tracker::class)) {
                $this->app->make(Tracker::class)->flush();
            }
        };
        $this->app->terminating(function () use ($flush): void {
            $flush();
            if ($this->app->resolved(Tracker::class)) {
                $this->app->make(Tracker::class)->setRequest(null);
            }
        });
        $this->app['events']->listen([JobProcessed::class, JobFailed::class, JobExceptionOccurred::class], $flush);
    }

    private function registerMiddleware(): void
    {
        $push = static function ($kernel): void {
            try {
                if (method_exists($kernel, 'hasMiddleware') && method_exists($kernel, 'appendMiddlewareToGroup')
                    && ! in_array(TrackPageViews::class, $kernel->getMiddlewareGroups()['web'] ?? [], true)) {
                    $kernel->appendMiddlewareToGroup('web', TrackPageViews::class);
                }
            } catch (Throwable) {
                // No "web" group: the app can add the middleware itself.
            }
        };

        if ($this->app->resolved(HttpKernel::class)) {
            $push($this->app->make(HttpKernel::class));
        } else {
            $this->app->afterResolving(HttpKernel::class, $push);
        }
    }
}
