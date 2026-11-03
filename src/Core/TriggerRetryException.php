<?php

namespace P8P\Core;

class TriggerRetryException extends \RuntimeException
{
    public function __construct(public readonly int $retryAfter = 60)
    {
        parent::__construct('Proveedor temporalmente no disponible; reprogramación de lectura.');
    }
}
