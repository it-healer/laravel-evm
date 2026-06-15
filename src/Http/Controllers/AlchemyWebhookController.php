<?php

namespace ItHealer\LaravelEvm\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ItHealer\LaravelEvm\Enums\EvmModel;
use ItHealer\LaravelEvm\Facades\Evm;
use ItHealer\LaravelEvm\Jobs\SyncEvmAddressJob;
use ItHealer\LaravelEvm\Models\EvmAddress;
use ItHealer\LaravelEvm\Models\EvmAlchemyWebhook;
use ItHealer\LaravelEvm\Services\Alchemy\AlchemySignature;

/**
 * Receives Alchemy Address Activity notifications: verifies the signature, resolves
 * the involved watched addresses and dispatches a targeted sync for each. The actual
 * deposit detection stays in AddressNetworkSync — this only replaces the polling timer.
 */
class AlchemyWebhookController
{
    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $data = json_decode($payload, true);

        $webhookId = $data['webhookId'] ?? null;

        if (!is_array($data) || !$webhookId) {
            abort(400, 'Invalid payload.');
        }

        /** @var class-string<EvmAlchemyWebhook> $webhookModel */
        $webhookModel = Evm::getModel(EvmModel::AlchemyWebhook);

        /** @var EvmAlchemyWebhook|null $webhook */
        $webhook = $webhookModel::query()->where('webhook_id', $webhookId)->first();

        if (!$webhook) {
            abort(404, 'Unknown webhook.');
        }

        if (!AlchemySignature::isValid($payload, $request->header('X-Alchemy-Signature'), $webhook->signing_key)) {
            abort(403, 'Invalid signature.');
        }

        // Match both sides: an address may be the recipient (incoming) or the sender
        // (outgoing), and the same address may live in several wallets.
        $candidates = collect($data['event']['activity'] ?? [])
            ->flatMap(fn (array $activity) => [$activity['fromAddress'] ?? null, $activity['toAddress'] ?? null])
            ->filter()
            ->map(fn (string $address) => Str::lower($address))
            ->unique()
            ->values();

        if ($candidates->isEmpty()) {
            return response()->json(['handled' => 0]);
        }

        /** @var class-string<EvmAddress> $addressModel */
        $addressModel = Evm::getModel(EvmModel::Address);

        $addresses = $addressModel::query()
            ->whereIn(DB::raw('LOWER(address)'), $candidates->all())
            ->get();

        foreach ($addresses as $address) {
            SyncEvmAddressJob::dispatch($address, $webhook->network);
        }

        return response()->json(['handled' => $addresses->count()]);
    }
}
