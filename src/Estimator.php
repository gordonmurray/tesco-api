<?php
declare(strict_types=1);
namespace Tesco;

final class Estimator
{
    public function __construct(private readonly Catalog $catalog) {}

    public function estimate(array $items): array
    {
        if (!array_is_list($items) || count($items) < 1 || count($items) > 50) {
            throw new ApiError('invalid_input', 'Supply an items list with 1–50 entries.', 422);
        }
        $quantities = [];
        // Validate the entire request before making any upstream requests.
        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item['product_id'] ?? null) || !is_int($item['quantity'] ?? null) || $item['quantity'] < 1 || $item['quantity'] > 99) {
                throw new ApiError('invalid_input', 'Each item needs a string product_id and an integer quantity from 1 to 99.', 422);
            }
            TescoClient::validateId($item['product_id']);
            $id = $item['product_id'];
            $quantities[$id] = ($quantities[$id] ?? 0) + $item['quantity'];
            if ($quantities[$id] > 99) {
                throw new ApiError('invalid_input', 'Combined quantity for a product cannot exceed 99.', 422);
            }
        }
        $lines = [];
        $subtotal = $deposits = 0;
        $complete = true;
        foreach ($quantities as $id => $quantity) {
            try {
                $product = $this->catalog->product((string) $id);
            } catch (ApiError $e) {
                if ($e->errorCode !== 'product_not_found') {
                    throw $e;
                }
                $complete = false;
                $lines[] = ['product_id' => (string) $id, 'quantity' => $quantity, 'status' => 'product_not_found', 'line_total_cents' => null];
                continue;
            }
            $reason = match (true) {
                !$product['available'] => 'unavailable',
                $product['product_type'] !== 'SingleProduct' => 'unsupported_product_type',
                $product['price_cents'] === null => 'unknown_price',
                $product['deposit_cents'] === null => 'unknown_deposit',
                default => null,
            };
            $line = ['product_id' => (string) $id, 'quantity' => $quantity, 'product' => $product, 'status' => $reason ?? 'priced', 'line_total_cents' => null];
            if ($reason !== null) {
                $complete = false;
            } else {
                $line['subtotal_cents'] = $product['price_cents'] * $quantity;
                $line['deposit_cents'] = $product['deposit_cents'] * $quantity;
                $line['line_total_cents'] = $line['subtotal_cents'] + $line['deposit_cents'];
                $subtotal += $line['subtotal_cents'];
                $deposits += $line['deposit_cents'];
            }
            $lines[] = $line;
        }
        return ['currency' => 'EUR', 'complete' => $complete, 'items' => $lines,
            'subtotal_cents' => $subtotal, 'deposit_cents' => $deposits,
            'priced_total_cents' => $subtotal + $deposits, 'total_cents' => $complete ? $subtotal + $deposits : null,
            'pricing_basis' => 'Regular prices plus deposits. Clubcard, multibuy and delivery charges are not applied.'];
    }
}
