<?php

declare(strict_types=1);

namespace Runomine\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Runomine\Laravel\Testing\RomFake;
use Runomine\Laravel\Tracker;

/**
 * Rom::track('purchase', ['plan' => 'rent'], ['amount' => 4.99, 'currency' => 'EUR']);
 *
 * @method static array|null track(string $name, array $props = [], ?array $revenue = null, string|int|null $user = null, ?array $context = null)
 * @method static array|null pageview(?\Illuminate\Http\Request $request = null, array $overrides = [])
 * @method static string|null visitor(?\Illuminate\Http\Request $request = null)
 * @method static array<string, string> checkoutMetadata(string|int|null $user = null)
 * @method static int flush()
 * @method static bool isEnabled()
 * @method static \Runomine\Client client()
 * @method static \Runomine\Laravel\Tracker resolveUserUsing(?callable $resolver)
 *
 * @see Tracker
 */
class Rom extends Facade
{
    /** Swap runomine for an in-memory fake that records events and offers assertions. Nothing is sent. */
    public static function fake(): RomFake
    {
        $fake = RomFake::make(static::getFacadeApplication());
        static::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return Tracker::class;
    }
}
