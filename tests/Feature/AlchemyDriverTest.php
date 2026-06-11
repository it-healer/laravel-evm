<?php

use Illuminate\Support\Facades\Http;
use ItHealer\LaravelEvm\Explorer\Drivers\AlchemyDriver;

function alchemyDriver(): AlchemyDriver
{
    return new AlchemyDriver('https://polygon-mainnet.g.alchemy.com/v2/KEY');
}

function alchemyTransfer(array $overrides = []): array
{
    return [
        'uniqueId' => '0xhash1:external',
        'hash' => '0xhash1',
        'blockNum' => '0x3e8',
        'from' => '0xAAA0000000000000000000000000000000000001',
        'to' => '0xBBB0000000000000000000000000000000000002',
        'value' => 1.5,
        'asset' => 'ETH',
        'category' => 'external',
        'rawContract' => ['value' => '0x14d1120d7b160000', 'address' => null, 'decimal' => '0x12'],
        'metadata' => ['blockTimestamp' => '2023-11-14T22:13:20.000Z'],
        ...$overrides,
    ];
}

it('queries both directions and follows the pageKey cursor', function () {
    Http::fakeSequence('polygon-mainnet.g.alchemy.com/*')
        ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => [
            'transfers' => [alchemyTransfer(['hash' => '0xin1'])],
            'pageKey' => 'cursor-1',
        ]])
        ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => [
            'transfers' => [alchemyTransfer(['hash' => '0xin2'])],
        ]])
        ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => [
            'transfers' => [alchemyTransfer(['hash' => '0xout1'])],
        ]]);

    $items = iterator_to_array(alchemyDriver()->getNativeTransactions('0xabc', startBlock: 100));

    expect(array_map(fn ($i) => $i->hash(), $items))->toBe(['0xin1', '0xin2', '0xout1']);

    $bodies = [];
    Http::assertSent(function ($request) use (&$bodies) {
        $bodies[] = $request->data()['params'][0];

        return true;
    });

    expect($bodies[0])->toHaveKey('toAddress')
        ->and($bodies[0]['fromBlock'])->toBe('0x64')
        ->and($bodies[0]['category'])->toBe(['external'])
        ->and($bodies[1]['pageKey'])->toBe('cursor-1')
        ->and($bodies[2])->toHaveKey('fromAddress');
});

it('uses rawContract value to avoid float precision loss', function () {
    // 19753086241975338281703822562 base units of an 18-decimals token —
    // unrepresentable exactly as a float
    Http::fakeSequence('polygon-mainnet.g.alchemy.com/*')
        ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => [
            'transfers' => [alchemyTransfer([
                'value' => 19753086241.975338,
                'category' => 'erc20',
                'rawContract' => [
                    'value' => '0x3fd35eb6d797c41510afd4e2',
                    'address' => '0xC2132D05D31c914a87C6611C10748AEb04B58e8F',
                    'decimal' => '0x12',
                ],
            ])],
        ]])
        ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['transfers' => []]]);

    $items = iterator_to_array(alchemyDriver()->getTokenTransactions('0xabc'));

    expect($items)->toHaveCount(1)
        ->and((string)$items[0]->amount())->toBe('19753086241.975338281703822562')
        ->and($items[0]->contractAddress())->toBe('0xc2132d05d31c914a87c6611c10748aeb04b58e8f')
        ->and($items[0]->confirmations())->toBeNull()
        ->and($items[0]->blockNumber())->toBe(1000)
        ->and($items[0]->time()->getTimestamp())->toBe(1700000000);
});

it('passes contractAddresses filter for token queries', function () {
    Http::fake([
        'polygon-mainnet.g.alchemy.com/*' => Http::response([
            'jsonrpc' => '2.0', 'id' => 1, 'result' => ['transfers' => []],
        ]),
    ]);

    iterator_to_array(alchemyDriver()->getTokenTransactions('0xabc', contract: '0xc213'));

    Http::assertSent(function ($request) {
        $params = $request->data()['params'][0];

        return $params['category'] === ['erc20'] && $params['contractAddresses'] === ['0xc213'];
    });
});
