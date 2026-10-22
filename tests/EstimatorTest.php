<?php
declare(strict_types=1);
namespace Tesco\Tests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tesco\{ApiError, Estimator, Product, Money};

final class EstimatorTest extends TestCase
{
    public function testDuplicatesAreCombinedAndDepositsAreChargedPerPack(): void
    {
        $catalog = new FakeCatalog(['315853194' => Fixtures::product()]);
        $result = (new Estimator($catalog))->estimate([
            ['product_id' => '315853194', 'quantity' => 1],
            ['product_id' => '315853194', 'quantity' => 2],
        ]);
        self::assertTrue($result['complete']);
        self::assertSame(1020, $result['subtotal_cents']);
        self::assertSame(75, $result['deposit_cents']);
        self::assertSame(1095, $result['total_cents']);
        self::assertSame(1, $catalog->calls);
        self::assertCount(1, $result['items']);
        // The textual Clubcard multibuy must not be treated as a unit price.
        self::assertSame(340, $result['items'][0]['product']['price_cents']);
    }

    #[DataProvider('unpriceable')]
    public function testUnpriceableLinesMakeTheTotalExplicitlyIncomplete(array $change, string $reason): void
    {
        $product = array_replace(Fixtures::product(), $change);
        $result = (new Estimator(new FakeCatalog(['315853194' => $product])))->estimate([['product_id' => '315853194', 'quantity' => 2]]);
        self::assertFalse($result['complete']);
        self::assertNull($result['total_cents']);
        self::assertSame(0, $result['priced_total_cents']);
        self::assertSame($reason, $result['items'][0]['status']);
    }

    public static function unpriceable(): iterable
    {
        yield [['available' => false], 'unavailable'];
        yield [['price_cents' => null], 'unknown_price'];
        yield [['deposit_cents' => null], 'unknown_deposit'];
        yield [['product_type' => 'LooseProduct'], 'unsupported_product_type'];
    }

    public function testMissingProductDoesNotEraseSuccessfullyPricedItems(): void
    {
        $catalog = new FakeCatalog(['315853194' => Fixtures::product()]);
        $result = (new Estimator($catalog))->estimate([['product_id' => '315853194', 'quantity' => 1], ['product_id' => '999999999', 'quantity' => 1]]);
        self::assertNull($result['total_cents']);
        self::assertSame(365, $result['priced_total_cents']);
        self::assertSame('product_not_found', $result['items'][1]['status']);
    }

    public function testUpstreamFailureFailsEstimateInsteadOfPretendingProductIsMissing(): void
    {
        $catalog = new FakeCatalog(['315853194' => new ApiError('upstream_timeout', 'timeout', 504)]);
        $this->expectException(ApiError::class);
        (new Estimator($catalog))->estimate([['product_id' => '315853194', 'quantity' => 1]]);
    }

    #[DataProvider('invalidItems')]
    public function testInvalidListsAreRejectedBeforeFetching(array $items): void
    {
        $catalog = new FakeCatalog();
        try {
            (new Estimator($catalog))->estimate($items);
            self::fail('Expected validation error');
        } catch (ApiError $e) {
            self::assertSame(422, $e->status);
            self::assertSame(0, $catalog->calls);
        }
    }

    public static function invalidItems(): iterable
    {
        yield [[]];
        yield [[['product_id' => '315853194', 'quantity' => 0]]];
        yield [[['product_id' => '315853194', 'quantity' => 1.5]]];
        yield [[['product_id' => '315853194', 'quantity' => '2']]];
        yield [[['product_id' => '315853194', 'quantity' => 99], ['product_id' => '315853194', 'quantity' => 1]]];
        yield [[['product_id' => '315853194', 'quantity' => 1], ['product_id' => '../etc/passwd', 'quantity' => 1]]];
        yield [array_fill(0, 51, ['product_id' => '315853194', 'quantity' => 1])];
    }

    public function testMoneyAndMissingFieldsDoNotTurnUnknownPricesIntoFreeItems(): void
    {
        self::assertSame(10, Money::cents(0.1));
        self::assertSame(101, Money::cents('1.005'));
        self::assertNull(Money::cents(null));
        self::assertNull(Money::cents(-1));
        self::assertNull(Money::cents(INF));
        self::assertNull(Money::cents(true));
        $node = Fixtures::node();
        unset($node['charges'], $node['sellers']);
        $product = Product::normalize($node, 'now');
        self::assertNull($product['deposit_cents']);
        self::assertNull($product['price_cents']);
        self::assertFalse($product['available']);
        $node['charges'] = [];
        self::assertSame(0, Product::normalize($node, 'now')['deposit_cents']);
    }
}
