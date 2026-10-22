<?php
declare(strict_types=1);
namespace Tesco\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tesco\{ApiError, Product};

final class ProductTest extends TestCase
{
    #[DataProvider('malformedProducts')]
    public function testMalformedUpstreamFieldsBecomeControlledErrors(array $node): void
    {
        $this->expectException(ApiError::class);
        Product::normalize($node, '2026-10-22T08:00:00Z');
    }

    public static function malformedProducts(): iterable
    {
        foreach ([
            ['id' => []],
            ['sellers' => 'invalid'],
            ['sellers' => ['results' => 'invalid']],
            ['defaultImageUrl' => []],
            ['charges' => ['invalid']],
            ['sellers' => ['results' => [['promotions' => 'invalid']]]],
            ['sellers' => ['results' => [['promotions' => [['description' => []]]]]]],
        ] as $change) {
            yield [array_replace(Fixtures::node(), $change)];
        }
    }
}
