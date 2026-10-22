<?php
declare(strict_types=1);
namespace Tesco\Tests;
use Tesco\Transport;
final class FakeTransport implements Transport
{
    public array $operations = [];
    public function __construct(public array $response) {}
    public function send(array $operation): array
    {
        $this->operations[] = $operation;
        return $this->response;
    }
}
