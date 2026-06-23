<?php

return [
    /*
     * Touch Synchronization System (TSS) config
     * If there are many addresses in the system, we synchronize only those that have been touched recently.
     * You must update touch_at in EvmAddress, if you want sync here.
     */
    'touch' => [
        /*
         * Is the adaptive (touch-based) synchronization enabled?
         * When enabled, addresses are synced frequently while in use and rarely while idle.
         */
        'enabled' => false,

        /*
         * Active window: an address is considered "in use" for this many seconds after its
         * last touch (touch_at — set on user/merchant activity).
         */
        'waiting_seconds' => 3600,

        /*
         * Minimum seconds between syncs while the address is active (0 = every run).
         */
        'fast_interval' => 0,

        /*
         * Minimum seconds between syncs while the address is idle.
         * null = skip idle addresses entirely (legacy behavior).
         */
        'slow_interval' => null,
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

        /*
         * Track outgoing transfers (fromAddress) in addition to incoming ones.
         * Disable to detect only deposits — on the Alchemy explorer this halves the
         * alchemy_getAssetTransfers requests (and Compute Units) per sync, at the cost
         * of not recording outgoing EvmTransaction rows from the explorer history.
         */
        'track_outgoing' => (bool)env('EVM_SYNC_TRACK_OUTGOING', true),

        /*
         * Cache the latest block number per network for this many seconds. A value > 0
         * means a multi-address sync run fetches eth_blockNumber once instead of once
         * per address. Keep small (a few seconds) so confirmations stay fresh; 0 disables.
         */
        'block_cache_ttl' => (int)env('EVM_SYNC_BLOCK_CACHE_TTL', 0),
    ],

    /*
     * Broadcast-but-unconfirmed outgoing transfers are subtracted from the confirmed
     * balance to show a truthful "available" balance. They leave the pending set once
     * mined (block_number) or reconciled during sync: a transfer whose nonce is already
     * confirmed was mined or replaced, while a still-next transfer the node no longer
     * knows (eth_getTransactionByHash returns null) was evicted from the mempool. The
     * node is asked only after `dropped_grace_seconds` so a freshly broadcast transfer
     * the node has not yet registered is not dropped prematurely. `ttl_minutes` is a
     * last-resort safety net (null relies solely on node reconciliation).
     */
    'pending' => [
        'ttl_minutes' => env('EVM_PENDING_TTL_MINUTES') !== null
            ? (int)env('EVM_PENDING_TTL_MINUTES')
            : null,

        'dropped_grace_seconds' => (int)env('EVM_PENDING_DROPPED_GRACE_SECONDS', 60),
    ],

    /*
     * Compute Unit (CU) cost overrides per RPC method, used to meter `credits` spent on
     * each node/explorer (reset monthly) and to pick the least-used one. Defaults mirror
     * Alchemy's published costs — see \ItHealer\LaravelEvm\Services\Alchemy\ComputeUnits.
     *
     * Example: 'compute_units' => ['alchemy_getAssetTransfers' => 150, 'eth_call' => 26],
     */
    'compute_units' => [],

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
     * Alchemy Notify (Address Activity Webhooks).
     *
     * Instead of (or in addition to) polling `evm:sync`, Alchemy can push a real-time
     * notification whenever a watched address has on-chain activity. The package
     * receives that push, verifies its HMAC signature and triggers a targeted
     * AddressNetworkSync for the involved address — so the deposit detection,
     * dedup and your `webhook_handler` keep working exactly as with polling,
     * but only when something actually happens (drastically fewer Compute Units).
     *
     * Docs: https://www.alchemy.com/docs/reference/address-activity-webhook
     */
    'alchemy' => [
        /*
         * Notify Auth Token (dashboard.alchemy.com → Webhooks → top-right "AUTH TOKEN").
         * This is NOT your RPC API key — it authorizes the Notify management API.
         */
        'auth_token' => env('EVM_ALCHEMY_NOTIFY_AUTH_TOKEN'),

        /*
         * Notify management API base URL.
         */
        'api_url' => 'https://dashboard.alchemy.com/api',

        /*
         * Inbound webhook receiver (the endpoint Alchemy POSTs notifications to).
         */
        'webhook' => [
            /*
             * Register the package route that receives Alchemy notifications.
             * Disabled by default so the package adds no public route unless opted in.
             */
            'enabled' => (bool)env('EVM_ALCHEMY_WEBHOOK_ENABLED', false),

            /*
             * URI path the receiver listens on (no leading slash). It must match the
             * webhook URL registered in Alchemy (see `url` below). Keep it outside any
             * localized/auth route groups of your app — the signature is the auth.
             */
            'path' => env('EVM_ALCHEMY_WEBHOOK_PATH', 'evm/alchemy/webhook'),

            /*
             * Public URL registered in Alchemy when creating a webhook. Defaults to
             * config('app.url') + path. Set explicitly behind proxies/CDNs.
             */
            'url' => env('EVM_ALCHEMY_WEBHOOK_URL'),

            /*
             * Extra middleware applied to the receiver route (e.g. a throttle).
             */
            'middleware' => [],
        ],

        /*
         * Automatically add/remove an address in the matching Alchemy webhook when an
         * EvmAddress is created/deleted. Only networks that already have a webhook
         * (created via `evm:alchemy-setup`) are affected. Use `evm:alchemy-reconcile`
         * for the authoritative full sync (e.g. after attaching a network to a wallet).
         */
        'auto_subscribe' => (bool)env('EVM_ALCHEMY_AUTO_SUBSCRIBE', false),

        /*
         * Queue used for the sync job triggered by an inbound webhook (null = default).
         */
        'queue' => [
            'connection' => env('EVM_ALCHEMY_QUEUE_CONNECTION'),
            'name' => env('EVM_ALCHEMY_QUEUE_NAME'),
        ],
    ],

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
        'alchemy_webhook' => \ItHealer\LaravelEvm\Models\EvmAlchemyWebhook::class,
    ],
];
