<?php

namespace ItHealer\LaravelEvm\Concerns;

use Illuminate\Support\Str;
use ItHealer\LaravelEvm\Enums\EvmModel;
use ItHealer\LaravelEvm\Models\EvmNetwork;
use ItHealer\LaravelEvm\Models\EvmNode;
use ItHealer\LaravelEvm\Models\EvmToken;
use ItHealer\LaravelEvm\Services\TokenList\DTO\TokenInfoDTO;

trait Token
{
    /**
     * Creates a token by reading its metadata (name, symbol, decimals) from the contract.
     */
    public function createToken(EvmNetwork $network, string $contract, ?EvmNode $node = null): EvmToken
    {
        $contract = Str::lower($contract);

        if (!$node) {
            $node = $this->getNode($network);
        }

        $node->increment('requests', 3);

        $api = $node->api();
        $name = $api->getTokenName($contract);
        $symbol = $api->getTokenSymbol($contract);
        $decimals = $api->getTokenDecimals($contract);

        /** @var class-string<EvmToken> $model */
        $model = $this->getModel(EvmModel::Token);

        return $model::create([
            'network_id' => $network->id,
            'address' => $contract,
            'name' => $name,
            'symbol' => $symbol,
            'decimals' => $decimals,
        ]);
    }

    /**
     * Creates a token from token list metadata without any RPC calls.
     */
    public function createTokenFromList(EvmNetwork $network, TokenInfoDTO $token): EvmToken
    {
        if ($token->chainId() !== $network->chain_id) {
            throw new \InvalidArgumentException(
                "Token chain id {$token->chainId()} does not match network chain id {$network->chain_id}."
            );
        }

        /** @var class-string<EvmToken> $model */
        $model = $this->getModel(EvmModel::Token);

        return $model::create([
            'network_id' => $network->id,
            'address' => Str::lower($token->address()),
            'name' => $token->name(),
            'symbol' => $token->symbol(),
            'decimals' => $token->decimals(),
            'logo_uri' => $token->logoUri(),
        ]);
    }
}
