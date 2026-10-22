<?php
declare(strict_types=1);
namespace Tesco;
interface Catalog
{
    public function search(string $query, int $page = 1, int $limit = 12): array;
    public function product(string $id): array;
}
