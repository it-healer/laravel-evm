<?php

namespace ItHealer\LaravelEvm\Explorer\Drivers;

use Closure;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use ItHealer\LaravelEvm\Api\DTOPaginator;
use ItHealer\LaravelEvm\Explorer\DTO\ExplorerTokenTransactionDTO;
use ItHealer\LaravelEvm\Explorer\DTO\ExplorerTransactionDTO;
use ItHealer\LaravelEvm\Explorer\DTO\GasOracleDTO;

/**
 * Etherscan API V2 (https://docs.etherscan.io/v2-migration): one API key
 * serves 60+ EVM chains, the chain is selected by the `chainid` parameter.
 */
class EtherscanV2Driver extends BaseExplorerDriver
{
    public const DEFAULT_BASE_URL = 'https://api.etherscan.io/v2/api';

    protected ?string $proxy;

    public function __construct(
        protected int $chainId,
        protected string $apiKey,
        protected ?string $baseURL = null,
        ?string $proxy = null,
        protected int $nativeDecimals = 18,
    ) {
        $this->baseURL = $baseURL ?: self::DEFAULT_BASE_URL;
        $this->proxy = $this->formatProxy($proxy);
    }

    public function request(array $params): mixed
    {
        $client = Http::asJson()
            ->acceptJson()
            ->withOptions([
                'base_uri' => $this->baseURL,
                'timeout' => 60,
                'proxy' => $this->proxy,
            ]);

        $response = $client->get('', [
            // Etherscan API V2 requires an explicit chain id
            'chainid' => $this->chainId,
            ...$params,
            'apikey' => $this->apiKey,
        ]);

        $result = $response->json();

        if (isset($result['error'])) {
            throw new \Exception($result['error']['message'] ?? $result['error']);
        }

        if (count($result ?? []) === 0) {
            throw new \Exception($response->body());
        }

        if (($result['status'] ?? null) !== '1') {
            $resultText = is_string($result['result'] ?? null) ? $result['result'] : json_encode($result['result'] ?? null);
            $haystack = Str::lower(($result['message'] ?? '').' '.$resultText);

            // Empty result set is not an error
            if (Str::contains($haystack, 'no transactions found')) {
                return [];
            }

            // Real API errors (deprecated endpoint, invalid key, rate limit, …)
            // must surface instead of being silently treated as "no transactions"
            throw new \Exception('Explorer API error: '.($resultText ?: ($result['message'] ?? 'unknown error')));
        }

        return $result['result'];
    }

    public function getNativeTransactions(
        string $address,
        int $startBlock = 0,
        int $perPage = 100,
        ?Closure $onRequest = null,
    ): DTOPaginator {
        return DTOPaginator::pages(function (int $page) use ($address, $startBlock, $perPage, $onRequest) {
            if ($onRequest) {
                $onRequest();
            }

            $data = $this->request([
                'module' => 'account',
                'action' => 'txlist',
                'address' => $address,
                'startblock' => $startBlock,
                'endblock' => '99999999',
                'sort' => 'asc',
                'page' => $page,
                'offset' => $perPage,
            ]);

            return array_map(fn (array $item) => $this->mapNative($item), $data);
        }, $perPage);
    }

    public function getTokenTransactions(
        string $address,
        ?string $contract = null,
        int $startBlock = 0,
        int $perPage = 100,
        ?Closure $onRequest = null,
    ): DTOPaginator {
        return DTOPaginator::pages(function (int $page) use ($address, $contract, $startBlock, $perPage, $onRequest) {
            if ($onRequest) {
                $onRequest();
            }

            $params = [
                'module' => 'account',
                'action' => 'tokentx',
                'address' => $address,
                'startblock' => $startBlock,
                'endblock' => '99999999',
                'sort' => 'asc',
                'page' => $page,
                'offset' => $perPage,
            ];

            if ($contract) {
                $params['contractaddress'] = $contract;
            }

            $data = $this->request($params);

            return array_map(fn (array $item) => $this->mapToken($item), $data);
        }, $perPage);
    }

    public function healthCheck(): bool
    {
        try {
            $this->request([
                'module' => 'getapilimit',
                'action' => 'getapilimit',
            ]);

            return true;
        } catch (\Exception) {
            return false;
        }
    }

    public function getGasOracle(): GasOracleDTO
    {
        $data = $this->request([
            'module' => 'gastracker',
            'action' => 'gasoracle',
        ]);

        return GasOracleDTO::make($data);
    }

    protected function mapNative(array $item): ExplorerTransactionDTO
    {
        return ExplorerTransactionDTO::make([
            'hash' => $item['hash'],
            'block_number' => (int)$item['blockNumber'],
            'timestamp' => (int)$item['timeStamp'],
            'from' => $item['from'],
            'to' => $item['to'] ?? '',
            'amount' => (string)\Brick\Math\BigDecimal::ofUnscaledValue($item['value'], $this->nativeDecimals),
            'is_error' => (bool)($item['isError'] ?? false),
            'confirmations' => isset($item['confirmations']) ? (int)$item['confirmations'] : null,
            'contract_address' => $item['contractAddress'] ?? null,
            'raw' => $item,
        ]);
    }

    protected function mapToken(array $item): ExplorerTokenTransactionDTO
    {
        $decimals = (int)($item['tokenDecimal'] ?? 18);

        return ExplorerTokenTransactionDTO::make([
            'hash' => $item['hash'],
            'block_number' => (int)$item['blockNumber'],
            'timestamp' => (int)$item['timeStamp'],
            'from' => $item['from'],
            'to' => $item['to'] ?? '',
            'contract_address' => $item['contractAddress'],
            'amount' => (string)\Brick\Math\BigDecimal::ofUnscaledValue($item['value'], $decimals),
            'token_decimals' => $decimals,
            'token_symbol' => $item['tokenSymbol'] ?? null,
            'token_name' => $item['tokenName'] ?? null,
            'is_error' => false,
            'confirmations' => isset($item['confirmations']) ? (int)$item['confirmations'] : null,
            'raw' => $item,
        ]);
    }
}
