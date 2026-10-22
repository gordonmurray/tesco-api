<?php
declare(strict_types=1);
namespace Tesco;

final class ApiError extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 502)
    {
        parent::__construct($message);
    }
}
