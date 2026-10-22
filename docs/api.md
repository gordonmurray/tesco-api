# API reference

Base URL: `http://localhost:8080`. All application responses are JSON. The API targets Tesco Ireland only.

## Products and money

Product IDs are nine-digit strings, taken from the end of Tesco product URLs. Search for a product first, then save its ID.

All normalized money uses integer euro cents. Unknown prices are `null`; they are never converted to zero. Unit prices are rounded to the nearest cent using decimal half-up rounding.

`price_cents` is the regular price for one item or pack. `deposit_cents` is the charge for that entire item or pack. For example, a 24-can pack can have a larger deposit than one bottle.

`promotions` contains Tesco’s offer descriptions and validity timestamps. These offers are informational. The estimator does not interpret their text or apply discounts.

`available` reflects Tesco’s anonymous catalog response. It does not represent confirmed stock for a particular store.

`observed_at` is a UTC fetch timestamp. Cached responses retain the original timestamp. `cached` appears on top-level search and product responses, and on product objects within estimates. Snapshot products are always fresh and omit `cached`.

## Search

`GET /search?q=coke%20zero&page=1&limit=12`

| Parameter | Default | Limit |
| --- | --- | --- |
| `q` | required | 1–200 characters; nonblank |
| `page` | 1 | 1–100 |
| `limit` | 12 | 1–24 |

Response fields: `query`, `page`, `limit`, `total`, `products`, `observed_at`, and `cached`. Empty results are a successful response with an empty `products` list.

## Product lookup

`GET /products/315853194`

Returns one normalized product. This calls Tesco’s `product(tpnc)` field and checks the returned ID. It does not guess from a keyword search.

A missing product returns HTTP 404 with `product_not_found`. An invalid ID returns HTTP 422.

## Shopping-list estimates

`POST /estimate` requires `Content-Type: application/json`:

```json
{"items":[{"product_id":"315853194","quantity":2}]}
```

The request accepts 1–50 entries. Quantities must be integers from 1 to 99. Duplicate IDs are combined, with a combined limit of 99 per product. The entire request is validated before fetching products.

| Response field | Meaning |
| --- | --- |
| `complete` | Every line could be priced |
| `items` | Line results, including product details where available |
| `subtotal_cents` | Regular prices for priced lines, excluding deposits |
| `deposit_cents` | Deposits for priced lines |
| `priced_total_cents` | Subtotal plus deposits for priced lines |
| `total_cents` | Complete total, or `null` if any line is excluded |
| `pricing_basis` | Explanation of excluded discounts and charges |

Line statuses are `priced`, `product_not_found`, `unavailable`, `unsupported_product_type`, `unknown_price`, or `unknown_deposit`.

A successfully evaluated but incomplete estimate returns HTTP 200. Clients must inspect `complete`. A network, access, or upstream schema error fails the whole request with a 5xx response.

The CLI uses exit code 1 for incomplete estimates while still printing their JSON result.

## Watchlist and history

`PUT /watchlist/{id}` adds a product after a successful lookup. Repeating this call updates its title without duplicating the entry. It does not record a snapshot.

`GET /watchlist` returns `products`, each with `product_id`, `title`, and `added_at`. The maximum watchlist size is 100.

`DELETE /watchlist/{id}` is idempotent. It removes the watchlist entry but preserves history.

`POST /snapshots/{id}` fetches a fresh product and records it, even if it is not watched. A successful response uses HTTP 201:

```json
{
  "results": [{"product_id":"315853194","status":"recorded","observed_at":"2026-10-22T08:30:00Z"}],
  "recorded": 1,
  "failed": 0,
  "skipped": 0
}
```

A failed snapshot returns HTTP 502 with the same summary structure. Its result entry contains an `error` code. No failed observation is saved.

Use the CLI command `snapshot` to refresh all watched products. Refresh continues after a missing product, but stops after other upstream errors. Remaining entries count as `skipped`.

`GET /history/{id}?limit=100` returns `product_id` and `snapshots`, newest first. `limit` accepts 1–1000. An ID with no observations returns an empty list. Observations are not deduplicated or automatically deleted.

## Errors

Most errors use this structure:

```json
{"error":{"code":"invalid_input","message":"Product IDs must contain exactly nine digits."}}
```

| HTTP status | Meaning |
| --- | --- |
| 400 | Invalid JSON |
| 401 | Missing or invalid wrapper bearer token |
| 404 | Missing route or Tesco product |
| 405 | Incorrect HTTP method |
| 413 | Request body exceeds 64 KiB |
| 415 | Estimate body is not `application/json` |
| 422 | Invalid input or full watchlist |
| 500 | Internal configuration or persistence failure |
| 502 | Upstream access, response, schema, or GraphQL failure |
| 503 | Tesco rate limit |
| 504 | Upstream request timeout |

Snapshot failures use the summary structure described above. Apache can reject oversized or malformed requests before PHP runs; those server responses can be HTML.

Upstream details and credentials are not included in public error messages. There are no automatic retries. Wait before retrying a 503 response.

## Authentication and service routes

Set `APP_API_TOKEN` to require `Authorization: Bearer TOKEN` on application routes. `/`, `/health`, and `/openapi.json` remain public.

`GET /health` confirms that the application and SQLite initialization succeed. It does not contact Tesco or guarantee that product queries will succeed.

The API sets `Cache-Control: no-store` for client responses. The internal SQLite cache is independent of browser caching.
