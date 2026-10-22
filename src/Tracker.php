<?php
declare(strict_types=1);
namespace Tesco;

final class Tracker
{
    public function __construct(private readonly Catalog $freshCatalog, private readonly Store $store) {}

    public function refresh(?string $id = null): array
    {
        $ids = $id === null ? array_column($this->store->watchlist(), 'product_id') : [$id];
        $results = [];
        $failed = 0;
        foreach ($ids as $productId) {
            try {
                $product = $this->freshCatalog->product($productId);
                $this->store->snapshot($product);
                $results[] = ['product_id' => $productId, 'status' => 'recorded', 'observed_at' => $product['observed_at']];
            } catch (ApiError $e) {
                ++$failed;
                $results[] = ['product_id' => $productId, 'status' => 'failed', 'error' => $e->errorCode];
                // Stop hitting an upstream that is denying or throttling us.
                if ($e->errorCode !== 'product_not_found') {
                    break;
                }
            }
        }
        return ['results' => $results, 'recorded' => count($results) - $failed, 'failed' => $failed, 'skipped' => count($ids) - count($results)];
    }
}
