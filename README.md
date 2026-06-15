# Laravel EVM

**English** | [Русский](README.ru.md)

A Laravel package for working with **any EVM network** (Ethereum, BSC, Polygon, Arbitrum, Base, ...):
HD wallets, address generation, native coin and ERC-20 token balances, incoming deposit tracking
with webhooks, and outgoing transfers (legacy and EIP-1559).

Unlike single-chain packages, wallets and addresses here are **network-agnostic** — the same
EVM address works in every chain. Networks are first-class records: add Polygon and all your
existing addresses immediately work in it, with balances, transactions and deposits tracked
per network.

## Requirements

- PHP 8.2+ with `ext-gmp`
- Laravel 10 / 11 / 12 / 13

## Installation

```bash
composer require it-healer/laravel-evm
php artisan evm:install   # publishes config + migrations
php artisan migrate
```

Schedule the sync (host app, e.g. `routes/console.php`):

```php
Schedule::command('evm:sync')->everyMinute()->withoutOverlapping()->runInBackground();
```

## Networks

Networks can be created manually or picked from the [chainid.network](https://chainid.network) catalog:

```php
use ItHealer\LaravelEvm\Facades\Evm;

// Manually
$polygon = Evm::createNetwork(
    chainId: 137,
    name: 'polygon',
    currencySymbol: 'POL',
    title: 'Polygon Mainnet',
);

// From the catalog (resolves title, currency, decimals, explorer URL)
$bsc = Evm::createNetworkFromChainList(56);

// Catalog browsing/searching (cached 24h) — for admin UI pickers
$chains = app(\ItHealer\LaravelEvm\Services\ChainList\ChainListService::class);
$chains->search('polygon');   // Collection<ChainDTO>
$chains->find(42161);         // ChainDTO|null
```

Per-network settings: `tx_type` (`null` = auto-detect EIP-1559 by baseFeePerGas, `0` = force legacy,
`2` = force EIP-1559), `lag_blocks` (sync overlap, increase for fast chains like BSC/Polygon),
`confirmations_target`, `active` (deactivated networks are skipped by sync and refuse transfers).

## Nodes (RPC)

Each network needs at least one RPC node. [Alchemy](https://www.alchemy.com) URLs are built automatically:

```php
Evm::createNode($polygon, 'public', 'https://polygon-rpc.com');
Evm::createAlchemyNode($polygon, apiKey: 'YOUR_ALCHEMY_KEY', name: 'alchemy');
```

The node is health-checked before saving. With several nodes per network the least-used
working one is picked automatically (`Evm::getNode($network)`).

## Explorers (transaction history)

Incoming/outgoing transfers are detected via explorer drivers:

| Driver | Coverage | Notes |
|---|---|---|
| `etherscan_v2` | 60+ chains | One [Etherscan](https://etherscan.io/apis) API key for all chains (the rate limit is shared!) |
| `alchemy` | Alchemy networks | Uses `alchemy_getAssetTransfers`; `base_url` must be the Alchemy URL |

```php
use ItHealer\LaravelEvm\Enums\ExplorerDriver;
use ItHealer\LaravelEvm\Services\AlchemyUrlFactory;

Evm::createExplorer($polygon, ExplorerDriver::EtherscanV2, 'etherscan', apiKey: 'ETHERSCAN_KEY');

Evm::createExplorer($polygon, ExplorerDriver::Alchemy, 'alchemy',
    baseURL: AlchemyUrlFactory::make(137, 'YOUR_ALCHEMY_KEY'));
```

## Tokens

Tokens are tracked per network. Create from the contract (3 RPC calls) or from a
[token list](https://tokenlists.org) without any RPC:

```php
// On-chain metadata
Evm::createToken($polygon, '0xc2132D05D31c914a87C6611C10748AEb04B58e8F');

// From token lists (config: evm.token_lists) — for admin UI pickers
$tokens = app(\ItHealer\LaravelEvm\Services\TokenList\TokenListService::class);
$usdt = $tokens->forChain(137)->firstWhere(fn ($t) => $t->symbol() === 'USDT');
Evm::createTokenFromList($polygon, $usdt);
```

Set `active = false` on a token to stop tracking it without deleting its history.

## Wallets & addresses

```php
// New wallet (BIP-39 mnemonic generated, primary address derived at index 0)
$wallet = Evm::createWallet('main', password: 'secret');

// Import an existing mnemonic
$wallet = Evm::createWallet('imported', mnemonic: 'test test ... junk');

// More addresses
$address = Evm::createAddress($wallet, 'Deposit #2');

// Watch-only
Evm::importAddress($wallet, '0x52908400098527886E0F7030069857D2E4169EE7');

// Validation / checksums
Evm::validateAddress('0x...');     // EIP-55 aware
Evm::toChecksumAddress('0x...');
```

### Derivation paths

Different wallets use different BIP-44 paths. The path template is configured **per wallet**
(`{index}` is replaced with the address index):

```php
use ItHealer\LaravelEvm\Evm as EvmCore;

Evm::createWallet('metamask', mnemonic: $m);                                                  // m/44'/60'/0'/0/{index}
Evm::createWallet('ledger', mnemonic: $m, derivationPath: EvmCore::PATH_LEDGER_LIVE);         // m/44'/60'/{index}'/0/0
Evm::createWallet('ledger-old', mnemonic: $m, derivationPath: EvmCore::PATH_LEDGER_LEGACY);   // m/44'/60'/0'/{index}
Evm::createWallet('custom', mnemonic: $m, derivationPath: "m/44'/60'/1'/0/{index}");
```

The default template is `config('evm.wallet.default_derivation_path')`.

Secrets (mnemonic, seed, private keys) are stored AES-256 encrypted; with a wallet `password`
the encryption key is derived from it — unlock with `$wallet->unlockWallet($password)` before
reading private keys or transferring.

## Balances

Balances are stored per (address, network) and refreshed by the sync:

```php
$row = $address->balanceForNetwork($polygon);   // EvmAddressBalance
$row->balance;                                   // BigDecimal, native coin
$row->tokens;                                    // [contract => '123.45']

$wallet->balanceForNetwork($polygon);            // BigDecimal, sum over addresses
$wallet->tokensForNetwork($polygon);             // [contract => BigDecimal]

// Live on-chain queries
Evm::getBalance($polygon, $address);
Evm::getBalanceOfToken($polygon, $address, $usdtToken);
```

## Transfers

All transfer methods take the network first. Fees are estimated automatically:
legacy `gasPrice`, or EIP-1559 `maxFeePerGas = 2 × baseFee + priorityFee` (see `config('evm.fee')`).
Nonces are allocated under a per-(chain, address) cache lock, so concurrent transfers don't clash.

```php
// Preview (no signing): amounts, gas, fee, resulting balances, error if any
$preview = Evm::previewTransfer($polygon, $fromAddress, '0xRecipient', '0.5');

// Native coin
$result = Evm::transfer($polygon, $fromAddress, '0xRecipient', '0.5');
$result->txid();

// ERC-20
Evm::transferToken($polygon, $usdtToken, $fromAddress, '0xRecipient', '100');

// transferFrom (collector pays gas, requires prior approve)
Evm::transferFromToken($polygon, $usdtToken, $collectorAddress, '0xHolder', '0xRecipient', '100');
```

## Sync & deposits

`evm:sync` walks every active network → every wallet → every address: refreshes balances,
imports transaction history via the explorer driver (cursor `sync_block_number` per
address×network with a `lag_blocks` overlap; re-runs are idempotent), records incoming
transfers as `EvmDeposit` and calls your webhook handler for each **new** deposit.

```php
// config/evm.php
'webhook_handler' => \App\Services\WebhookHandlers\EvmWebhookHandler::class,
```

```php
use ItHealer\LaravelEvm\Models\EvmDeposit;
use ItHealer\LaravelEvm\Webhook\WebhookHandlerInterface;

class EvmWebhookHandler implements WebhookHandlerInterface
{
    public function handle(EvmDeposit $deposit): void
    {
        $deposit->network;   // EvmNetwork — branch per chain
        $deposit->symbol;    // 'POL' or token symbol
        $deposit->amount;    // BigDecimal
        $deposit->confirmations;
    }
}
```

Commands:

```
evm:sync                                     # everything (under a cache lock)
evm:network-sync {network}                   # one network (id, chain id or name)
evm:wallet-sync {wallet_id} [--network=]
evm:address-sync {address_id} [--network=] [--force]
evm:node-sync {node_id}                      # health check
evm:explorer-sync {explorer_id}              # health check
```

Sync services support progress/cancellation hooks (for UI-driven resyncs):

```php
(new AddressNetworkSync($address, $network, force: true))
    ->onProgress(fn (int $count, string $stage) => cache()->put($key, $count))
    ->cancelWhen(fn (): bool => (bool) cache()->get($cancelKey))
    ->run();
```

### Adaptive synchronization (touch)

For large installations enable adaptive sync (`evm.touch`) so addresses are polled **often
while in use and rarely while idle**, instead of every run. An address is "active" for
`waiting_seconds` after its last `touch_at` (set on user/merchant activity); while active it
syncs no more often than `fast_interval`, while idle no more often than `slow_interval`.

```php
// config/evm.php
'touch' => [
    'enabled' => true,
    'waiting_seconds' => 1800,  // stay "active" 30 min after last touch
    'fast_interval' => 60,      // while active: at most once per 60s
    'slow_interval' => 3600,    // while idle: at most once per hour (null = skip idle entirely)
],
```

Mark activity by updating `touch_at` whenever the wallet is used (GUI view, API call, unlock):

```php
$address->update(['touch_at' => now()]);
// or in bulk for a wallet:
$wallet->addresses()->update(['touch_at' => now()]);
```

Defaults (`fast_interval` 0, `slow_interval` null) preserve the legacy behavior: active
addresses sync every run, idle ones are skipped. `evm:address-sync --force` bypasses the schedule.

## Real-time deposits with Alchemy webhooks

Polling pays for an explorer scan of every address on every run, even when nothing happened.
[Alchemy Address Activity webhooks](https://www.alchemy.com/docs/reference/address-activity-webhook)
flip this around: Alchemy **pushes** a notification the moment a watched address has on-chain
activity — **incoming or outgoing** — the package verifies its signature and triggers a **targeted**
`AddressNetworkSync` for that address (matched whether it appears as sender or recipient, across every
wallet it belongs to). Deposit detection, outgoing transaction history, dedup and your `webhook_handler`
stay exactly the same — you just stop paying Compute Units for idle polling.

> Free tier: 5 webhooks (= up to 5 networks), up to 100k addresses per webhook. The Notify
> **Auth Token** (dashboard → Webhooks → top-right) is **not** your RPC API key.

### 1. Configure

```dotenv
EVM_ALCHEMY_NOTIFY_AUTH_TOKEN=your-notify-auth-token
EVM_ALCHEMY_WEBHOOK_ENABLED=true
EVM_ALCHEMY_WEBHOOK_URL=https://your-app.com/evm/alchemy/webhook   # public HTTPS receiver
EVM_ALCHEMY_AUTO_SUBSCRIBE=true                                    # subscribe new addresses automatically
```

The receiver route is registered by the package (path `evm.alchemy.webhook.path`, default
`evm/alchemy/webhook`). It is **not** under the `web`/`auth` middleware groups — the HMAC
signature is the authentication — so keep it out of any localized/CSRF-protected prefix.

### 2. Create the webhook and subscribe addresses

```bash
php artisan evm:alchemy-setup polygon --reconcile   # creates the webhook + pushes existing addresses
```

`--reconcile` (or `evm:alchemy-reconcile`) diffs the addresses the package tracks in the network
(available addresses of wallets that have the network attached) against Alchemy's list and applies
the difference (batched to 500/request). Run it again after attaching a network to a wallet, or rely
on `evm.alchemy.auto_subscribe` for incremental add/remove on address create/delete.

```bash
php artisan evm:alchemy-reconcile            # all configured networks
php artisan evm:alchemy-reconcile polygon    # one network
```

Programmatic API (facade):

```php
Evm::ensureAlchemyWebhook($polygon);                 // create or reuse, returns EvmAlchemyWebhook
Evm::subscribeAlchemyAddress($address, $polygon);
Evm::unsubscribeAlchemyAddress($address, $polygon);
Evm::reconcileAlchemyWebhook($polygon);              // ['added' => [...], 'removed' => [...]]
```

### 3. Confirmations

Address Activity webhooks fire **once** (when the tx is mined) and are not re-sent as confirmations
grow. If your handler waits for N confirmations, schedule a cheap, targeted top-up that re-syncs only
addresses with deposits still below `confirmations_target`:

```php
Schedule::command('evm:confirm-deposits')->everyFiveMinutes()->withoutOverlapping();
```

Keep `evm:sync` as a rare fallback (e.g. hourly) for any missed deliveries — re-runs are idempotent.

## Compute Units, cost control & load balancing

Every RPC call on a node and every explorer request is metered in a `credits` counter
(Compute Units) that **resets at the start of each calendar month**. Node and explorer
selection picks the one with the **fewest credits this month**, so load (and Alchemy CU
spend) is distributed automatically across several nodes/explorers of a network.

```php
$node = Evm::getNode($polygon);   // the least-used node this month
$node->credits;                   // CU spent this month
$node->creditsThisMonth();        // same, but 0 if the counter is from a previous month
```

CU costs per method come from `ItHealer\LaravelEvm\Services\Alchemy\ComputeUnits` and mirror
Alchemy's published prices; override any of them in `config('evm.compute_units')`. For non-Alchemy
providers the same table is just a load weight.

### Reducing Alchemy CU

Polling is CU-hungry because `alchemy_getAssetTransfers` costs 150 CU and runs for both
directions on every address, every run. Options, cheapest first:

- **Use webhooks** instead of polling (see above) — you pay CU only when activity happens.
- **`evm.sync.track_outgoing = false`** — detect deposits only (query just `toAddress`),
  halving the `getAssetTransfers` requests on the Alchemy explorer.
- **`evm.sync.block_cache_ttl = N`** (seconds) — fetch `eth_blockNumber` once per network per
  run instead of once per address.
- Stored token decimals are reused automatically, so token balance reads no longer spend an
  extra `decimals()` `eth_call`.
- Add several nodes/explorers per network — requests spread across them by least-credits.

## Model customization

Every model can be replaced in `config('evm.models')` with a subclass — the package
resolves models through this map everywhere.

## Testing

```bash
vendor/bin/pest
```

Address derivation and transaction signing (legacy + EIP-1559) are verified against
ethers.js-generated vectors, explorer drivers and sync against HTTP fakes.

## License

MIT
