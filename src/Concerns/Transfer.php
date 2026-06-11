<?php

namespace ItHealer\LaravelEvm\Concerns;

use Brick\Math\BigDecimal;
use ItHealer\LaravelEvm\Api\Node\DTO\PreviewTransferDTO;
use ItHealer\LaravelEvm\Api\Node\DTO\TransferDTO;
use ItHealer\LaravelEvm\Models\EvmAddress;
use ItHealer\LaravelEvm\Models\EvmNetwork;
use ItHealer\LaravelEvm\Models\EvmToken;

trait Transfer
{
    public function previewTransfer(
        EvmNetwork|int|string $network,
        EvmAddress $from,
        EvmAddress|string $to,
        BigDecimal|float|int|string $amount,
        ?BigDecimal $balanceBefore = null,
        ?int $gasLimit = null
    ): PreviewTransferDTO {
        $node = $this->getNode($network);
        $node->increment('requests', 3 + ($balanceBefore === null ? 1 : 0));

        if ($to instanceof EvmAddress) {
            $to = $to->address;
        }

        return $node->api()->previewTransfer(
            from: $from->address,
            to: $to,
            amount: BigDecimal::of($amount),
            balanceBefore: $balanceBefore,
            gasLimit: $gasLimit
        );
    }

    public function transfer(
        EvmNetwork|int|string $network,
        EvmAddress $from,
        EvmAddress|string $to,
        BigDecimal|float|int|string $amount,
        ?BigDecimal $balanceBefore = null,
        ?int $gasLimit = null
    ): TransferDTO {
        $node = $this->getNode($network);
        $node->increment('requests', 4 + ($balanceBefore === null ? 1 : 0));

        if ($to instanceof EvmAddress) {
            $to = $to->address;
        }

        return $node->api()->transfer(
            from: $from->address,
            to: $to,
            privateKey: $from->private_key,
            amount: BigDecimal::of($amount),
            balanceBefore: $balanceBefore,
            gasLimit: $gasLimit,
        );
    }

    public function previewTokenTransfer(
        EvmNetwork|int|string $network,
        EvmToken|string $contract,
        EvmAddress $from,
        EvmAddress|string $to,
        BigDecimal|float|int|string $amount,
        ?BigDecimal $balanceBefore = null,
        ?BigDecimal $tokenBalanceBefore = null,
        ?int $gasLimit = null,
    ): PreviewTransferDTO {
        $node = $this->getNode($network);
        $node->increment('requests', 3 + ($balanceBefore === null ? 1 : 0) + ($tokenBalanceBefore === null ? 1 : 0));

        if ($contract instanceof EvmToken) {
            $contract = $contract->address;
        }
        if ($to instanceof EvmAddress) {
            $to = $to->address;
        }

        return $node->api()->previewTokenTransfer(
            contract: $contract,
            from: $from->address,
            to: $to,
            amount: BigDecimal::of($amount),
            balanceBefore: $balanceBefore,
            tokenBalanceBefore: $tokenBalanceBefore,
            gasLimit: $gasLimit,
        );
    }

    public function transferToken(
        EvmNetwork|int|string $network,
        EvmToken|string $contract,
        EvmAddress $from,
        EvmAddress|string $to,
        BigDecimal|float|int|string $amount,
        ?BigDecimal $balanceBefore = null,
        ?BigDecimal $tokenBalanceBefore = null,
        ?int $gasLimit = null,
    ): TransferDTO {
        $node = $this->getNode($network);
        $node->increment('requests', 4 + ($balanceBefore === null ? 1 : 0) + ($tokenBalanceBefore === null ? 1 : 0));

        if ($contract instanceof EvmToken) {
            $contract = $contract->address;
        }
        if ($to instanceof EvmAddress) {
            $to = $to->address;
        }

        return $node->api()->transferToken(
            contract: $contract,
            from: $from->address,
            to: $to,
            privateKey: $from->private_key,
            amount: BigDecimal::of($amount),
            balanceBefore: $balanceBefore,
            tokenBalanceBefore: $tokenBalanceBefore,
            gasLimit: $gasLimit,
        );
    }

    public function previewTransferFromToken(
        EvmNetwork|int|string $network,
        EvmToken|string $contract,
        EvmAddress $collector,
        EvmAddress|string $from,
        EvmAddress|string $to,
        BigDecimal|float|int|string $amount,
        ?BigDecimal $balanceBefore = null,
        ?BigDecimal $tokenBalanceBefore = null,
        ?int $gasLimit = null,
    ): PreviewTransferDTO {
        $node = $this->getNode($network);
        $node->increment('requests', 3 + ($balanceBefore === null ? 1 : 0) + ($tokenBalanceBefore === null ? 1 : 0));

        if ($contract instanceof EvmToken) {
            $contract = $contract->address;
        }
        if ($from instanceof EvmAddress) {
            $from = $from->address;
        }
        if ($to instanceof EvmAddress) {
            $to = $to->address;
        }

        return $node->api()->previewTransferFromToken(
            contract: $contract,
            from: $from,
            to: $to,
            amount: BigDecimal::of($amount),
            balanceBefore: $balanceBefore,
            tokenBalanceBefore: $tokenBalanceBefore,
            gasLimit: $gasLimit,
        );
    }

    public function transferFromToken(
        EvmNetwork|int|string $network,
        EvmToken|string $contract,
        EvmAddress $collector,
        EvmAddress|string $from,
        EvmAddress|string $to,
        BigDecimal|float|int|string $amount,
        ?BigDecimal $balanceBefore = null,
        ?BigDecimal $tokenBalanceBefore = null,
        ?int $gasLimit = null,
    ): TransferDTO {
        $node = $this->getNode($network);
        $node->increment('requests', 4 + ($balanceBefore === null ? 1 : 0) + ($tokenBalanceBefore === null ? 1 : 0));

        if ($contract instanceof EvmToken) {
            $contract = $contract->address;
        }
        if ($from instanceof EvmAddress) {
            $from = $from->address;
        }
        if ($to instanceof EvmAddress) {
            $to = $to->address;
        }

        return $node->api()->transferFromToken(
            contract: $contract,
            from: $from,
            to: $to,
            privateKey: $collector->private_key,
            amount: BigDecimal::of($amount),
            balanceBefore: $balanceBefore,
            tokenBalanceBefore: $tokenBalanceBefore,
            gasLimit: $gasLimit,
        );
    }
}
