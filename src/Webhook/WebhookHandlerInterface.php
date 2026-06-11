<?php

namespace ItHealer\LaravelEvm\Webhook;

use ItHealer\LaravelEvm\Models\EvmDeposit;

interface WebhookHandlerInterface
{
    public function handle(EvmDeposit $deposit): void;
}
