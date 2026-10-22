<?php
declare(strict_types=1);
namespace Tesco\Tests;

use PHPUnit\Framework\TestCase;
use Tesco\Bootstrap;

final class BootstrapTest extends TestCase
{
    public function testZeroStringTokenDoesNotDisableAuthentication(): void
    {
        $original = [];
        foreach (['APP_API_TOKEN', 'DB_PATH', 'CACHE_TTL'] as $name) {
            $original[$name] = getenv($name);
        }
        try {
            putenv('APP_API_TOKEN=0');
            putenv('DB_PATH=:memory:');
            putenv('CACHE_TTL=0');
            [, , , $app] = Bootstrap::services();
            self::assertSame(401, $app->handle('GET', '/watchlist')[0]);
            self::assertSame(200, $app->handle('GET', '/watchlist', [], '', 'Bearer 0')[0]);
        } finally {
            foreach ($original as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
        }
    }
}
