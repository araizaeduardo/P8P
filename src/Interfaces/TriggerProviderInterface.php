<?php

namespace P8P\Interfaces;

interface TriggerProviderInterface
{
    /** key => {name,node_type,event,transport,config_fields,secret_fields,help,docs}. */
    public function definitions(): array;

    /** Request: method, headers (lowercase), query, raw_body, callback_url. Never log it. */
    public function verify(string $key, array $config, array $secrets, array $request, int $now): array;

    /** Returns events, state, has_more; throw TriggerRetryException for Retry-After. */
    public function poll(string $key, array $config, array $secrets, array $state, int $now): array;
}
