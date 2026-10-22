<?php
declare(strict_types=1);
namespace Tesco;

final class TescoClient implements Catalog
{
    private const FIELDS = <<<'GRAPHQL'
        id title defaultImageUrl productType
        charges { __typename ... on ProductDepositReturnCharge { amount } }
        sellers(type: TOP, limit: 1, offset: 0) {
            results {
                isForSale
                price { actual unitPrice unitOfMeasure }
                promotions { description startDate endDate }
            }
        }
        GRAPHQL;

    public function __construct(private readonly Transport $transport) {}

    public function search(string $query, int $page = 1, int $limit = 12): array
    {
        if (!mb_check_encoding($query, 'UTF-8') || trim($query) === '' || mb_strlen($query) > 200 || $page < 1 || $page > 100 || $limit < 1 || $limit > 24) {
            throw new ApiError('invalid_input', 'Use a query of 1–200 characters, page 1–100, and limit 1–24.', 422);
        }
        $fields = self::FIELDS;
        $document = 'query Search($query: String!, $page: Int!, $count: Int!) { search(query: $query, page: $page, count: $count) { info { total } results { node { ... on ProductType { ' . $fields . ' } } } } }';
        $data = $this->request('Search', $document, ['query' => $query, 'page' => $page, 'count' => $limit]);
        if (!isset($data['search']['results'], $data['search']['info']['total']) || !is_array($data['search']['results']) || !array_is_list($data['search']['results']) || !is_int($data['search']['info']['total'])) {
            throw new ApiError('upstream_schema', 'Tesco returned an unrecognized search response.');
        }
        $at = gmdate('Y-m-d\TH:i:s\Z');
        $products = [];
        foreach ($data['search']['results'] as $hit) {
            if (!is_array($hit) || !is_array($hit['node'] ?? null)) {
                throw new ApiError('upstream_schema', 'Tesco returned an unrecognized product.');
            }
            $products[] = Product::normalize($hit['node'] ?? [], $at);
        }
        return ['query' => $query, 'page' => $page, 'limit' => $limit, 'total' => $data['search']['info']['total'], 'products' => $products, 'observed_at' => $at];
    }

    public function product(string $id): array
    {
        self::validateId($id);
        $document = 'query Product($tpnc: String!) { product(tpnc: $tpnc) { ' . self::FIELDS . ' } }';
        $data = $this->request('Product', $document, ['tpnc' => $id]);
        if (!array_key_exists('product', $data)) {
            throw new ApiError('upstream_schema', 'Tesco returned an unrecognized product response.');
        }
        if ($data['product'] === null) {
            throw new ApiError('product_not_found', 'Product not found in Tesco Ireland.', 404);
        }
        if (!is_array($data['product'])) {
            throw new ApiError('upstream_schema', 'Tesco returned an unrecognized product.');
        }
        $product = Product::normalize($data['product'], gmdate('Y-m-d\TH:i:s\Z'));
        if ($product['id'] !== $id) {
            throw new ApiError('upstream_schema', 'Tesco returned a different product.');
        }
        return $product;
    }

    public static function validateId(string $id): void
    {
        if (!preg_match('/^\d{9}$/D', $id)) {
            throw new ApiError('invalid_input', 'Product IDs must contain exactly nine digits.', 422);
        }
    }

    private function request(string $name, string $query, array $variables): array
    {
        $response = $this->transport->send(['operationName' => $name, 'variables' => $variables, 'query' => $query, 'extensions' => ['mfeName' => 'mfe-plp']]);
        if ($response['status'] === 429) {
            throw new ApiError('upstream_rate_limited', 'Tesco is rate limiting requests. Try again later.', 503);
        }
        if (in_array($response['status'], [401, 403], true)) {
            throw new ApiError('upstream_access_denied', 'Tesco rejected access. Check the website client key.', 502);
        }
        if ($response['status'] !== 200) {
            throw new ApiError('upstream_unavailable', 'Tesco returned an unsuccessful HTTP response.', 502);
        }
        try {
            $decoded = json_decode($response['body'], true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiError('upstream_invalid_json', 'Tesco returned invalid JSON.');
        }
        if (!is_array($decoded) || !array_is_list($decoded) || count($decoded) !== 1) {
            throw new ApiError('upstream_schema', 'Tesco returned an unrecognized response.');
        }
        $entry = $decoded[0] ?? null;
        if (!is_array($entry)) {
            throw new ApiError('upstream_schema', 'Tesco returned an unrecognized response.');
        }
        if (isset($entry['errors']) && (!is_array($entry['errors']) || !array_is_list($entry['errors']))) {
            throw new ApiError('upstream_schema', 'Tesco returned unrecognized errors.');
        }
        if (($entry['status'] ?? null) === 429) {
            throw new ApiError('upstream_rate_limited', 'Tesco is rate limiting requests. Try again later.', 503);
        }
        if (!empty($entry['errors']) || (isset($entry['status']) && $entry['status'] !== 200)) {
            foreach ($entry['errors'] ?? [] as $error) {
                if (!is_array($error)) {
                    throw new ApiError('upstream_schema', 'Tesco returned an unrecognized error.');
                }
                $status = $error['extensions']['http']['status'] ?? $entry['status'] ?? null;
                if ($status === 429) {
                    throw new ApiError('upstream_rate_limited', 'Tesco is rate limiting requests. Try again later.', 503);
                }
                if ($name === 'Product' && ($status === 404 || ($error['message'] ?? '') === 'product-not-found')) {
                    throw new ApiError('product_not_found', 'Product not found in Tesco Ireland.', 404);
                }
            }
            throw new ApiError('upstream_graphql', 'Tesco could not complete the query.');
        }
        if (!isset($entry['data']) || !is_array($entry['data'])) {
            throw new ApiError('upstream_schema', 'Tesco returned no data.');
        }
        return $entry['data'];
    }
}
