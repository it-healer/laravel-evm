<?php

namespace ItHealer\LaravelEvm\Enums;

enum EvmModel: string
{
    case Network = 'network';
    case Node = 'node';
    case Explorer = 'explorer';
    case Token = 'token';
    case Wallet = 'wallet';
    case Address = 'address';
    case AddressBalance = 'address_balance';
    case Transaction = 'transaction';
    case Deposit = 'deposit';
    case AlchemyWebhook = 'alchemy_webhook';
}
