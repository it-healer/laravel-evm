<?php

namespace ItHealer\LaravelEvm\Api\Node;

use Illuminate\Support\Str;
use ItHealer\LaravelEvm\Tx\Support\Hex;

/**
 * ABI encoding of Multicall3 `aggregate3((address target, bool allowFailure, bytes callData)[])`
 * and decoding of its `(bool success, bytes returnData)[]` result. Multicall3 is deployed at the
 * same address on most EVM networks (https://www.multicall3.com), so many read calls
 * (balances of many addresses and tokens) cost one eth_call instead of one each.
 */
class Multicall3
{
    public const ADDRESS = '0xcA11bde05977b3631167028862bE2a173976CA11';

    /** aggregate3((address,bool,bytes)[]) */
    public const AGGREGATE3 = '82ad56cb';

    /** getEthBalance(address) of Multicall3 itself */
    public const GET_ETH_BALANCE = '4d2301cc';

    /** ERC-20 balanceOf(address) */
    public const BALANCE_OF = '70a08231';

    /**
     * @param  list<array{target: string, callData: string, allowFailure?: bool}>  $calls
     */
    public static function encodeAggregate3(array $calls): string
    {
        $count = count($calls);
        $heads = [];
        $bodies = [];
        $offset = 32 * $count;

        foreach ($calls as $call) {
            $data = Hex::strip0x($call['callData']);
            $length = intdiv(strlen($data), 2);
            $padded = str_pad($data, (int)ceil($length / 32) * 64, '0');

            $body = self::word(Hex::strip0x(Str::lower($call['target'])))
                .self::word(($call['allowFailure'] ?? true) ? '1' : '0')
                .self::word(dechex(96))
                .self::word(dechex($length))
                .$padded;

            $heads[] = self::word(dechex($offset));
            $bodies[] = $body;
            $offset += intdiv(strlen($body), 2);
        }

        return '0x'.self::AGGREGATE3
            .self::word(dechex(32))
            .self::word(dechex($count))
            .implode('', $heads)
            .implode('', $bodies);
    }

    /**
     * @return list<array{success: bool, returnData: string}> returnData without 0x
     */
    public static function decodeAggregate3(string $hex): array
    {
        $data = Hex::strip0x($hex);
        $arrayAt = self::readInt($data, 0);
        $count = self::readInt($data, $arrayAt);
        $bodyAt = $arrayAt + 32;
        $results = [];

        for ($i = 0; $i < $count; $i++) {
            $elementAt = $bodyAt + self::readInt($data, $bodyAt + 32 * $i);
            $success = self::readInt($data, $elementAt) === 1;
            $bytesAt = $elementAt + self::readInt($data, $elementAt + 32);
            $length = self::readInt($data, $bytesAt);

            $results[] = [
                'success' => $success,
                'returnData' => substr($data, ($bytesAt + 32) * 2, $length * 2),
            ];
        }

        return $results;
    }

    public static function getEthBalanceCall(string $address): string
    {
        return '0x'.self::GET_ETH_BALANCE.self::word(Hex::strip0x(Str::lower($address)));
    }

    public static function balanceOfCall(string $address): string
    {
        return '0x'.self::BALANCE_OF.self::word(Hex::strip0x(Str::lower($address)));
    }

    protected static function word(string $hex): string
    {
        return str_pad($hex, 64, '0', STR_PAD_LEFT);
    }

    /**
     * A 32-byte word at the byte offset as an int: offsets and lengths of a response fit easily.
     */
    protected static function readInt(string $data, int $byteOffset): int
    {
        $word = substr($data, $byteOffset * 2, 64);

        if (strlen($word) !== 64) {
            throw new \UnexpectedValueException('Multicall3 response is truncated');
        }

        return Hex::toInt($word);
    }
}
