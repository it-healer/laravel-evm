<?php

namespace ItHealer\LaravelEvm\Concerns;

use ItHealer\LaravelEvm\Enums\EvmModel;
use ItHealer\LaravelEvm\Models\EvmNetwork;
use ItHealer\LaravelEvm\Models\EvmWallet;

trait Wallet
{
    /**
     * Attach a network to the wallet: it becomes visible and synchronized.
     * Idempotent.
     */
    public function attachNetwork(EvmWallet $wallet, EvmNetwork $network): void
    {
        $wallet->networks()->syncWithoutDetaching([$network->id]);
    }

    /**
     * Detach a network from the wallet: it is no longer shown or synchronized.
     * Already synced data (balances, transactions) stays intact.
     */
    public function detachNetwork(EvmWallet $wallet, EvmNetwork $network): void
    {
        $wallet->networks()->detach($network->id);
    }

    public function hasNetwork(EvmWallet $wallet, EvmNetwork $network): bool
    {
        return $wallet->networks()->whereKey($network->id)->exists();
    }

    public function importWallet(
        string $name,
        string|array $mnemonic,
        ?string $passphrase = null,
        ?string $password = null,
        ?bool $savePassword = true,
        ?string $derivationPath = null,
    ): EvmWallet {
        if (is_array($mnemonic)) {
            $mnemonic = implode(' ', $mnemonic);
        }

        $seed = $this->mnemonicSeed($mnemonic, $passphrase);

        $wallet = $this->makeWalletModel($name, $derivationPath);
        $wallet->unlockWallet($password);
        if ($savePassword) {
            $wallet->password = $password;
        }
        $wallet->mnemonic = $mnemonic;
        $wallet->seed = $seed;

        return $wallet;
    }

    public function generateWallet(
        string $name,
        ?int $mnemonicSize = 18,
        ?string $passphrase = null,
        ?string $password = null,
        ?bool $savePassword = true,
        ?string $derivationPath = null,
    ): EvmWallet {
        $mnemonic = $this->mnemonicGenerate($mnemonicSize ?? 18);
        $seed = $this->mnemonicSeed($mnemonic, $passphrase);

        $wallet = $this->makeWalletModel($name, $derivationPath);
        $wallet->unlockWallet($password);
        if ($savePassword) {
            $wallet->password = $password;
        }
        $wallet->mnemonic = implode(' ', $mnemonic);
        $wallet->seed = $seed;

        return $wallet;
    }

    public function newWallet(
        string $name,
        ?string $password = null,
        ?bool $savePassword = true,
        ?string $derivationPath = null,
    ): EvmWallet {
        $wallet = $this->makeWalletModel($name, $derivationPath);
        $wallet->unlockWallet($password);
        if ($savePassword) {
            $wallet->password = $password;
        }

        return $wallet;
    }

    public function createWallet(
        string $name,
        ?string $password = null,
        ?bool $savePassword = true,
        string|array|int|null $mnemonic = null,
        ?string $passphrase = null,
        ?string $derivationPath = null,
    ): EvmWallet {
        if (is_string($mnemonic)) {
            $mnemonic = explode(' ', $mnemonic);
        } elseif (is_null($mnemonic) || is_int($mnemonic)) {
            $mnemonic = $this->mnemonicGenerate($mnemonic ?? 18);
        }

        $seed = $this->mnemonicSeed($mnemonic, $passphrase);

        $wallet = $this->makeWalletModel($name, $derivationPath);
        $wallet->unlockWallet($password);
        if ($savePassword) {
            $wallet->password = $password;
        }
        $wallet->mnemonic = implode(' ', $mnemonic);
        $wallet->seed = $seed;
        $wallet->save();

        $this->createAddress($wallet, 'Primary Address', 0);

        return $wallet;
    }

    protected function makeWalletModel(string $name, ?string $derivationPath): EvmWallet
    {
        $derivationPath ??= config('evm.wallet.default_derivation_path', \ItHealer\LaravelEvm\Evm::PATH_BIP44);

        if (!$this->validateDerivationPath(str_replace('{index}', '0', $derivationPath))) {
            throw new \InvalidArgumentException("Invalid derivation path template: {$derivationPath}");
        }

        /** @var class-string<EvmWallet> $walletModel */
        $walletModel = $this->getModel(EvmModel::Wallet);

        return new $walletModel([
            'name' => $name,
            'derivation_path' => $derivationPath,
        ]);
    }
}
