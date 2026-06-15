<?php

use Illuminate\Support\Facades\Http;
use ItHealer\LaravelEvm\Services\Alchemy\AlchemyNotifyClient;

function notifyClient(): AlchemyNotifyClient
{
    return new AlchemyNotifyClient('AUTH_TOKEN', 'https://dashboard.alchemy.com/api');
}

it('creates an address activity webhook with the auth token', function () {
    Http::fake([
        'dashboard.alchemy.com/api/create-webhook' => Http::response([
            'data' => ['id' => 'wh_1', 'signing_key' => 'sk_1'],
        ]),
    ]);

    $result = notifyClient()->createWebhook('MATIC_MAINNET', 'https://app.test/hook', ['0xabc']);

    expect($result['id'])->toBe('wh_1')
        ->and($result['signing_key'])->toBe('sk_1');

    Http::assertSent(function ($request) {
        return $request->hasHeader('X-Alchemy-Token', 'AUTH_TOKEN')
            && $request['network'] === 'MATIC_MAINNET'
            && $request['webhook_type'] === 'ADDRESS_ACTIVITY'
            && $request['webhook_url'] === 'https://app.test/hook'
            && $request['addresses'] === ['0xabc'];
    });
});

it('batches address updates to the 500-per-request limit', function () {
    Http::fake(['dashboard.alchemy.com/*' => Http::response([])]);

    $add = array_map(fn ($i) => '0x'.str_pad((string)$i, 40, '0', STR_PAD_LEFT), range(1, 600));

    notifyClient()->updateAddresses('wh_1', $add);

    $patches = [];
    Http::assertSent(function ($request) use (&$patches) {
        if ($request->method() === 'PATCH') {
            $patches[] = $request['addresses_to_add'];
        }

        return true;
    });

    expect($patches)->toHaveCount(2)
        ->and($patches[0])->toHaveCount(500)
        ->and($patches[1])->toHaveCount(100);
});

it('follows the cursor when listing watched addresses', function () {
    Http::fakeSequence('dashboard.alchemy.com/*')
        ->push(['data' => ['0xa', '0xb'], 'pagination' => ['cursors' => ['after' => 'c1']]])
        ->push(['data' => ['0xc'], 'pagination' => ['cursors' => []]]);

    $addresses = notifyClient()->getAddresses('wh_1');

    expect($addresses)->toBe(['0xa', '0xb', '0xc']);
});
