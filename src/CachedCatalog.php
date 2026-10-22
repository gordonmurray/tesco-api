<?php
declare(strict_types=1);
namespace Tesco;

final class CachedCatalog implements Catalog
{
    public function __construct(private readonly Catalog $source, private readonly Store $store, private readonly int $ttl) {}

    public function search(string $query, int $page = 1, int $limit = 12): array
    {
        if (!mb_check_encoding($query, 'UTF-8')) {
            throw new ApiError('invalid_input', 'The search query must be valid UTF-8.', 422);
        }
        return $this->get('search:' . hash('sha256', json_encode([$query, $page, $limit], JSON_THROW_ON_ERROR)), fn () => $this->source->search($query, $page, $limit));
    }

    public function product(string $id): array
    {
        TescoClient::validateId($id);
        return $this->get('product:' . $id, fn () => $this->source->product($id));
    }

    private function get(string $key, \Closure $fetch): array
    {
        $cached = $this->ttl > 0 ? $this->store->cached($key, time()) : null;
        if ($cached !== null) {
            return $cached + ['cached' => true];
        }
        $result = $fetch();
        if ($this->ttl > 0) {
            $this->store->cache($key, $result, time() + $this->ttl);
        }
        return $result + ['cached' => false];
    }
}
