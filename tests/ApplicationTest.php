<?php
declare(strict_types=1);
namespace Tesco\Tests;
use PHPUnit\Framework\TestCase;
use Tesco\{Application, Store, Tracker};

final class ApplicationTest extends TestCase
{
    private function app(string $token = ''): Application
    {
        $store = new Store(':memory:');
        $catalog = new FakeCatalog(['315853194' => Fixtures::product()]);
        return new Application($catalog, $store, new Tracker($catalog, $store), $token);
    }

    public function testAuthenticationProtectsReadsAndWritesButNotHealth(): void
    {
        $app = $this->app('test-token');
        self::assertSame(200, $app->handle('GET', '/health')[0]);
        self::assertSame(401, $app->handle('GET', '/watchlist')[0]);
        self::assertSame(401, $app->handle('PUT', '/watchlist/315853194')[0]);
        self::assertSame(200, $app->handle('GET', '/watchlist', [], '', 'Bearer test-token')[0]);
    }

    public function testWatchSnapshotHistoryWorkflow(): void
    {
        $app = $this->app();
        self::assertSame(200, $app->handle('PUT', '/watchlist/315853194')[0]);
        self::assertCount(1, $app->handle('GET', '/watchlist')[1]['products']);
        self::assertSame(201, $app->handle('POST', '/snapshots/315853194')[0]);
        self::assertSame(340, $app->handle('GET', '/history/315853194')[1]['snapshots'][0]['price_cents']);
        self::assertSame(200, $app->handle('DELETE', '/watchlist/315853194')[0]);
        self::assertCount(1, $app->handle('GET', '/history/315853194')[1]['snapshots']);
    }

    public function testMalformedRequestsHaveUsefulStatuses(): void
    {
        $app = $this->app();
        self::assertSame(404, $app->handle('GET', '/missing')[0]);
        self::assertSame(405, $app->handle('DELETE', '/estimate')[0]);
        self::assertSame(415, $app->handle('POST', '/estimate', [], '{}')[0]);
        self::assertSame(400, $app->handle('POST', '/estimate', [], '{', '', 'application/json')[0]);
        self::assertSame(413, $app->handle('POST', '/estimate', [], str_repeat('a', 65537), '', 'application/json')[0]);
        self::assertSame(422, $app->handle('GET', '/search', ['q' => ['milk']])[0]);
        self::assertSame(422, $app->handle('GET', '/search', ['q' => 'milk', 'page' => '-1'])[0]);
        self::assertSame(422, $app->handle('GET', '/history/315853194', ['limit' => '1001'])[0]);
        self::assertSame(422, $app->handle('POST', '/estimate', [], 'null', '', 'application/json')[0]);
    }

    public function testEstimateEndpointReturnsIntegerCents(): void
    {
        [$status, $body] = $this->app()->handle('POST', '/estimate', [], '{"items":[{"product_id":"315853194","quantity":2}]}', '', 'application/json; charset=utf-8');
        self::assertSame(200, $status);
        self::assertSame(730, $body['total_cents']);
    }
}
