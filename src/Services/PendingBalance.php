<?php

namespace ItHealer\LaravelEvm\Services;

use Brick\Math\BigDecimal;
use Illuminate\Support\Str;
use ItHealer\LaravelEvm\Enums\EvmModel;
use ItHealer\LaravelEvm\Facades\Evm;
use ItHealer\LaravelEvm\Models\EvmTransaction;

/**
 * Computes the in-flight value of broadcast-but-not-yet-mined outgoing
 * transfers, so the UI can show a truthful "available" balance immediately
 * after a withdrawal instead of the stale confirmed balance.
 */
class PendingBalance
{
    /**
     * Sum of pending outgoing transfers per address for a network, in one query.
     *
     * @param  string[]  $addresses
     * @return array<string, array{native: BigDecimal, fee: BigDecimal, tokens: array<string, BigDecimal>}>
     *                                Keyed by lowercased address. Missing addresses mean "nothing pending".
     */
    public static function forAddresses(int $networkId, array $addresses): array
    {
        $addresses = array_values(array_unique(array_map(
            fn (string $address) => Str::lower($address),
            $addresses
        )));

        if ($addresses === []) {
            return [];
        }

        /** @var class-string<EvmTransaction> $model */
        $model = Evm::getModel(EvmModel::Transaction);

        $placeholders = implode(',', array_fill(0, count($addresses), '?'));

        $rows = $model::query()
            ->pendingOutgoing()
            ->where('network_id', $networkId)
            ->whereRaw("LOWER(address) IN ($placeholders)", $addresses)
            ->selectRaw('LOWER(address) as address_key, token_address, SUM(amount) as amount_sum, SUM(fee) as fee_sum')
            ->groupBy('address_key', 'token_address')
            ->toBase()
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $key = $row->address_key;
            $result[$key] ??= ['native' => BigDecimal::zero(), 'fee' => BigDecimal::zero(), 'tokens' => []];

            $result[$key]['fee'] = $result[$key]['fee']->plus((string) ($row->fee_sum ?? '0'));

            $contract = (string) $row->token_address;
            $amount = BigDecimal::of((string) ($row->amount_sum ?? '0'));

            if ($contract === '') {
                $result[$key]['native'] = $result[$key]['native']->plus($amount);
            } else {
                $result[$key]['tokens'][$contract] = ($result[$key]['tokens'][$contract] ?? BigDecimal::zero())->plus($amount);
            }
        }

        return $result;
    }

    /**
     * Pending sums for a single address (lowercased key lookup of forAddresses).
     *
     * @return array{native: BigDecimal, fee: BigDecimal, tokens: array<string, BigDecimal>}
     */
    public static function forAddress(int $networkId, string $address): array
    {
        $sums = static::forAddresses($networkId, [$address]);

        return $sums[Str::lower($address)]
            ?? ['native' => BigDecimal::zero(), 'fee' => BigDecimal::zero(), 'tokens' => []];
    }

    /**
     * Available native balance = confirmed balance − pending native amount − pending fees, floored at 0.
     *
     * @param  array{native: BigDecimal, fee: BigDecimal, tokens: array<string, BigDecimal>}  $pending
     */
    public static function availableNative(BigDecimal $balance, array $pending): BigDecimal
    {
        $available = $balance->minus($pending['native'])->minus($pending['fee']);

        return $available->isNegative() ? BigDecimal::zero() : $available;
    }

    /**
     * Available token balance = confirmed token balance − pending token amount, floored at 0.
     *
     * @param  array{native: BigDecimal, fee: BigDecimal, tokens: array<string, BigDecimal>}  $pending
     */
    public static function availableToken(string $contract, BigDecimal|string|int|float|null $tokenBalance, array $pending): BigDecimal
    {
        $balance = BigDecimal::of($tokenBalance ?? 0);
        $available = $balance->minus($pending['tokens'][$contract] ?? BigDecimal::zero());

        return $available->isNegative() ? BigDecimal::zero() : $available;
    }
}
