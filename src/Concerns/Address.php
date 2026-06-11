<?php

namespace ItHealer\LaravelEvm\Concerns;

use BIP\BIP44;
use Brick\Math\BigDecimal;
use kornrunner\Keccak;
use ItHealer\LaravelEvm\Enums\EvmModel;
use ItHealer\LaravelEvm\Models\EvmAddress;
use ItHealer\LaravelEvm\Models\EvmNetwork;
use ItHealer\LaravelEvm\Models\EvmToken;
use ItHealer\LaravelEvm\Models\EvmWallet;
use ItHealer\LaravelEvm\Tx\Support\Key;

trait Address
{
    public function createAddress(
        EvmWallet $wallet,
        ?string $title = null,
        ?int $index = null,
        ?string $seed = null,
        ?string $derivationPath = null,
    ): EvmAddress {
        $address = $this->newAddress($wallet, $title, $index, $seed, $derivationPath);
        $address->save();

        return $address;
    }

    public function newAddress(
        EvmWallet $wallet,
        ?string $title = null,
        ?int $index = null,
        ?string $seed = null,
        ?string $derivationPath = null,
    ): EvmAddress {
        if ($index === null) {
            $index = $wallet->addresses()->max('index');
            $index = $index === null ? 0 : ($index + 1);
        }

        if (!$seed) {
            $seed = $wallet->seed;
        }

        if (!$seed) {
            throw new \Exception('Argument Seed is required.');
        }

        $derivationPath ??= $wallet->derivation_path ?? config('evm.wallet.default_derivation_path');

        $derived = $this->deriveFromSeed($seed, $this->resolveDerivationPath($derivationPath, $index));

        /** @var class-string<EvmAddress> $addressModel */
        $addressModel = $this->getModel(EvmModel::Address);

        $address = new $addressModel([
            'address' => $derived['address'],
            'title' => $title,
            'index' => $index,
        ]);
        $address->wallet()->associate($wallet);
        $address->private_key = $derived['private_key'];

        return $address;
    }

    /**
     * Resolves a derivation path template (e.g. "m/44'/60'/0'/0/{index}")
     * into a concrete path for the given address index.
     */
    public function resolveDerivationPath(string $pathTemplate, int $index): string
    {
        $path = str_replace('{index}', (string)$index, $pathTemplate);

        if (!$this->validateDerivationPath($path)) {
            throw new \InvalidArgumentException("Invalid derivation path: {$path}");
        }

        if (!str_contains($pathTemplate, '{index}') && $index > 0) {
            throw new \InvalidArgumentException(
                "Derivation path template \"{$pathTemplate}\" has no {index} placeholder, only index 0 is allowed."
            );
        }

        return $path;
    }

    public function validateDerivationPath(string $path): bool
    {
        return (bool)preg_match("/^m(\/\d+'?)+$/", $path);
    }

    /**
     * @return array{address: string, private_key: string}
     */
    public function deriveFromSeed(string $seed, string $path): array
    {
        $hdKey = BIP44::fromMasterSeed($seed)->derive($path);
        $privateKey = (string)$hdKey->privateKey;

        return [
            'address' => $this->privateKeyToAddress($privateKey),
            'private_key' => $privateKey,
        ];
    }

    public function importAddress(EvmWallet $wallet, string $address): EvmAddress
    {
        return $wallet->addresses()->create([
            'address' => $address,
            'watch_only' => true,
        ]);
    }

    public function validateAddress(string $address): bool
    {
        if (!preg_match('/^0x[a-fA-F0-9]{40}$/', $address)) {
            return false;
        }

        if (strtolower($address) === $address || strtoupper(substr($address, 2)) === substr($address, 2)) {
            return true;
        }

        $addressNoPrefix = substr($address, 2);
        $hash = Keccak::hash(strtolower($addressNoPrefix), 256);

        for ($i = 0; $i < 40; $i++) {
            $char = $addressNoPrefix[$i];
            $expectedCase = hexdec($hash[$i]) > 7 ? strtoupper($char) : strtolower($char);
            if ($char !== $expectedCase) {
                return false;
            }
        }

        return true;
    }

    public function toChecksumAddress(string $address): string
    {
        $address = strtolower(str_replace('0x', '', $address));
        $hash = Keccak::hash($address, 256);

        $checksum = '0x';

        for ($i = 0; $i < strlen($address); $i++) {
            $char = $address[$i];
            $checksum .= (hexdec($hash[$i]) >= 8) ? strtoupper($char) : $char;
        }

        return $checksum;
    }

    public function privateKeyToAddress(string $privateKey): string
    {
        return $this->toChecksumAddress(Key::privateKeyToAddress($privateKey));
    }

    public function getBalance(EvmNetwork $network, string|EvmAddress $address): BigDecimal
    {
        $address = $address instanceof EvmAddress ? $address->address : $address;

        return $this->getNode($network)->getBalance($address);
    }

    public function getBalanceOfToken(
        EvmNetwork $network,
        string|EvmAddress $address,
        string|EvmToken $contract
    ): BigDecimal {
        $address = $address instanceof EvmAddress ? $address->address : $address;
        $contract = $contract instanceof EvmToken ? $contract->address : $contract;

        return $this->getNode($network)->getBalanceOfToken($address, $contract);
    }
}
