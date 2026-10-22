<?php
declare(strict_types=1);
namespace Tesco;

interface Transport
{
    /** @return array{status: int, body: string} */
    public function send(array $operation): array;
}
