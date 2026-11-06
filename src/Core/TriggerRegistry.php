<?php

namespace P8P\Core;

use P8P\Interfaces\TriggerProviderInterface;
use P8P\Core\TriggerProviders\{WebhookProviders, CloudPollingProviders, BusinessPollingProviders, NotionWebhooks, CommerceTriggers, CollaborationSuiteTriggers, CrmSuiteTriggers, ContentSuiteTriggers};

class TriggerRegistry
{
    private array $providers = [];
    private array $catalog = [];

    public function __construct(?array $providers = null)
    {
        foreach ($providers ?? [new WebhookProviders(), new CloudPollingProviders(), new BusinessPollingProviders(), new NotionWebhooks(), new CommerceTriggers(), new CollaborationSuiteTriggers(), new CrmSuiteTriggers(), new ContentSuiteTriggers(), new \P8P\Nodes\Custom\RssAtomNode()] as $provider) {
            if (!$provider instanceof TriggerProviderInterface) {
                throw new \InvalidArgumentException('Adaptador de trigger inválido.');
            }
            foreach ($provider->definitions() as $key => $definition) {
                if (isset($this->catalog[$key])) {
                    throw new \LogicException('Identificador de trigger duplicado.');
                }
                $this->catalog[$key] = ['key' => $key] + $definition;
                $this->providers[$key] = $provider;
            }
        }
    }

    public function definitions(): array
    {
        return $this->catalog;
    }

    public function definition(string $key): array
    {
        return $this->catalog[$key] ?? throw new \InvalidArgumentException('Trigger desconocido.');
    }

    public function provider(string $key): TriggerProviderInterface
    {
        return $this->providers[$key] ?? throw new \InvalidArgumentException('Trigger desconocido.');
    }
}
