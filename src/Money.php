<?php
declare(strict_types=1);
namespace Tesco;

final class Money
{
    public static function cents(mixed $value): ?int
    {
        if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value)) {
            return null;
        }
        $amount = (float) $value;
        if (!is_finite($amount) || $amount < 0 || $amount > 1000000) {
            return null;
        }
        // Round decimal digits before integer arithmetic; 1.005 * 100 can
        // become 100.499999... in binary floating point.
        $decimal = (string) $value;
        if (!preg_match('/^\d+(?:\.\d+)?$/D', $decimal)) {
            $decimal = sprintf('%.8F', $amount);
        }
        [$whole, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');
        $fraction = str_pad($fraction, 3, '0');
        return (int) $whole * 100 + (int) substr($fraction, 0, 2) + ((int) $fraction[2] >= 5 ? 1 : 0);
    }
}
