<?php

return [
    /*
     * Touch Synchronization System (TSS) config
     * If there are many addresses in the system, we synchronize only those that have been touched recently.
     * You must update touch_at in EvmAddress, if you want sync here.
     */
    'touch' => [
        /*
         * Is system enabled?
         */
        'enabled' => false,

        /*
         * The time during which the address is synchronized after touching it (in seconds).
         */
        'waiting_seconds' => 3600,
    ],

    /*
     * Address synchronization settings.
     */
    'sync' => [
        /*
         * Explorers (Etherscan etc.) index transactions with a delay relative to the
         * node head. Each sync keeps this many blocks of overlap behind the current
         * block, so transactions indexed late are still picked up on the next run.
         * Can be overridden per network via evm_networks.lag_blocks (fast chains
         * like BSC/Polygon need a bigger overlap).
         */
        'lag_blocks' => 20,
    ],

    /*
     * Wallet settings.
     */
    'wallet' => [
        /*
         * Default BIP-44 derivation path template used when creating a wallet.
         * The {index} placeholder is replaced with the address index.
         * Presets: see \ItHealer\LaravelEvm\Concerns\Wallet (PATH_BIP44,
         * PATH_LEDGER_LIVE, PATH_LEDGER_LEGACY).
         */
        'default_derivation_path' => "m/44'/60'/0'/0/{index}",
    ],

    /*
     * Default proxy (socks4|socks5|http|https://[user:pass@]host[:port]) used for
     * ChainList/TokenList catalog requests and as a fallback for nodes and
     * explorers that have no proxy of their own.
     */
    'proxy' => env('EVM_PROXY'),

    /*
     * EVM chains catalog (https://chainid.network) used to pick networks to add.
     */
    'chainlist' => [
        'url' => 'https://chainid.network/chains.json',
        'cache_ttl' => 86400,
    ],

    /*
     * Token lists (https://tokenlists.org format) used to pick tokens per network.
     */
    'token_lists' => [
        'https://tokens.uniswap.org',
    ],

    /*
     * Fee estimation settings for EIP-1559 transactions:
     * maxFeePerGas = base_fee_multiplier * baseFee + maxPriorityFeePerGas.
     */
    'fee' => [
        'base_fee_multiplier' => 2,

        /*
         * Priority fee (in gwei) used when a node does not support
         * the eth_maxPriorityFeePerGas RPC method.
         */
        'fallback_priority_fee_gwei' => '1.5',
    ],

    /*
     * Sets the handler to be used when an EVM Wallet receives a new deposit.
     */
    'webhook_handler' => \ItHealer\LaravelEvm\Webhook\EmptyWebhookHandler::class,

    /*
     * Set model class to allow more customization.
     *
     * Each model must be or extend the corresponding
     * `\ItHealer\LaravelEvm\Models\*` class.
     */
    'models' => [
        'network' => \ItHealer\LaravelEvm\Models\EvmNetwork::class,
        'node' => \ItHealer\LaravelEvm\Models\EvmNode::class,
        'explorer' => \ItHealer\LaravelEvm\Models\EvmExplorer::class,
        'token' => \ItHealer\LaravelEvm\Models\EvmToken::class,
        'wallet' => \ItHealer\LaravelEvm\Models\EvmWallet::class,
        'address' => \ItHealer\LaravelEvm\Models\EvmAddress::class,
        'address_balance' => \ItHealer\LaravelEvm\Models\EvmAddressBalance::class,
        'transaction' => \ItHealer\LaravelEvm\Models\EvmTransaction::class,
        'deposit' => \ItHealer\LaravelEvm\Models\EvmDeposit::class,
    ],
];
