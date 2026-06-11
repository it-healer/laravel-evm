<?php

namespace ItHealer\LaravelEvm;

use Illuminate\Database\Eloquent\Model;
use ItHealer\LaravelEvm\Concerns\Address;
use ItHealer\LaravelEvm\Concerns\Explorer;
use ItHealer\LaravelEvm\Concerns\Mnemonic;
use ItHealer\LaravelEvm\Concerns\Network;
use ItHealer\LaravelEvm\Concerns\Node;
use ItHealer\LaravelEvm\Concerns\Token;
use ItHealer\LaravelEvm\Concerns\Wallet;
use ItHealer\LaravelEvm\Enums\EvmModel;

class Evm
{
    use Network, Node, Explorer, Token, Mnemonic, Address, Wallet;

    /** Standard BIP-44 path used by MetaMask, Trust Wallet and most software wallets. */
    public const PATH_BIP44 = "m/44'/60'/0'/0/{index}";

    /** Path used by Ledger Live. */
    public const PATH_LEDGER_LIVE = "m/44'/60'/{index}'/0/0";

    /** Legacy path used by older Ledger firmware / MyEtherWallet. */
    public const PATH_LEDGER_LEGACY = "m/44'/60'/0'/{index}";

    /**
     * @return class-string<Model>
     */
    public function getModel(EvmModel $model): string
    {
        return config('evm.models.'.$model->value);
    }
}
