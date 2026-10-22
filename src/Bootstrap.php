<?php
declare(strict_types=1);
namespace Tesco;

final class Bootstrap
{
    public static function services(): array
    {
        $key = getenv('TESCO_WEB_API_KEY') ?: 'TvOSZJHlEk0pjniDGQFAc9Q59WGAR4dA';
        if (preg_match('/[\r\n]/', $key)) {
            throw new \RuntimeException('Invalid website client key configuration.');
        }
        $ttl = getenv('CACHE_TTL');
        if ($ttl !== false && (!ctype_digit($ttl) || (int) $ttl > 86400)) {
            throw new \RuntimeException('CACHE_TTL must be an integer from 0 to 86400.');
        }
        $store = new Store(getenv('DB_PATH') ?: dirname(__DIR__) . '/var/tesco.sqlite');
        $client = new TescoClient(new CurlTransport($key));
        $catalog = new CachedCatalog($client, $store, $ttl === false ? 300 : (int) $ttl);
        $tracker = new Tracker($client, $store);
        $token = getenv('APP_API_TOKEN');
        return [$catalog, $store, $tracker, new Application($catalog, $store, $tracker, $token === false ? '' : $token)];
    }
}
