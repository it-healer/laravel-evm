<?php

namespace ItHealer\LaravelEvm\Tx\Support;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;

class Hex
{
    public static function strip0x(string $hex): string
    {
        return str_starts_with($hex, '0x') ? substr($hex, 2) : $hex;
    }

    public static function prefix0x(string $hex): string
    {
        return str_starts_with($hex, '0x') ? $hex : '0x'.$hex;
    }

    /**
     * Hex string (with or without 0x) to a BigDecimal of unscaled integer value.
     * Safe for values above 2^53 where hexdec() loses precision.
     */
    public static function toBigDecimal(string $hex, int $scale = 0): BigDecimal
    {
        $value = self::strip0x($hex);
        $value = $value === '' ? '0' : $value;

        return BigDecimal::ofUnscaledValue(BigInteger::fromBase($value, 16), $scale);
    }

    public static function toBigInteger(string $hex): BigInteger
    {
        $value = self::strip0x($hex);

        return BigInteger::fromBase($value === '' ? '0' : $value, 16);
    }

    public static function toInt(string $hex): int
    {
        return self::toBigInteger($hex)->toInt();
    }

    /**
     * BigDecimal integer value to even-length hex string without 0x prefix.
     */
    public static function fromBigDecimal(BigDecimal $value): string
    {
        return self::evenLength($value->toBigInteger()->toBase(16));
    }

    public static function fromBigInteger(BigInteger $value): string
    {
        return self::evenLength($value->toBase(16));
    }

    public static function fromInt(int $value): string
    {
        return self::evenLength(dechex($value));
    }

    /**
     * BigDecimal integer value to a JSON-RPC QUANTITY hex (0x-prefixed, no leading zeros).
     * Node implementations (geth's hexutil.Big) reject quantities with leading zero digits,
     * which the even-length byte encoding produces for odd-nibble values (e.g. 1e18 wei).
     */
    public static function toQuantity(BigDecimal $value): string
    {
        $hex = ltrim($value->toBigInteger()->toBase(16), '0');

        return '0x'.($hex === '' ? '0' : $hex);
    }

    public static function evenLength(string $hex): string
    {
        return strlen($hex) % 2 === 0 ? $hex : '0'.$hex;
    }
}
