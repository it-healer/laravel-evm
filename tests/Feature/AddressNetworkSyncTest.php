<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use ItHealer\LaravelEvm\Enums\ExplorerDriver;
use ItHealer\LaravelEvm\Facades\Evm;
use ItHealer\LaravelEvm\Models\EvmDeposit;
use ItHealer\LaravelEvm\Models\EvmTransaction;
use ItHealer\LaravelEvm\Services\Sync\AddressNetworkSync;
use ItHealer\LaravelEvm\Webhook\WebhookHandlerInterface;

uses(RefreshDatabase::class);

class SpyWebhookHandler implements WebhookHandlerInterface
{
    public static array $handled = [];

    public function handle(EvmDeposit $deposit): void
    {
        self::$handled[] = $deposit;
    }
}

const ADDR = '0xf39fd6e51aad88f6f4ce6ab8827279cfffb92266';
const USDT = '0xc2132d05d31c914a87c6611c10748aeb04b58e8f';

function fakeSyncApis(): void
{
    Http::fake([
        'rpc.test*' => function ($request) {
            $data = $request->data();

            $result = match ($data['method']) {
                'eth_blockNumber' => '0x200', // 512
                'eth_getBalance' => '0x1bc16d674ec80000', // 2 ETH
                'eth_call' => match (substr($data['params'][0]['data'], 0, 10)) {
                    '0x313ce567' => '0x'.str_pad('6', 64, '0', STR_PAD_LEFT),
                    '0x70a08231' => '0x'.str_pad(dechex(75_000_000), 64, '0', STR_PAD_LEFT), // 75 USDT
                },
            };

            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]);
        },
        'api.etherscan.io/*' => function ($request) {
            $action = $request['action'] ?? '';

            if ($action === 'getapilimit') {
                return Http::response(['status' => '1', 'message' => 'OK', 'result' => []]);
            }

            if ($action === 'txlist') {
                return Http::response(['status' => '1', 'message' => 'OK', 'result' => [
                    [
                        'hash' => '0xnative-in',
                        'blockNumber' => '100',
                        'timeStamp' => '1700000000',
                        'from' => '0x1111111111111111111111111111111111111111',
                        'to' => ADDR,
                        'value' => '500000000000000000', // 0.5
                        'isError' => '0',
                        'confirmations' => '412',
                        'contractAddress' => '',
                    ],
                    [
                        'hash' => '0xnative-out',
                        'blockNumber' => '110',
                        'timeStamp' => '1700000500',
                        'from' => ADDR,
                        'to' => '0x2222222222222222222222222222222222222222',
                        'value' => '100000000000000000', // 0.1
                        'isError' => '0',
                        'confirmations' => '402',
                        'contractAddress' => '',
                    ],
                ]]);
            }

            // tokentx
            return Http::response(['status' => '1', 'message' => 'OK', 'result' => [
                [
                    'hash' => '0xtoken-in',
                    'blockNumber' => '120',
                    'timeStamp' => '1700001000',
                    'from' => '0x3333333333333333333333333333333333333333',
                    'to' => ADDR,
                    'contractAddress' => USDT,
                    'value' => '25000000', // 25 USDT
                    'tokenDecimal' => '6',
                    'tokenSymbol' => 'USDT',
                    'tokenName' => 'Tether USD',
                    'confirmations' => '392',
                ],
            ]]);
        },
    ]);
}

function syncSetup(): array
{
    fakeSyncApis();
    SpyWebhookHandler::$handled = [];
    config()->set('evm.webhook_handler', SpyWebhookHandler::class);

    $network = Evm::createNetwork(137, 'polygon', 'POL');
    Evm::createNode($network, 'node', 'https://rpc.test');
    Evm::createExplorer($network, ExplorerDriver::EtherscanV2, 'etherscan', apiKey: 'KEY');
    $network->tokens()->create([
        'address' => USDT,
        'name' => 'Tether USD',
        'symbol' => 'USDT',
        'decimals' => 6,
    ]);

    $wallet = Evm::createWallet('w', mnemonic: 'test test test test test test test test test test test junk');

    return [$network, $wallet->addresses->first()];
}

it('synchronizes balances, transactions and deposits of an address', function () {
    [$network, $address] = syncSetup();

    (new AddressNetworkSync($address, $network))->run();

    $balanceRow = $address->balanceForNetwork($network)->fresh();

    expect($balanceRow->balance->isEqualTo('2'))->toBeTrue()
        ->and($balanceRow->tokens)->toBe([USDT => '75.000000'])
        ->and($balanceRow->sync_block_number)->toBe(512 - 20)
        ->and(EvmTransaction::count())->toBe(3)
        ->and(EvmDeposit::count())->toBe(2);

    $nativeDeposit = EvmDeposit::whereNull('token_id')->first();
    $tokenDeposit = EvmDeposit::whereNotNull('token_id')->first();

    expect($nativeDeposit->amount->isEqualTo('0.5'))->toBeTrue()
        ->and($nativeDeposit->confirmations)->toBe(412)
        ->and($nativeDeposit->symbol)->toBe('POL')
        ->and($tokenDeposit->amount->isEqualTo('25'))->toBeTrue()
        ->and($tokenDeposit->symbol)->toBe('USDT');

    expect(SpyWebhookHandler::$handled)->toHaveCount(2);

    $outgoing = EvmTransaction::where('txid', '0xnative-out')->first();
    expect($outgoing->type->value)->toBe('out')
        ->and($outgoing->network_id)->toBe($network->id);
});

it('is idempotent on re-run and fires no duplicate webhooks', function () {
    [$network, $address] = syncSetup();

    (new AddressNetworkSync($address, $network))->run();
    SpyWebhookHandler::$handled = [];

    (new AddressNetworkSync($address->fresh(), $network))->run();

    expect(EvmTransaction::count())->toBe(3)
        ->and(EvmDeposit::count())->toBe(2)
        ->and(SpyWebhookHandler::$handled)->toBeEmpty();
});

it('runs the full evm:sync command across active networks', function () {
    [$network] = syncSetup();

    $this->artisan('evm:sync')->assertSuccessful();

    expect(EvmTransaction::count())->toBe(3)
        ->and($network->fresh()->sync_at)->not->toBeNull();
});

it('skips inactive networks in evm:sync', function () {
    [$network] = syncSetup();
    $network->update(['active' => false]);

    $this->artisan('evm:sync')->assertSuccessful();

    expect(EvmTransaction::count())->toBe(0);
});
