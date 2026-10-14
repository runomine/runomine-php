<?php

declare(strict_types=1);

namespace Runomine\Tests\Laravel;

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\AssertionFailedError;
use Runomine\Client;
use Runomine\Laravel\Facades\Rom;
use Runomine\Laravel\RunomineServiceProvider;
use Runomine\Laravel\TrackPageViews;
use Runomine\Laravel\Tracker;
use Runomine\Testing\FakeSender;
use Runomine\Tests\VisitorHashTest;
use Runomine\VisitorHash;

final class LaravelTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [RunomineServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Rom' => Rom::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        // Testbench applies #[DefineEnvironment] before this method, so the token-less case is decided here.
        $app['config']->set('runomine.token', $this->name() === 'test_without_a_token_nothing_happens' ? null : VisitorHashTest::TOKEN);
    }

    protected function romWithoutAutoMiddleware($app): void
    {
        $app['config']->set('runomine.middleware', false);
    }

    protected function romWithoutUser($app): void
    {
        $app['config']->set('runomine.user', false);
    }

    protected function defineRoutes($router): void
    {
        Route::middleware('web')->group(function () {
            Route::get('/', fn () => '<h1>home</h1>');
            Route::get('/pricing', fn () => '<h1>pricing</h1>');
            Route::get('/json', fn () => ['ok' => true]);
            Route::get('/go', fn () => redirect('/'));
            Route::get('/api/page', fn () => '<h1>api but html</h1>');
            Route::get('/up', fn () => '<h1>up</h1>');
            Route::post('/form', fn () => '<h1>posted</h1>');
            Route::get('/buy', function () {
                Rom::track('purchase', ['plan' => 'rent'], ['amount' => 4.99, 'currency' => 'EUR']);

                return response()->json(['ok' => true]);
            });
        });
    }

    private function browse(string $uri, array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->flushHeaders()->withServerVariables(['REMOTE_ADDR' => VisitorHashTest::IP])
            ->withHeaders(['User-Agent' => VisitorHashTest::UA] + $headers)
            ->get($uri);
    }

    public function test_the_middleware_joins_the_web_group(): void
    {
        $groups = $this->app->make(HttpKernel::class)->getMiddlewareGroups();

        self::assertContains(TrackPageViews::class, $groups['web']);
        self::assertContains(TrackPageViews::class, $this->app['router']->getMiddlewareGroups()['web']);
    }

    #[DefineEnvironment('romWithoutAutoMiddleware')]
    public function test_the_middleware_can_be_left_out(): void
    {
        self::assertNotContains(TrackPageViews::class, $this->app->make(HttpKernel::class)->getMiddlewareGroups()['web']);

        $rom = Rom::fake();
        $this->browse('/');
        $rom->assertNoPageviews();
    }

    public function test_a_page_is_recorded_after_the_response(): void
    {
        $rom = Rom::fake();

        $this->browse('http://example.com/pricing?utm_source=newsletter', [
            'Referer' => 'https://www.google.com/',
            'CF-IPCountry' => 'es',
        ])->assertOk();

        $views = $rom->pageviews();
        self::assertCount(1, $views);
        $view = $views[0];
        self::assertSame('http://example.com/pricing?utm_source=newsletter', $view['url']);
        self::assertSame('https://www.google.com/', $view['referrer']);
        self::assertSame(VisitorHashTest::UA, $view['user_agent']);
        self::assertSame('ES', $view['country']);
        self::assertSame(VisitorHash::compute(VisitorHashTest::TOKEN, VisitorHashTest::IP, VisitorHashTest::UA, 'example.com'), $view['visitor']);
        self::assertArrayNotHasKey('user', $view);
        self::assertArrayNotHasKey('ip', $view);
    }

    public function test_cloudfront_country_header(): void
    {
        $rom = Rom::fake();
        $this->browse('/', ['CloudFront-Viewer-Country' => 'FR']);

        $rom->assertPageviewRecorded(fn ($e) => $e['country'] === 'FR');
    }

    public function test_only_qualifying_requests_are_recorded(): void
    {
        $rom = Rom::fake();

        $this->browse('/json')->assertOk();                                  // not text/html
        $this->browse('/go')->assertRedirect();                              // not 200
        $this->browse('/missing')->assertNotFound();                         // not 200
        $this->browse('/api/page')->assertOk();                              // skipped path
        $this->browse('/up')->assertOk();                                    // skipped path
        $this->browse('/', ['Sec-Purpose' => 'prefetch'])->assertOk();       // prefetch
        $this->browse('/', ['Purpose' => 'prefetch'])->assertOk();           // prefetch
        $this->withServerVariables(['REMOTE_ADDR' => VisitorHashTest::IP])->post('/form')->assertOk(); // not GET

        $rom->assertNoPageviews();

        $this->browse('/')->assertOk();
        self::assertCount(1, $rom->pageviews());
    }

    public function test_the_logged_in_user_is_attached(): void
    {
        $rom = Rom::fake();

        $this->actingAs(new GenericUser(['id' => 42]));
        $this->browse('/');

        $rom->assertPageviewRecorded(fn ($e) => ($e['user'] ?? null) === '42');
    }

    #[DefineEnvironment('romWithoutUser')]
    public function test_the_user_can_be_switched_off(): void
    {
        $rom = Rom::fake();

        $this->actingAs(new GenericUser(['id' => 42]));
        $this->browse('/');

        $rom->assertPageviewRecorded(fn ($e) => ! isset($e['user']));
    }

    public function test_a_custom_user_resolver(): void
    {
        $rom = Rom::fake();
        Rom::resolveUserUsing(fn (?Request $request) => 'acct_9');

        $this->browse('/');
        Rom::track('signup');

        $rom->assertPageviewRecorded(fn ($e) => $e['user'] === 'acct_9');
        $rom->assertTracked('signup', fn ($e) => $e['user'] === 'acct_9');
    }

    public function test_track_inside_a_request_attaches_the_visitor_url_and_user(): void
    {
        $rom = Rom::fake();
        $this->actingAs(new GenericUser(['id' => 7]));

        $this->browse('http://example.com/buy', ['CF-IPCountry' => 'ES'])->assertOk();

        $visitor = VisitorHash::compute(VisitorHashTest::TOKEN, VisitorHashTest::IP, VisitorHashTest::UA, 'example.com');
        $rom->assertTracked('purchase', fn ($e) => $e['visitor'] === $visitor
            && $e['url'] === 'http://example.com/buy'
            && $e['user'] === '7'
            && $e['country'] === 'ES'
            && $e['revenue'] === ['amount' => 4.99, 'currency' => 'EUR']
            && $e['props'] === ['plan' => 'rent']
            && ! isset($e['user_agent'], $e['referrer']));
        $rom->assertTrackedTimes('purchase', 1);
        $rom->assertNoPageviews(); // /buy answered JSON
    }

    public function test_track_outside_a_request_has_no_visitor(): void
    {
        $rom = Rom::fake();

        Rom::track('renewal', ['plan' => 'yearly'], ['amount' => 49, 'currency' => 'EUR'], 42);
        Rom::track('cron_ran');

        $rom->assertTracked('renewal', fn ($e) => ! array_key_exists('visitor', $e) && ! isset($e['url']) && $e['user'] === '42');
        $rom->assertTracked('cron_ran', fn ($e) => ! isset($e['user']));
        self::assertNull(Rom::visitor());
    }

    public function test_a_visitor_captured_in_the_request_can_be_passed_on(): void
    {
        $rom = Rom::fake();
        $captured = null;
        Route::middleware('web')->get('/checkout', function () use (&$captured) {
            $captured = Rom::visitor();

            return '<h1>checkout</h1>';
        });

        $this->browse('http://example.com/checkout')->assertOk();
        $expected = VisitorHash::compute(VisitorHashTest::TOKEN, VisitorHashTest::IP, VisitorHashTest::UA, 'example.com');
        self::assertSame($expected, $captured);

        // Later, in a job or webhook: no request, but the visitor was handed over.
        Rom::track('checkout_completed', [], ['amount' => 4.99, 'currency' => 'EUR'], 7, ['visitor' => $captured]);

        $rom->assertTracked('checkout_completed', fn ($e) => $e['visitor'] === $expected && $e['user'] === '7' && ! isset($e['url']));
    }

    public function test_checkout_metadata_inside_a_request(): void
    {
        Rom::fake();
        $this->actingAs(new GenericUser(['id' => 7]));
        $captured = [];
        Route::middleware('web')->get('/checkout', function () use (&$captured) {
            $captured[] = Rom::checkoutMetadata();
            $captured[] = Rom::checkoutMetadata(42);
            $captured[] = Rom::checkoutMetadata('acct_9');

            return '<h1>checkout</h1>';
        });

        $this->browse('http://example.com/checkout')->assertOk();

        $visitor = VisitorHash::compute(VisitorHashTest::TOKEN, VisitorHashTest::IP, VisitorHashTest::UA, 'example.com');
        self::assertSame(['runomine_visitor' => $visitor, 'runomine_user' => '7'], $captured[0]);
        self::assertSame(['runomine_visitor' => $visitor, 'runomine_user' => '42'], $captured[1]);
        self::assertSame(['runomine_visitor' => $visitor, 'runomine_user' => 'acct_9'], $captured[2]);
    }

    public function test_checkout_metadata_for_a_guest_and_outside_a_request(): void
    {
        Rom::fake();
        $captured = null;
        Route::middleware('web')->get('/checkout', function () use (&$captured) {
            $captured = Rom::checkoutMetadata();

            return '<h1>checkout</h1>';
        });

        $this->browse('http://example.com/checkout')->assertOk();
        $visitor = VisitorHash::compute(VisitorHashTest::TOKEN, VisitorHashTest::IP, VisitorHashTest::UA, 'example.com');
        self::assertSame(['runomine_visitor' => $visitor], $captured);

        // A job or the console: no visitor, never a made-up one.
        self::assertSame(['runomine_user' => '42'], Rom::checkoutMetadata(42));
        self::assertSame([], Rom::checkoutMetadata());
    }

    public function test_track_in_a_queued_job(): void
    {
        $rom = Rom::fake();

        dispatch(function () {
            Rom::track('job_done', ['kind' => 'export']);
        });

        $rom->assertTracked('job_done', fn ($e) => ! array_key_exists('visitor', $e));
    }

    #[DefineEnvironment('romWithoutAutoMiddleware')]
    public function test_a_pageview_recorded_by_hand_carries_props(): void
    {
        Route::middleware('web')->get('/news', function (Request $request) {
            Rom::pageview($request, ['props' => ['section' => 'news', 'area' => 'public']]);

            return '<h1>news</h1>';
        });
        $rom = Rom::fake();

        $this->browse('http://example.com/news')->assertOk();

        $rom->assertPageviewRecorded(fn (array $e) => $e['props'] === ['section' => 'news', 'area' => 'public']
            && $e['url'] === 'http://example.com/news'
            && $e['visitor'] === VisitorHash::compute(VisitorHashTest::TOKEN, VisitorHashTest::IP, VisitorHashTest::UA, 'example.com'));
        self::assertCount(1, $rom->pageviews());
    }

    public function test_the_middleware_sends_props_a_controller_set(): void
    {
        Route::middleware('web')->get('/section', function (Request $request) {
            $request->attributes->set('runomine.props', ['section' => 'news']);

            return '<h1>news</h1>';
        });
        $rom = Rom::fake();

        $this->browse('/section')->assertOk();
        $this->browse('/pricing')->assertOk();

        [$section, $pricing] = $rom->pageviews();
        self::assertSame(['section' => 'news'], $section['props']);
        self::assertArrayNotHasKey('props', $pricing);
    }

    public function test_samples_through_the_facade_and_the_fake(): void
    {
        $rom = Rom::fake();
        $rom->assertNothingSampled();

        self::assertTrue(Rom::sample(['queue' => ['pending' => 2], 'healthy' => true]));
        self::assertTrue(Rom::samples([['ts' => '2026-10-13T18:00:00Z', 'data' => ['cpu' => 0.25]]]));

        $rom->assertSampled(fn (array $s) => ($s['data']['healthy'] ?? null) === true)
            ->assertSampled(fn (array $s) => $s['ts'] === '2026-10-13T18:00:00.000Z' && $s['data'] === ['cpu' => 0.25]);
        self::assertCount(2, $rom->sampled());
        $rom->assertNothingTracked()->assertNoPageviews();

        $this->expectException(AssertionFailedError::class);
        $rom->assertSampled(fn (array $s) => isset($s['data']['missing']));
    }

    public function test_fake_assertions(): void
    {
        $rom = Rom::fake();
        $rom->assertNothingTracked();

        Rom::track('signup');

        $rom->assertTracked('signup')->assertNotTracked('purchase')->assertTrackedTimes('signup', 1);

        $this->expectException(AssertionFailedError::class);
        $rom->assertTracked('purchase');
    }

    public function test_the_real_client_sends_in_terminate(): void
    {
        $sender = new FakeSender();
        $this->app->instance(Client::class, new Client(token: VisitorHashTest::TOKEN, url: 'https://runomine.test', sender: $sender));
        $this->app->forgetInstance(Tracker::class);
        Rom::clearResolvedInstances();

        $this->browse('/')->assertOk();

        self::assertCount(1, $sender->requests());
        self::assertSame('https://runomine.test/api/v1/events', $sender->requests()[0]['url']);
        self::assertSame('pageview', $sender->events()[0]['type']);
    }

    public function test_a_failing_runomine_never_breaks_the_page(): void
    {
        $sender = new FakeSender([], 503);
        $this->app->instance(Client::class, new Client(token: VisitorHashTest::TOKEN, sender: $sender, sleep: fn () => null));
        $this->app->forgetInstance(Tracker::class);
        Rom::clearResolvedInstances();

        $this->browse('/')->assertOk()->assertSee('home', false);
        Rom::track('after');
        self::assertSame(0, Rom::flush());
        self::assertCount(6, $sender->requests()); // 3 attempts per flush, then dropped
    }

    public function test_without_a_token_nothing_happens(): void
    {
        self::assertFalse(Rom::isEnabled());
        self::assertNull(Rom::track('signup'));
        $this->browse('/')->assertOk();
        self::assertSame([], Rom::client()->pending());

        $this->actingAs(new GenericUser(['id' => 42]));
        self::assertSame(['runomine_user' => '42'], Rom::checkoutMetadata());
        self::assertSame(['runomine_user' => '7'], Rom::checkoutMetadata(7));
    }
}
