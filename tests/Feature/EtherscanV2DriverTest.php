<?php

use Illuminate\Support\Facades\Http;
use ItHealer\LaravelEvm\Explorer\Drivers\EtherscanV2Driver;

function etherscanDriver(): EtherscanV2Driver
{
    return new EtherscanV2Driver(chainId: 137, apiKey: 'KEY');
}

function etherscanTx(array $overrides = []): array
{
    return [
        'hash' => '0xhash1',
        'blockNumber' => '1000',
        'timeStamp' => '1700000000',
        'from' => '0xAAA0000000000000000000000000000000000001',
        'to' => '0xBBB0000000000000000000000000000000000002',
        'value' => '1500000000000000000',
        'isError' => '0',
        'confirmations' => '12',
        'contractAddress' => '',
        ...$overrides,
    ];
}

it('passes chainid and apikey on every request', function () {
    Http::fake(['api.etherscan.io/*' => Http::response(['status' => '1', 'message' => 'OK', 'result' => []])]);

    iterator_to_array(etherscanDriver()->getNativeTransactions('0xabc'));

    Http::assertSent(function ($request) {
        return $request['chainid'] == 137
            && $request['apikey'] === 'KEY'
            && $request['action'] === 'txlist'
            && $request['sort'] === 'asc';
    });
});

it('paginates native transactions until a short page', function () {
    $page1 = array_map(fn ($i) => etherscanTx(['hash' => "0xh{$i}"]), range(1, 2));
    $page2 = [etherscanTx(['hash' => '0xh3'])];

    Http::fakeSequence('api.etherscan.io/*')
        ->push(['status' => '1', 'message' => 'OK', 'result' => $page1])
        ->push(['status' => '1', 'message' => 'OK', 'result' => $page2]);

    $requests = 0;
    $items = iterator_to_array(
        etherscanDriver()->getNativeTransactions('0xabc', perPage: 2, onRequest: function () use (&$requests) {
            $requests++;
        })
    );

    expect($items)->toHaveCount(3)
        ->and($requests)->toBe(2)
        ->and($items[0]->hash())->toBe('0xh1')
        ->and($items[0]->amount()->isEqualTo('1.5'))->toBeTrue()
        ->and($items[0]->blockNumber())->toBe(1000)
        ->and($items[0]->confirmations())->toBe(12)
        ->and($items[0]->isError())->toBeFalse()
        ->and($items[0]->from())->toBe('0xaaa0000000000000000000000000000000000001');
});

it('treats "No transactions found" as an empty result', function () {
    Http::fake([
        'api.etherscan.io/*' => Http::response([
            'status' => '0',
            'message' => 'No transactions found',
            'result' => [],
        ]),
    ]);

    expect(iterator_to_array(etherscanDriver()->getNativeTransactions('0xabc')))->toBeEmpty();
});

it('surfaces real API errors instead of swallowing them', function () {
    Http::fake([
        'api.etherscan.io/*' => Http::response([
            'status' => '0',
            'message' => 'NOTOK',
            'result' => 'Max rate limit reached',
        ]),
    ]);

    iterator_to_array(etherscanDriver()->getNativeTransactions('0xabc'));
})->throws(Exception::class, 'Max rate limit reached');

it('maps token transactions with token decimals', function () {
    Http::fake([
        'api.etherscan.io/*' => Http::response([
            'status' => '1',
            'message' => 'OK',
            'result' => [
                [
                    'hash' => '0xtok',
                    'blockNumber' => '2000',
                    'timeStamp' => '1700000000',
                    'from' => '0xAAA0000000000000000000000000000000000001',
                    'to' => '0xBBB0000000000000000000000000000000000002',
                    'contractAddress' => '0xC2132D05D31c914a87C6611C10748AEb04B58e8F',
                    'value' => '250000000',
                    'tokenDecimal' => '6',
                    'tokenSymbol' => 'USDT',
                    'tokenName' => 'Tether USD',
                    'confirmations' => '5',
                ],
            ],
        ]),
    ]);

    $items = iterator_to_array(etherscanDriver()->getTokenTransactions('0xabc', contract: '0xc213'));

    expect($items)->toHaveCount(1)
        ->and($items[0]->amount()->isEqualTo('250'))->toBeTrue()
        ->and($items[0]->contractAddress())->toBe('0xc2132d05d31c914a87c6611c10748aeb04b58e8f')
        ->and($items[0]->tokenSymbol())->toBe('USDT');

    Http::assertSent(fn ($request) => $request['contractaddress'] === '0xc213');
});
