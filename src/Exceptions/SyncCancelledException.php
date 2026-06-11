<?php

namespace ItHealer\LaravelEvm\Exceptions;

class SyncCancelledException extends \RuntimeException
{
    public function __construct(string $message = 'Synchronization cancelled')
    {
        parent::__construct($message);
    }
}
