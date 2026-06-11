<?php

namespace ItHealer\LaravelEvm\Enums;

enum ExplorerDriver: string
{
    case EtherscanV2 = 'etherscan_v2';
    case Alchemy = 'alchemy';
}
