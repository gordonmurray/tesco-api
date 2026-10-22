# Operations and development

## Runtime and security updates

The Dockerfile pins PHP 8.5.12, released on October 21, 2026. This release fixes CVE-2026-109618 and CVE-2026-109619.

Read the [PHP advisory](https://www.php.net/archive/2026.php#2026-10-21-5) before selecting an older image. The build rejects PHP versions below 8.5.12.

To upgrade PHP:

1. Check the current stable release on [php.net](https://www.php.net/downloads.php).
2. Update `PHP_VERSION` in the Dockerfile and the PHP requirement and platform version in `composer.json`.
3. Update the lockfile with Composer under the new runtime.
4. Build and run the test target.
5. Rebuild the runtime image with `--pull` and recreate the service.
6. Check `docker compose exec -T api php -v` and container health.

Dependabot checks Composer and Docker dependencies weekly. Its proposals still need review and tests. A fixed image tag does not automatically advance to the next PHP patch.

## Local PHP development

Required: PHP 8.5.12 or a later 8.5 patch, Composer 2, and the cURL, JSON, mbstring, PDO, and PDO SQLite extensions.

```sh
composer install
composer test
php -S 127.0.0.1:8080 -t public public/index.php
```

The local PHP server is for development. Docker uses Apache. Set environment variables in your shell for local PHP; the app does not load `.env` files itself.

```sh
CACHE_TTL=0 php bin/tesco search 'oat milk'
php bin/tesco estimate examples/shopping-list.json
```

The Docker test target uses the same PHP base as the runtime. To audit packages with network access:

```sh
docker compose build test
docker run --rm tesco-api-test composer validate --strict
docker run --rm tesco-api-test composer audit
```

The image name assumes the default Compose project name, `tesco-api`. If you change the project name, use the resulting test image name.

## Database backup

Stop the service before copying the SQLite files so that the database and its WAL remain consistent:

```sh
docker compose stop api
mkdir -p backups
docker compose cp api:/app/var/. ./backups/
docker compose start api
```

The backup contains the watchlist, price observations, and cache. Keep backups outside the public document root.

To restore into the existing data volume:

```sh
docker compose stop api
docker compose cp ./backups/. api:/app/var/
docker compose run --rm --no-deps --user root --cap-add CHOWN api chown -R www-data:www-data /app/var
docker compose start api
```

Restore a complete backup set from one stopped service. Do not combine a database with WAL files from a different backup. Use a fresh backup directory for each backup.

## Remote access

The default Compose port is loopback-only. For access from another machine, put an HTTPS reverse proxy in front of the service and set a strong `APP_API_TOKEN`.

Apply request-rate limits at the proxy. The application is a single-user tool with one shared watchlist and database. Its bearer token grants access to all application routes.

Do not expose the database volume or the repository directory as web content. Only `public/` belongs in the document root.

## Cache and observations

Cache entries expire after `CACHE_TTL` seconds. Expired entries are removed during subsequent cache writes. Failed requests are not cached, and stale entries are not served on upstream failure.

Snapshots always use the uncached client. A snapshot does not invalidate an existing product cache entry. Use its returned history observation for the fresh value, or disable caching during an investigation.

History grows with every successful snapshot. There is no retention policy. Monitor the database size and back it up before manual maintenance.

## Troubleshooting

| Symptom | Action |
| --- | --- |
| Port already in use | Set `PORT=8081` in `.env`, then recreate the container |
| HTTP 401 | Supply the configured wrapper bearer token |
| `upstream_access_denied` | Check the website key and whether Tesco has changed access requirements |
| `upstream_rate_limited` | Reduce request frequency and wait before retrying |
| `upstream_graphql` or `upstream_schema` | Inspect the current Tesco query contract and update the client and fixture tests |
| `internal_error` | Read `docker compose logs api`; check database permissions and configuration |
| Incomplete estimate | Inspect each line’s `status`; do not use `priced_total_cents` as a complete total |
| Empty history | Run `snapshot`; search and watch commands do not collect history |

Product requests have a five-second connection timeout and a twenty-second total timeout. Responses are capped at 4 MiB. Sequential requests in one process are spaced at least 350 milliseconds apart.

Requests do not retry automatically. Large uncached estimates require several sequential upstream requests and can exceed HTTP time limits; use the CLI for longer lists.
