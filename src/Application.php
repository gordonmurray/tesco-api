<?php
declare(strict_types=1);
namespace Tesco;

final class Application
{
    public function __construct(private readonly Catalog $catalog, private readonly Store $store, private readonly Tracker $tracker, private readonly string $token = '') {}

    /** @return array{0: int, 1: array} */
    public function handle(string $method, string $path, array $query = [], string $body = '', string $authorization = '', string $contentType = ''): array
    {
        try {
            if ($method === 'GET' && $path === '/health') {
                return [200, ['status' => 'ok']];
            }
            if ($method === 'GET' && $path === '/') {
                return [200, ['name' => 'Tesco API', 'region' => 'IE', 'currency' => 'EUR', 'version' => '2.0.0', 'endpoints' => ['/health', '/search?q=milk', '/products/{id}', '/estimate', '/watchlist', '/history/{id}', '/snapshots/{id}']]];
            }
            if ($this->token !== '' && !hash_equals('Bearer ' . $this->token, $authorization)) {
                throw new ApiError('unauthorized', 'A valid bearer token is required.', 401);
            }
            if ($path === '/search') {
                $this->method($method, ['GET']);
                if (!is_string($query['q'] ?? null)) {
                    throw new ApiError('invalid_input', 'Supply a search query in q.', 422);
                }
                return [200, $this->catalog->search($query['q'], $this->integer($query['page'] ?? '1', 1, 100), $this->integer($query['limit'] ?? '12', 1, 24))];
            }
            if (preg_match('~^/products/([^/]+)$~D', $path, $m)) {
                $this->method($method, ['GET']);
                return [200, $this->catalog->product($m[1])];
            }
            if ($path === '/estimate') {
                $this->method($method, ['POST']);
                if (strtolower(trim(explode(';', $contentType)[0])) !== 'application/json') {
                    throw new ApiError('unsupported_media_type', 'Send Content-Type: application/json.', 415);
                }
                if (strlen($body) > 65536) {
                    throw new ApiError('request_too_large', 'Request body exceeds 64 KiB.', 413);
                }
                try {
                    $input = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    throw new ApiError('invalid_json', 'Request body must be valid JSON.', 400);
                }
                if (!is_array($input) || !is_array($input['items'] ?? null)) {
                    throw new ApiError('invalid_input', 'Supply an object with an items list.', 422);
                }
                return [200, (new Estimator($this->catalog))->estimate($input['items'])];
            }
            if ($path === '/watchlist') {
                $this->method($method, ['GET']);
                return [200, ['products' => $this->store->watchlist()]];
            }
            if (preg_match('~^/watchlist/([^/]+)$~D', $path, $m)) {
                $this->method($method, ['PUT', 'DELETE']);
                TescoClient::validateId($m[1]);
                if ($method === 'PUT') {
                    $this->store->watch($this->catalog->product($m[1]));
                } else {
                    $this->store->unwatch($m[1]);
                }
                return [200, ['product_id' => $m[1], 'watched' => $method === 'PUT']];
            }
            if (preg_match('~^/history/([^/]+)$~D', $path, $m)) {
                $this->method($method, ['GET']);
                TescoClient::validateId($m[1]);
                return [200, ['product_id' => $m[1], 'snapshots' => $this->store->history($m[1], $this->integer($query['limit'] ?? '100', 1, 1000))]];
            }
            if (preg_match('~^/snapshots/([^/]+)$~D', $path, $m)) {
                $this->method($method, ['POST']);
                TescoClient::validateId($m[1]);
                $result = $this->tracker->refresh($m[1]);
                return [$result['failed'] ? 502 : 201, $result];
            }
            throw new ApiError('not_found', 'Route not found.', 404);
        } catch (ApiError $e) {
            return [$e->status, ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]]];
        }
    }

    private function method(string $actual, array $allowed): void
    {
        if (!in_array($actual, $allowed, true)) {
            throw new ApiError('method_not_allowed', 'Allowed methods: ' . implode(', ', $allowed), 405);
        }
    }

    private function integer(mixed $value, int $min, int $max): int
    {
        if (!is_scalar($value) || !preg_match('/^\d+$/D', (string) $value) || (int) $value < $min || (int) $value > $max) {
            throw new ApiError('invalid_input', "Expected an integer from $min to $max.", 422);
        }
        return (int) $value;
    }
}
