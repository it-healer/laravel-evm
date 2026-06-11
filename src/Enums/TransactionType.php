<?php

namespace ItHealer\LaravelEvm\Enums;

enum TransactionType: string
{
    case OUTGOING = 'out';
    case INCOMING = 'in';
}