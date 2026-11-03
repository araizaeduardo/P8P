<?php

namespace P8P\Core;

class WorkflowRuntime
{
    public static function normalize(array $workflow): array
    {
        $nodes = [];
        foreach ($workflow['nodes'] ?? [] as $node) {
            $type = $node['data']['type'] ?? $node['type'] ?? '';
            $nodes[] = ['id' => $node['id'], 'type' => $type, 'data' => [
                'is_start' => in_array($type, ['start', 'chat_trigger', 'service_trigger'], true) || (bool)($node['data']['is_start'] ?? $node['data']['isStart'] ?? false),
                'parameters' => $node['data']['parameters'] ?? []]];
        }
        return ['nodes' => $nodes, 'edges' => $workflow['edges'] ?? []];
    }

    public static function start(array $workflow): array
    {
        $starts = array_values(array_filter(self::normalize($workflow)['nodes'], static fn ($node) =>
            in_array($node['type'], ['start', 'chat_trigger', 'service_trigger'], true) || $node['data']['is_start']));
        if (count($starts) !== 1) {
            throw new \InvalidArgumentException('El workflow receptor necesita exactamente un nodo de inicio.');
        }
        return $starts[0];
    }

    public static function hash(array $workflow): string
    {
        return hash('sha256', json_encode(self::normalize($workflow), JSON_THROW_ON_ERROR));
    }
}
