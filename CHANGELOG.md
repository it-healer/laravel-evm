# Changelog

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
