<?php

namespace ItHealer\LaravelEvm\Explorer\Drivers;

use Brick\Math\BigDecimal;
use Closure;
use Generator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use ItHealer\LaravelEvm\Api\DTOPaginator;
use ItHealer\LaravelEvm\Explorer\DTO\ExplorerTokenTransactionDTO;
use ItHealer\LaravelEvm\Explorer\DTO\ExplorerTransactionDTO;
use ItHealer\LaravelEvm\Services\Alchemy\ComputeUnits;
use ItHealer\LaravelEvm\Tx\Support\Hex;

/**
 * Alchemy Transfers API (alchemy_getAssetTransfers).
 * https://docs.alchemy.com/reference/alchemy-getassettransfers
 *
 * Incoming and outgoing transfers require separate requests
 * (toAddress / fromAddress); pagination is cursor based (pageKey).
 * Only successful transfers are returned, so isError is always false
 * and `confirmations` is not provided (the sync computes it from the
 * latest block number).
 */
class AlchemyDriver extends BaseExplorerDriver
{
    protected ?string $proxy;

    public function __construct(
        protected string $baseURL,
        ?string $proxy = null,
        protected int $nativeDecimals = 18,
    ) {
        $this->proxy = $this->formatProxy($proxy);
    }

    public function rpc(string $method, array $params = []): mixed
    {
        $client = Http::asJson()
            ->acceptJson()
            ->withOptions([
                'base_uri' => $this->baseURL,
                'timeout' => 60,
                'proxy' => $this->proxy,
            ]);

        $response = $client->post('', [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
            'id' => 1,
        ]);

        $result = $response->json();

        if (isset($result['error'])) {
            throw new \Exception($result['error']['message']);
        }

        if (count($result ?? []) === 0 || !array_key_exists('result', $result)) {
            throw new \Exception($response->body());
        }

        return $result['result'];
    }

    public function getNativeTransactions(
        string $address,
        int $startBlock = 0,
        int $perPage = 100,
        ?Closure $onRequest = null,
    ): DTOPaginator {
        return DTOPaginator::generator(function () use ($address, $startBlock, $perPage, $onRequest): Generator {
            yield from $this->transfers($address, ['external'], null, $startBlock, $perPage, $onRequest, false);
        });
    }

    public function getTokenTransactions(
        string $address,
        ?string $contract = null,
        int $startBlock = 0,
        int $perPage = 100,
        ?Closure $onRequest = null,
    ): DTOPaginator {
        return DTOPaginator::generator(function () use ($address, $contract, $startBlock, $perPage, $onRequest): Generator {
            yield from $this->transfers($address, ['erc20'], $contract, $startBlock, $perPage, $onRequest, true);
        });
    }

    public function healthCheck(): bool
    {
        try {
            $this->rpc('eth_blockNumber');

            return true;
        } catch (\Exception) {
            return false;
        }
    }

    public function creditsPerRequest(): int
    {
        return ComputeUnits::cost('alchemy_getAssetTransfers');
    }

    /**
     * Both directions (incoming then outgoing), each cursor-paginated.
     */
    protected function transfers(
        string $address,
        array $categories,
        ?string $contract,
        int $startBlock,
        int $perPage,
        ?Closure $onRequest,
        bool $isToken,
    ): Generator {
        // Deposit detection only needs incoming (toAddress) transfers. Disabling
        // outgoing tracking halves the alchemy_getAssetTransfers requests (and CU).
        $directions = config('evm.sync.track_outgoing', true)
            ? ['toAddress', 'fromAddress']
            : ['toAddress'];

        foreach ($directions as $direction) {
            $pageKey = null;

            do {
                if ($onRequest) {
                    $onRequest();
                }

                $params = [
                    'fromBlock' => '0x'.dechex($startBlock),
                    'toBlock' => 'latest',
                    'category' => $categories,
                    'withMetadata' => true,
                    'excludeZeroValue' => false,
                    'order' => 'asc',
                    'maxCount' => '0x'.dechex($perPage),
                    $direction => $address,
                ];

                if ($contract) {
                    $params['contractAddresses'] = [$contract];
                }

                if ($pageKey) {
                    $params['pageKey'] = $pageKey;
                }

                $result = $this->rpc('alchemy_getAssetTransfers', [$params]);

                foreach ($result['transfers'] ?? [] as $item) {
                    yield $isToken ? $this->mapToken($item) : $this->mapNative($item);
                }

                $pageKey = $result['pageKey'] ?? null;
            } while ($pageKey !== null);
        }
    }

    /**
     * Asset amount from rawContract (hex value + decimals): the float
     * `value` field of the response loses precision on big amounts.
     */
    protected function rawAmount(array $item, int $defaultDecimals): array
    {
        $rawValue = $item['rawContract']['value'] ?? null;
        $rawDecimals = $item['rawContract']['decimal'] ?? null;
        $decimals = $rawDecimals !== null ? Hex::toInt($rawDecimals) : $defaultDecimals;

        $amount = $rawValue !== null
            ? BigDecimal::ofUnscaledValue(Hex::toBigInteger($rawValue), $decimals)
            : BigDecimal::of((string)($item['value'] ?? '0'));

        return [(string)$amount, $decimals];
    }

    protected function baseAttributes(array $item, string $amount): array
    {
        return [
            'hash' => $item['hash'],
            'block_number' => Hex::toInt($item['blockNum']),
            'timestamp' => Carbon::parse($item['metadata']['blockTimestamp'])->getTimestamp(),
            'from' => $item['from'],
            'to' => $item['to'] ?? '',
            'amount' => $amount,
            'is_error' => false,
            'confirmations' => null,
            'raw' => $item,
        ];
    }

    protected function mapNative(array $item): ExplorerTransactionDTO
    {
        [$amount] = $this->rawAmount($item, $this->nativeDecimals);

        return ExplorerTransactionDTO::make([
            ...$this->baseAttributes($item, $amount),
            'contract_address' => null,
        ]);
    }

    protected function mapToken(array $item): ExplorerTokenTransactionDTO
    {
        [$amount, $decimals] = $this->rawAmount($item, 18);

        return ExplorerTokenTransactionDTO::make([
            ...$this->baseAttributes($item, $amount),
            'contract_address' => $item['rawContract']['address'] ?? '',
            'token_decimals' => $decimals,
            'token_symbol' => $item['asset'] ?? null,
            'token_name' => null,
        ]);
    }
}
