<?php
declare(strict_types=1);
namespace Tesco\Tests;
use Tesco\Product;
final class Fixtures
{
    public static function node(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/fixtures/product.json'), true, 64, JSON_THROW_ON_ERROR);
    }
    public static function product(): array
    {
        return Product::normalize(self::node(), '2026-10-22T08:00:00Z');
    }
}
