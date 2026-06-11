<?php

namespace ItHealer\LaravelEvm\Webhook;

use ItHealer\LaravelEvm\Models\EvmDeposit;

class EmptyWebhookHandler implements WebhookHandlerInterface
{
    public function handle(EvmDeposit $deposit): void
    {
    }
}
