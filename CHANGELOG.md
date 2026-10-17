# Changelog

## v1.8.2 — 2026-10-17

- Change: the recent-blocks tip is the median of each block's 25th-percentile tip instead of the
  50th — on Polygon the median swings between ~80 and ~160 gwei on spikes while a quarter of the
  included transactions pay much less; the 25th percentile still matches what gets into blocks.

## v1.8.1 — 2026-10-17

- Fix: EIP-1559 fees no longer rely only on `eth_maxPriorityFeePerGas` — some nodes suggest a zero
  or stale tip and the transaction waits in the mempool for minutes. The priority fee is now the
  largest of the node's suggestion, the median tip of the last 10 blocks (`eth_feeHistory`) and
  `evm.fee.min_priority_fee_gwei` (default 0, `EVM_MIN_PRIORITY_FEE_GWEI`); `maxFeePerGas` is based
  on the next block's base fee when it is higher than the latest one. Nodes without
  `eth_feeHistory` behave as before. Legacy networks are unchanged.

## v1.8.0 — 2026-10-15

- Add: `Evm::previewCall()` / `Evm::sendCall()` (and `NodeApi::previewCall()` / `sendCall()`) —
  arbitrary transactions with native value and call data, sent through the same nonce-safe path as
  transfers. Without a gas limit the estimate is padded by `evm.fee.call_gas_buffer` (default 1.2);
  a call that would revert returns a preview with `error` instead of throwing.

## v1.3.1 — 2026-06-15

- Fix: `eth_estimateGas` failed with "cannot unmarshal hex number with leading zero digits
  into ... TransactionArgs.value" for amounts whose wei value has an odd number of hex digits
  (e.g. exactly 1 POL/ETH = 1e18). The `value` is now encoded as a canonical JSON-RPC QUANTITY
  (no leading zeros) via `Hex::toQuantity()`; transaction signing (byte/RLP encoding) is unchanged.

## v1.3.0 — 2026-06-15

- Add: **adaptive (touch-based) synchronization**. `evm.touch` now supports `fast_interval`
  (max sync frequency while an address is active) and `slow_interval` (while idle; `null` = skip
  idle addresses entirely). `waiting_seconds` is the active window after the last `touch_at`.
  Addresses are polled often while in use and rarely while idle, cutting RPC/API/CU usage.
  Defaults (`fast_interval` 0, `slow_interval` null) preserve the previous behavior.

## v1.2.1 — 2026-06-15

- Fix: `AlchemyNotifyClient::deleteWebhook()` now passes `webhook_id` as a query parameter
  (Alchemy rejected it in the request body with a 400 ValidationError).
- Docs: add a Russian translation of the README (`README.ru.md`) with a language switcher.

## v1.2.0 — 2026-06-15

- Add: **Alchemy Notify (Address Activity webhooks)** as a push alternative to polling. The package
  receives the notification, verifies its HMAC signature and triggers a targeted `AddressNetworkSync`
  for the involved address — deposit detection, dedup and your `webhook_handler` stay unchanged, but
  run only when activity actually happens (drastically fewer Compute Units than minute-by-minute polling).
  - New model/table `EvmAlchemyWebhook` (one webhook per network, encrypted signing key).
  - `AlchemyNotifyClient` (Notify management API) and `Evm::ensureAlchemyWebhook()`,
    `subscribeAlchemyAddress()`, `unsubscribeAlchemyAddress()`, `reconcileAlchemyWebhook()`.
  - Receiver route (opt-in via `evm.alchemy.webhook.enabled`), `SyncEvmAddressJob`,
    optional auto-subscribe of new addresses (`evm.alchemy.auto_subscribe`).
  - Commands: `evm:alchemy-setup`, `evm:alchemy-reconcile`, `evm:confirm-deposits`.
- Add: **Compute Unit (CU) metering and least-credits load balancing.** Nodes and explorers now
  track `credits` spent per method (mirroring Alchemy's CU costs, overridable via
  `config('evm.compute_units')`); the counter resets monthly and `getNode()`/`getExplorer()` pick
  the least-used one, distributing load across several nodes/explorers.
- Add: CU optimizations —
  - `evm.sync.track_outgoing` (default `true`): set to `false` to detect deposits only and halve
    the Alchemy `getAssetTransfers` requests.
  - `evm.sync.block_cache_ttl` (default `0`): cache `eth_blockNumber` per network for N seconds so
    a multi-address run fetches it once instead of once per address.
  - Token balance reads reuse the stored `EvmToken` decimals instead of spending a `decimals()`
    `eth_call` on every read.

## v1.1.1 — 2026-06-15

- Fix: `has_password`/`has_mnemonic`/`has_seed` accessors no longer decrypt the stored value
  to test for presence. They now inspect the raw attribute, so serializing a locked wallet
  (e.g. via `toArray()`/Inertia props without prior `unlockWallet()`) no longer throws
  `DecryptException: The MAC is invalid`.

## v1.0.0 — 2026-06-11

Initial release.

- Network-agnostic HD wallets and addresses (one address — all EVM chains), per-wallet
  BIP-44 derivation path templates (MetaMask, Ledger Live, Ledger Legacy presets, custom paths).
- Networks as first-class records with the chainid.network catalog service.
- RPC nodes per network with health checks, request-based load balancing and an Alchemy URL factory.
- Explorer drivers for transaction history: Etherscan API V2 (one key, 60+ chains) and
  Alchemy Transfers API (cursor pagination, rawContract-precision amounts).
- ERC-20 tokens per network, created from the contract or from tokenlists.org lists.
- Per-(address, network) balances, token balances and sync cursors.
- Outgoing transfers: legacy (type 0) and EIP-1559 (type 2) with auto-detection by baseFeePerGas,
  fee estimation, safe nonce allocation per (chain, address) under a cache lock.
  Signing verified against ethers.js test vectors.
- Deposit tracking with idempotent sync (lag-blocks overlap) and webhook handler interface.
- Console commands: evm:sync, evm:network-sync, evm:wallet-sync, evm:address-sync,
  evm:node-sync, evm:explorer-sync.
