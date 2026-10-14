<?php

declare(strict_types=1);

namespace Runomine\Tests;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Runomine\VisitorHash;

final class VisitorHashTest extends TestCase
{
    public const TOKEN = 'rm_live_test_0123456789abcdef';

    public const IP = '203.0.113.9';

    public const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 Version/18.0 Safari/605.1.15';

    public const SALT = 'fd5d804a4a60e713ada2bcef694204ccfece37b96dfff60153236f075014f883';

    public const VISITOR = '5f9e1bebe31e8bf5';

    public function test_contract_vector(): void
    {
        $day = new DateTimeImmutable('2026-10-13', new DateTimeZone('UTC'));

        self::assertSame(self::SALT, VisitorHash::salt(self::TOKEN, $day));
        self::assertSame(self::VISITOR, VisitorHash::compute(self::TOKEN, self::IP, self::UA, 'example.com', $day));
    }

    public function test_host_is_lowercased_and_port_is_dropped(): void
    {
        $day = new DateTimeImmutable('2026-10-13T12:00:00Z');

        self::assertSame(self::VISITOR, VisitorHash::compute(self::TOKEN, self::IP, self::UA, 'EXAMPLE.com:8443', $day));
        self::assertSame('[::1]', VisitorHash::normalizeHost('[::1]:8080'));
        self::assertSame('::1', VisitorHash::normalizeHost('::1'));
    }

    public function test_the_day_is_the_utc_date(): void
    {
        // 2026-10-14 01:00 in Kiritimati (+14) is still 2026-10-13 in UTC.
        $local = new DateTimeImmutable('2026-10-14 01:00:00', new DateTimeZone('Pacific/Kiritimati'));

        self::assertSame(self::VISITOR, VisitorHash::compute(self::TOKEN, self::IP, self::UA, 'example.com', $local));
    }

    public function test_it_changes_with_the_day_and_the_token(): void
    {
        $day = new DateTimeImmutable('2026-10-14T00:00:00Z');

        self::assertNotSame(self::VISITOR, VisitorHash::compute(self::TOKEN, self::IP, self::UA, 'example.com', $day));
        self::assertNotSame(self::VISITOR, VisitorHash::compute('rm_live_other', self::IP, self::UA, 'example.com', new DateTimeImmutable('2026-10-13T00:00:00Z')));
    }
}
