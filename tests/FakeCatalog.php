<?php
declare(strict_types=1);
namespace Tesco\Tests;
use Tesco\{Catalog, ApiError};
final class FakeCatalog implements Catalog
{
    public int $calls = 0;
    public function __construct(public array $products = []) {}
    public function search(string $query, int $page = 1, int $limit = 12): array
    {
        ++$this->calls;
        return ['products' => array_values($this->products), 'query' => $query];
    }
    public function product(string $id): array
    {
        ++$this->calls;
        $product = $this->products[$id] ?? new ApiError('product_not_found', 'Missing', 404);
        if ($product instanceof ApiError) {
            throw $product;
        }
        return $product;
    }
}
