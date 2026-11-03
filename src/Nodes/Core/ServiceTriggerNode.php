<?php

namespace P8P\Nodes\Core;

use P8P\Interfaces\NodeInterface;

class ServiceTriggerNode implements NodeInterface
{
    public function getSchema(): array
    {
        return ['type' => 'service_trigger', 'name' => 'Evento de servicio', 'description' => 'Inicio verificado desde Triggers. Configura el receptor después de guardar el workflow.',
            'icon' => 'Webhook', 'category' => 'Core', 'is_start' => true, 'parameters' => []];
    }

    public function validate(array $parameters): bool
    {
        return true;
    }

    public function execute(array $parameters, array $context): array
    {
        if (!is_array($context['_trigger'] ?? null)) {
            throw new \RuntimeException('Este inicio requiere un evento verificado y encolado.');
        }
        return ['success' => true, 'event' => $context['_trigger'], 'data' => $context['_trigger']['data']];
    }
}
