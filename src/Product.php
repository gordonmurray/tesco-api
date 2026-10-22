<?php
declare(strict_types=1);
namespace Tesco;

final class Product
{
    public static function normalize(array $node, string $observedAt): array
    {
        if (!isset($node['id'], $node['title']) || (!is_string($node['id']) && !is_int($node['id'])) || !preg_match('/^\d{9}$/D', (string) $node['id']) || !is_string($node['title'])) {
            throw new ApiError('upstream_schema', 'Tesco returned an unrecognized product.');
        }
        if (isset($node['charges']) && !is_array($node['charges'])) {
            throw new ApiError('upstream_schema', 'Tesco returned unrecognized charges.');
        }
        if (isset($node['sellers']) && !is_array($node['sellers'])) {
            throw new ApiError('upstream_schema', 'Tesco returned unrecognized sellers.');
        }
        if (isset($node['sellers']['results']) && (!is_array($node['sellers']['results']) || !array_is_list($node['sellers']['results']))) {
            throw new ApiError('upstream_schema', 'Tesco returned unrecognized sellers.');
        }
        $seller = $node['sellers']['results'][0] ?? [];
        if (!is_array($seller) || (isset($seller['price']) && !is_array($seller['price']))) {
            throw new ApiError('upstream_schema', 'Tesco returned an unrecognized price.');
        }
        $price = $seller['price'] ?? [];
        foreach ([$node['defaultImageUrl'] ?? null, $node['productType'] ?? null, $price['unitOfMeasure'] ?? null] as $value) {
            if ($value !== null && !is_string($value)) {
                throw new ApiError('upstream_schema', 'Tesco returned unrecognized product details.');
            }
        }
        $promotions = $seller['promotions'] ?? [];
        if (!is_array($promotions) || !array_is_list($promotions)) {
            throw new ApiError('upstream_schema', 'Tesco returned unrecognized promotions.');
        }
        foreach ($promotions as $promotion) {
            if (!is_array($promotion)) {
                throw new ApiError('upstream_schema', 'Tesco returned an unrecognized promotion.');
            }
            foreach (['description', 'startDate', 'endDate'] as $field) {
                if (isset($promotion[$field]) && !is_string($promotion[$field])) {
                    throw new ApiError('upstream_schema', 'Tesco returned an unrecognized promotion.');
                }
            }
        }
        $deposit = array_key_exists('charges', $node) && is_array($node['charges']) ? 0 : null;
        foreach ($node['charges'] ?? [] as $charge) {
            if (!is_array($charge)) {
                throw new ApiError('upstream_schema', 'Tesco returned an unrecognized charge.');
            }
            $amount = Money::cents($charge['amount'] ?? null);
            if (($charge['__typename'] ?? '') !== 'ProductDepositReturnCharge' || $amount === null) {
                $deposit = null;
                break;
            }
            $deposit += $amount;
        }
        return [
            'id' => (string) $node['id'], 'title' => $node['title'],
            'url' => 'https://www.tesco.ie/shop/en-IE/products/' . $node['id'],
            'image_url' => $node['defaultImageUrl'] ?? null,
            'currency' => 'EUR', 'price_cents' => Money::cents($price['actual'] ?? null),
            'unit_price_cents' => Money::cents($price['unitPrice'] ?? null),
            'unit_measure' => $price['unitOfMeasure'] ?? null, 'deposit_cents' => $deposit,
            'available' => ($seller['isForSale'] ?? false) === true,
            'product_type' => $node['productType'] ?? null,
            'promotions' => $promotions, 'observed_at' => $observedAt,
        ];
    }
}
