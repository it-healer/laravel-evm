<?php

namespace ItHealer\LaravelEvm\Enums;

enum TxType: int
{
    case Legacy = 0;
    case Eip1559 = 2;
}
