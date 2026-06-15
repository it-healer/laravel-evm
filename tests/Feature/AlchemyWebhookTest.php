<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use ItHealer\LaravelEvm\Facades\Evm;
use ItHealer\LaravelEvm\Http\Controllers\AlchemyWebhookController;
use ItHealer\LaravelEvm\Jobs\SyncEvmAddressJob;
use ItHealer\LaravelEvm\Models\EvmAlchemyWebhook;

uses(RefreshDatabase::class);

const MNEMONIC = 'test test test test test test test test test test test junk';

function webhookSetup(): array
{
    $network = Evm::createNetwork(137, 'polygon', 'POL');
    $wallet = Evm::createWallet('w', mnemonic: MNEMONIC);
    Evm::attachNetwork($wallet, $network);

    $webhook = EvmAlchemyWebhook::create([
        'network_id' => $network->id,
        'webhook_id' => 'wh_test',
        'signing_key' => 'sk_secret',
        'active' => true,
    ]);

    return [$network, $wallet->addresses->first(), $webhook];
}

function postWebhook(array $payload, ?string $signature = null)
{
    $raw = json_encode($payload);
    $signature ??= hash_hmac('sha256', $raw, 'sk_secret');

    return test()->call('POST', 'evm/alchemy/webhook', [], [], [], [
        'HTTP_X_ALCHEMY_SIGNATURE' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], $raw);
}

beforeEach(function () {
    Route::post('evm/alchemy/webhook', AlchemyWebhookController::class);
});

it('dispatches a sync for a watched address on a valid signature', function () {
    Bus::fake();
    [$network, $address] = webhookSetup();

    $response = postWebhook([
        'webhookId' => 'wh_test',
        'event' => ['activity' => [
            ['toAddress' => strtolower($address->address), 'hash' => '0xdeadbeef'],
        ]],
    ]);

    $response->assertOk()->assertJson(['handled' => 1]);

    Bus::assertDispatched(SyncEvmAddressJob::class, function ($job) use ($address, $network) {
        return $job->address->is($address) && $job->network->is($network);
    });
});

it('dispatches a sync for an outgoing transfer (address as sender)', function () {
    Bus::fake();
    [$network, $address] = webhookSetup();

    postWebhook([
        'webhookId' => 'wh_test',
        'event' => ['activity' => [
            [
                'fromAddress' => strtolower($address->address),
                'toAddress' => '0x000000000000000000000000000000000000dead',
                'hash' => '0xspend',
            ],
        ]],
    ])->assertOk()->assertJson(['handled' => 1]);

    Bus::assertDispatched(SyncEvmAddressJob::class, function ($job) use ($address, $network) {
        return $job->address->is($address) && $job->network->is($network);
    });
});

it('rejects an invalid signature', function () {
    Bus::fake();
    [, $address] = webhookSetup();

    postWebhook([
        'webhookId' => 'wh_test',
        'event' => ['activity' => [['toAddress' => strtolower($address->address)]]],
    ], signature: 'wrong')->assertForbidden();

    Bus::assertNothingDispatched();
});

it('returns 404 for an unknown webhook id', function () {
    webhookSetup();

    postWebhook(['webhookId' => 'wh_unknown', 'event' => ['activity' => []]])
        ->assertNotFound();
});

it('ignores activity for addresses it does not track', function () {
    Bus::fake();
    webhookSetup();

    postWebhook([
        'webhookId' => 'wh_test',
        'event' => ['activity' => [['toAddress' => '0x000000000000000000000000000000000000dead']]],
    ])->assertOk()->assertJson(['handled' => 0]);

    Bus::assertNothingDispatched();
});

it('ensures a webhook only once and reuses it', function () {
    $network = Evm::createNetwork(137, 'polygon', 'POL');

    Http::fake([
        'dashboard.alchemy.com/api/create-webhook' => Http::response([
            'data' => ['id' => 'wh_created', 'signing_key' => 'sk_created'],
        ]),
    ]);

    $first = Evm::ensureAlchemyWebhook($network);
    $second = Evm::ensureAlchemyWebhook($network);

    expect($first->webhook_id)->toBe('wh_created')
        ->and($second->is($first))->toBeTrue()
        ->and(EvmAlchemyWebhook::count())->toBe(1);

    Http::assertSentCount(1);
});

it('reconciles the watched-address list with tracked addresses', function () {
    [$network, $address, $webhook] = webhookSetup();

    Http::fake([
        'dashboard.alchemy.com/api/webhook-addresses*' => Http::response([
            'data' => ['0xstale000000000000000000000000000000000000'],
            'pagination' => ['cursors' => []],
        ]),
        'dashboard.alchemy.com/api/update-webhook-addresses' => Http::response([]),
    ]);

    $diff = Evm::reconcileAlchemyWebhook($network);

    expect($diff['added'])->toContain($address->address)
        ->and($diff['removed'])->toContain('0xstale000000000000000000000000000000000000')
        ->and($webhook->fresh()->addresses_count)->toBe(1);

    Http::assertSent(fn ($request) => $request->method() === 'PATCH'
        && in_array($address->address, $request['addresses_to_add'], true));
});
