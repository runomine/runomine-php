# Changelog

## 0.2.0

- Page views take `props`, with the same rules as events (flat, up to 20 keys, string or number values):
  `$client->pageview([..., 'props' => ['section' => 'news']])`, `Rom::pageview($request, ['props' => [...]])`, or
  `$request->attributes->set('runomine.props', [...])` for the middleware's page view.
  runomine stores them as the page view's dims. `Rom::fake()` records them in the payload.
- Samples, an app's own ops numbers for runomine's Live page: `Client::sample(array $data, $ts = null)` and
  `Client::samples(iterable $samples)` (also `Rom::sample()` / `Rom::samples()`), sent at once to
  `POST /api/v1/samples`. `FakeSender::samples()`, and `sampled()`, `assertSampled()`, `assertNothingSampled()` on
  `Rom::fake()`.

## 0.1.0

- First release: framework-agnostic `Runomine\Client` (pageviews, events with props and revenue, batching, retries,
  the contract's visitor hash, checkout metadata) and the Laravel integration (service provider, `TrackPageViews`
  middleware, `Rom` facade, `Rom::fake()`).
