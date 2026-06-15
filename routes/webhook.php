<?php

use Illuminate\Support\Facades\Route;
use ItHealer\LaravelEvm\Http\Controllers\AlchemyWebhookController;

if (config('evm.alchemy.webhook.enabled', false)) {
    Route::post(config('evm.alchemy.webhook.path', 'evm/alchemy/webhook'), AlchemyWebhookController::class)
        ->middleware((array)config('evm.alchemy.webhook.middleware', []))
        ->name('evm.alchemy.webhook');
}
