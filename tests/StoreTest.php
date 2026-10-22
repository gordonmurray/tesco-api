<?php
declare(strict_types=1);
namespace Tesco\Tests;
use PHPUnit\Framework\TestCase;
use Tesco\{Store, CachedCatalog, Tracker, ApiError};

final class StoreTest extends TestCase
{
    public function testWatchIsIdempotentAndUnwatchKeepsHistory(): void
    {
        $store = new Store(':memory:');
        $product = Fixtures::product();
        $store->watch($product);
        $store->watch($product);
        self::assertCount(1, $store->watchlist());
        $store->snapshot($product);
        $product['price_cents'] = 300;
        $store->snapshot($product);
        $store->unwatch($product['id']);
        self::assertSame([], $store->watchlist());
        self::assertSame([300, 340], array_column($store->history($product['id']), 'price_cents'));
        self::assertCount(1, $store->history($product['id'], 1));
    }

    public function testSqliteDataSurvivesReopeningDatabase(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tesco-test-');
        try {
            $store = new Store($path);
            $store->watch(Fixtures::product());
            $store->snapshot(Fixtures::product());
            unset($store);
            $reopened = new Store($path);
            self::assertCount(1, $reopened->watchlist());
            self::assertSame(340, $reopened->history('315853194')[0]['price_cents']);
            unset($reopened);
        } finally {
            foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
                if (is_file($file)) { unlink($file); }
            }
        }
    }

    public function testCachePreservesObservationTimeAndExpires(): void
    {
        $store = new Store(':memory:');
        $source = new FakeCatalog(['315853194' => Fixtures::product()]);
        $catalog = new CachedCatalog($source, $store, 300);
        self::assertFalse($catalog->product('315853194')['cached']);
        self::assertTrue($catalog->product('315853194')['cached']);
        self::assertSame('2026-10-22T08:00:00Z', $catalog->product('315853194')['observed_at']);
        self::assertSame(1, $source->calls);
        $store->cache('product:315853194', Fixtures::product(), time() - 1);
        self::assertFalse($catalog->product('315853194')['cached']);
        self::assertSame(2, $source->calls);
    }

    public function testFailedFetchIsNotCachedAndStaleDataIsNotServed(): void
    {
        $store = new Store(':memory:');
        $store->cache('product:315853194', Fixtures::product(), time() - 1);
        $source = new FakeCatalog(['315853194' => new ApiError('upstream_unavailable', 'offline')]);
        $catalog = new CachedCatalog($source, $store, 300);
        try {
            $catalog->product('315853194');
            self::fail('Expected upstream error');
        } catch (ApiError $e) {
            self::assertSame('upstream_unavailable', $e->errorCode);
        }
        $source->products['315853194'] = Fixtures::product();
        self::assertFalse($catalog->product('315853194')['cached']);
        self::assertSame(2, $source->calls);
    }

    public function testTrackingBypassesCachedPricesAndNeverRecordsFailedFetches(): void
    {
        $store = new Store(':memory:');
        $product = Fixtures::product();
        $store->watch($product);
        $store->cache('product:315853194', $product, time() + 300);
        $product['price_cents'] = 400;
        $source = new FakeCatalog(['315853194' => $product]);
        $tracker = new Tracker($source, $store);
        self::assertSame(1, $tracker->refresh()['recorded']);
        self::assertSame(400, $store->history('315853194')[0]['price_cents']);
        $source->products['315853194'] = new ApiError('upstream_rate_limited', 'slow down', 503);
        self::assertSame(1, $tracker->refresh()['failed']);
        self::assertCount(1, $store->history('315853194'));
    }

    public function testWatchlistCapacityAndThrottledRefreshReportsSkippedProducts(): void
    {
        $store = new Store(':memory:');
        $product = Fixtures::product();
        for ($i = 100000000; $i < 100000100; ++$i) {
            $product['id'] = (string) $i;
            $store->watch($product);
        }
        $source = new FakeCatalog(['100000000' => new ApiError('upstream_rate_limited', 'wait', 503)]);
        $result = (new Tracker($source, $store))->refresh();
        self::assertSame(1, $result['failed']);
        self::assertSame(99, $result['skipped']);
        self::assertSame(1, $source->calls);
        $this->expectException(ApiError::class);
        $store->watch(Fixtures::product());
    }
}
