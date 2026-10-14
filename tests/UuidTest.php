<?php

declare(strict_types=1);

namespace Runomine\Tests;

use PHPUnit\Framework\TestCase;
use Runomine\Uuid;

final class UuidTest extends TestCase
{
    public function test_it_is_a_v7_uuid_carrying_the_time(): void
    {
        $id = Uuid::v7(1_791_916_445_123); // 2026-10-13T18:34:05.123Z

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id);
        self::assertSame(1_791_916_445_123, hexdec(str_replace('-', '', substr($id, 0, 13))));
    }

    public function test_ids_are_unique_and_sort_by_time(): void
    {
        $earlier = Uuid::v7(1_000);
        $later = Uuid::v7(2_000);
        self::assertLessThan(0, strcmp($earlier, $later));

        $ids = [];
        for ($i = 0; $i < 1000; $i++) {
            $ids[] = Uuid::v7();
        }
        self::assertCount(1000, array_unique($ids));
    }
}
