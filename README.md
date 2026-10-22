# Tesco API

**Find products. Track prices. Estimate your next shop.**

An unofficial PHP wrapper around Tesco Ireland’s GraphQL API, with a small JSON API, a command-line client, and persistent price history. No Tesco account is needed for the supported queries.

## Start in a minute

Install Docker with Compose, then run:

```sh
git clone https://github.com/gordonmurray/tesco-api.git
cd tesco-api
docker compose up --build -d --wait
curl http://localhost:8080/health
curl --get --data-urlencode 'q=coke zero' http://localhost:8080/search
```

The service listens at **http://localhost:8080**, bound to your machine’s loopback address. The health response is `{"status":"ok"}`.

No local PHP installation, Tesco login, or database server is required. SQLite data lives in a named Docker volume and survives container replacement.

**Runtime:** PHP **8.5.12**, Apache, and SQLite.

## What it does

- Search the Irish catalog and retrieve products by their nine-digit Tesco IDs.
- Return names, images, product links, regular prices, deposits, unit prices, availability, and offer descriptions.
- Estimate a shopping list with integer-cent arithmetic and explicit handling of incomplete prices.
- Save a watchlist and record fresh price observations in SQLite.
- Expose the same operations through HTTP and a CLI.
- Cache successful lookups for five minutes to reduce requests to Tesco.

This project is independent of Tesco. Its upstream is an undocumented website API, not a supported public developer service. Access and response formats can change.

## Find a product

```sh
curl --get \
  --data-urlencode 'q=coke zero' \
  --data 'limit=3' \
  http://localhost:8080/search

curl http://localhost:8080/products/315853194
```

A product response looks like this, with offer descriptions omitted for brevity:

```json
{
  "id": "315853194",
  "title": "Coca-Cola Zero Sugar Soft Drink 2 Litre",
  "url": "https://www.tesco.ie/shop/en-IE/products/315853194",
  "image_url": "https://digitalcontent.api.tesco.com/...",
  "currency": "EUR",
  "price_cents": 340,
  "unit_price_cents": 170,
  "unit_measure": "litre",
  "deposit_cents": 25,
  "available": true,
  "product_type": "SingleProduct",
  "promotions": [],
  "observed_at": "2026-10-22T08:20:13Z",
  "cached": false
}
```

These example prices were observed on October 22, 2026. They are not guaranteed checkout prices. `observed_at` records when this wrapper fetched the data, not when Tesco last changed the price.

## Estimate a shopping list

Choose exact product IDs from search results. Quantities are whole packs or items, not kilograms.

The included [shopping list](examples/shopping-list.json) contains two 2L bottles of Coke Zero and one litre of milk:

```json
{
  "items": [
    {"product_id": "315853194", "quantity": 2},
    {"product_id": "292769732", "quantity": 1}
  ]
}
```

```sh
curl http://localhost:8080/estimate \
  -H 'Content-Type: application/json' \
  --data-binary @examples/shopping-list.json
```

At the observed prices, the result is **€7.95 in products + €0.50 in deposits = €8.45**.

Every monetary field ends in `_cents`. `845` means €8.45. Duplicate product IDs are combined before pricing.

The estimator uses regular prices plus deposits. It preserves Clubcard and multibuy descriptions but does not apply their discounts. Delivery charges are excluded.

If a product is missing, unavailable, weight-priced, or has an unknown price or deposit, the response has `complete: false` and `total_cents: null`. `priced_total_cents` is the sum of the usable lines, not a complete basket quote. Each excluded line has a status explaining why.

An upstream outage fails the estimate with an error. It does not make the missing items look free.

## Track things you buy

Add a product to your watchlist, record its current price, and inspect its history:

```sh
curl -X PUT http://localhost:8080/watchlist/315853194
curl -X POST http://localhost:8080/snapshots/315853194
curl http://localhost:8080/history/315853194
```

Or use the CLI:

```sh
docker compose exec -T api php bin/tesco watch 315853194
docker compose exec -T api php bin/tesco watchlist
docker compose exec -T api php bin/tesco snapshot
docker compose exec -T api php bin/tesco history 315853194
```

`snapshot` without an ID refreshes the entire watchlist. It bypasses the cache and saves each successful observation, including unchanged prices. Failed requests never become observations.

History starts when you collect it. Adding a watchlist entry does not start a background scheduler or retrieve historical Tesco prices.

To collect prices daily, add a host cron entry. Replace the repository path with your own:

```cron
0 7 * * * cd /absolute/path/to/tesco-api && /usr/bin/flock -n /tmp/tesco-api-snapshot.lock docker compose exec -T api php bin/tesco snapshot >> snapshots.log 2>&1
```

This runs at 07:00 in the host’s timezone. The container must be running. `flock` prevents overlapping runs on Linux.

The watchlist holds up to 100 products. Refresh stops on an upstream access, network, schema, or rate-limit error and reports the remaining products as skipped. The CLI exits nonzero if any item fails.

Remove an item without deleting its history:

```sh
docker compose exec -T api php bin/tesco unwatch 315853194
```

## HTTP API

| Method | Route | Purpose |
| --- | --- | --- |
| GET | `/` | Service information |
| GET | `/health` | Application and database startup check; no Tesco request |
| GET | `/search?q=milk&page=1&limit=12` | Search products |
| GET | `/products/{id}` | Retrieve an exact product |
| POST | `/estimate` | Price an `items` list |
| GET | `/watchlist` | List watched products |
| PUT | `/watchlist/{id}` | Add or update a watched product |
| DELETE | `/watchlist/{id}` | Stop watching; keep history |
| POST | `/snapshots/{id}` | Record one fresh product observation |
| GET | `/history/{id}?limit=100` | Read observations, newest first |

See the [API reference](docs/api.md) for response semantics and limits, or import [OpenAPI](public/openapi.json) into an API client. The running service also serves `/openapi.json`.

There is no endpoint that changes your Tesco basket, submits an order, or accesses your Tesco account.

## CLI

```sh
docker compose exec -T api php bin/tesco help
docker compose exec -T api php bin/tesco search 'oat milk'
docker compose exec -T api php bin/tesco product 315853194
docker compose exec -T api php bin/tesco estimate examples/shopping-list.json
```

To estimate a custom file from your machine:

```sh
docker compose run --rm --no-deps \
  -v "$PWD/my-list.json:/tmp/list.json:ro" \
  api php bin/tesco estimate /tmp/list.json
```

Commands print JSON. Errors go to stderr. Exit code `1` means an error, an incomplete estimate, or a failed snapshot. HTTP bearer authentication does not apply to local CLI commands.

## Configuration

Defaults work without an `.env` file. To customize them:

```sh
cp .env.example .env
# Edit .env, then recreate the container.
docker compose up -d
```

| Variable | Default | Meaning |
| --- | --- | --- |
| `PORT` | `8080` | Local HTTP port |
| `CACHE_TTL` | `300` | Cache lifetime in seconds, from 0 to 86400; 0 disables caching |
| `APP_API_TOKEN` | empty | Optional bearer token protecting product, estimate, watchlist, snapshot, and history routes |
| `TESCO_WEB_API_KEY` | bundled website key | Replace if Tesco rotates its website client key |
| `DB_PATH` | `/app/var/tesco.sqlite` in Docker | SQLite path; local PHP defaults to `var/tesco.sqlite` |

`DB_PATH` is an application variable. Compose deliberately fixes it inside the persistent volume.

With `APP_API_TOKEN` set, add this header to HTTP requests:

```sh
curl -H 'Authorization: Bearer YOUR_TOKEN' http://localhost:8080/watchlist
```

`/`, `/health`, and `/openapi.json` remain public. The Tesco website key is not a customer credential. Do not put Tesco passwords, cookies, or account tokens into this project.

## Docker and data

The final image contains Apache, PHP, application code, and the Composer autoloader. It excludes Composer itself, PHPUnit, build tools, and development dependencies.

The service runs as `www-data` on port 8080. Compose provides a read-only root filesystem, a writable data volume, temporary storage, dropped capabilities, and resource limits. Only `public/` is web-accessible.

```sh
# Read logs and container health.
docker compose logs --tail=50 api
docker compose ps

# Rebuild after code or image changes.
docker compose build --pull api
docker compose up -d --wait api

# Stop while keeping your data.
docker compose down
```

`docker compose down -v` deletes the database volume, including your watchlist and history.

For backups, updates, local PHP development, and remote access, see [operations](docs/operations.md).

## Tests and development

```sh
docker compose build test
docker compose run --rm test
# After building the api image, check Apache and authentication too.
tests/runtime-smoke.sh tesco-api-api
```

PHPUnit **13.4** tests run with networking disabled. They use synthetic product fixtures and temporary SQLite databases, so Tesco availability cannot break the test suite.

The tests cover decimal rounding, deposits, duplicate quantities, incomplete estimates, GraphQL errors inside HTTP 200, exact product IDs, cache expiry, authentication, history persistence, and refresh failures.

The project has no third-party runtime packages. Its PHP requirements are enforced by Composer and checked during the image build. CI also checks PHP syntax, Composer metadata, and dependency advisories.

## Architecture

```text
HTTP / CLI
    ├── Catalog → SQLite cache → TescoClient → cURL → Tesco GraphQL
    ├── Estimator → Catalog
    └── Tracker → fresh TescoClient → SQLite observations
```

`TescoClient` owns upstream queries and error handling. `Product` normalizes each result. `Estimator` calculates totals. `Store` owns SQLite persistence. `Application` maps HTTP routes, while `bin/tesco` exposes the CLI.

The two upstream operations are `search(query, page, count)` and `product(tpnc)`. Inputs use GraphQL variables. The host, protocol, Irish region, and language are fixed in the transport.

## Scope and limitations

- **Ireland only:** `tesco.ie`, `en-IE`, EUR. There is no location-specific or account-specific pricing.
- **Guide prices:** catalog availability does not guarantee stock at your local store or delivery slot.
- **Regular-price estimates:** Clubcard, multibuy, and delivery calculations are not implemented.
- **Whole-item quantities:** weight-priced and unknown product types are excluded from estimates.
- **Observed history:** snapshots record fetched values, not Tesco’s complete price-change history.
- **Single-user service:** there are no per-user watchlists or granular permissions. Remote deployments need HTTPS, authentication, and rate limits.
- **Undocumented upstream:** schema changes, client-key rotation, throttling, or access restrictions can interrupt service. There are no automatic retries.

## Credits

Integration references:

- [tesco-ie-grocery-mcp](https://github.com/craicoverflow/tesco-ie-grocery-mcp): Irish region headers and anonymous search behavior.
- [basketeer](https://github.com/tobyandrews1985/basketeer): exact lookup through `product(tpnc)`.
