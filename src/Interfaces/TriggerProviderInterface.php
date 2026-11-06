<?php

namespace P8P\Interfaces;

interface TriggerProviderInterface
{
    /** key => {name,node_type,event,transport,config_fields,secret_fields,help,docs,setup_when_disabled?}. */
    public function definitions(): array;

    /**
     * Request: method, headers (lowercase), query, raw_body, callback_url,
     * form_fields (untrusted PHP-parsed multipart fields; never uploaded files).
     * When setup_allowed is true, reject business events before external reads.
     * Draft URL verification must return challenge=true and events=[].
     * Never log the request or secrets.
     */
    public function verify(string $key, array $config, array $secrets, array $request, int $now): array;

    /** Returns events, state, has_more; throw TriggerRetryException for Retry-After. */
    public function poll(string $key, array $config, array $secrets, array $state, int $now): array;
}
