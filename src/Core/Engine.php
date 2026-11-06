<?php

namespace P8P\Core;

use InvalidArgumentException;
use P8P\Interfaces\NodeInterface;
use P8P\Nodes\Core\LoopNode;
use P8P\Nodes\Core\DelayNode;
use P8P\Nodes\Core\MergeNode;
use P8P\Nodes\Core\SetVariableNode;
use P8P\Nodes\Core\SwitchNode;
use RuntimeException;

class Engine
{
    private array $registeredNodes = [];
    private \Closure $clock;

    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock === null ? static fn (): int => time() : \Closure::fromCallable($clock);
    }

    public function registerNode(NodeInterface $node): void
    {
        $this->registeredNodes[$node->getSchema()['type']] = $node;
    }

    public function executeWorkflow(array $workflowData, array $initialContext = []): array
    {
        return $this->run($workflowData, $initialContext);
    }

    /** Solo invocar con un checkpoint cargado del almacenamiento de cola de confianza. */
    public function resumeWorkflow(array $workflowData, array $checkpoint): array
    {
        if (($checkpoint['version'] ?? null) !== 1
            || ($checkpoint['workflow_hash'] ?? '') !== $this->workflowHash($workflowData)
            || !isset($checkpoint['frames'], $checkpoint['executions'], $checkpoint['resume_at'])) {
            throw new InvalidArgumentException('Checkpoint de ejecución inválido o workflow modificado.');
        }
        return $this->run($workflowData, [], $checkpoint);
    }

    private function workflowHash(array $workflow): string
    {
        return hash('sha256', json_encode($workflow, JSON_THROW_ON_ERROR));
    }

    private function run(array $workflowData, array $initialContext, array $checkpoint = []): array
    {
        $nodes = $workflowData['nodes'] ?? [];
        $edges = $workflowData['edges'] ?? [];
        if ($nodes === []) {
            throw new InvalidArgumentException('El workflow no contiene nodos para ejecutar.');
        }
        $nodesById = array_column($nodes, null, 'id');
        if (count($nodesById) !== count($nodes)) {
            throw new InvalidArgumentException('Los IDs de nodos deben ser únicos.');
        }
        $adjacencyList = [];
        $incoming = [];
        $subConnections = [];
        foreach ($edges as $edge) {
            if (!isset($nodesById[$edge['source'] ?? ''], $nodesById[$edge['target'] ?? ''])) {
                throw new InvalidArgumentException('Una conexión referencia un nodo inexistente.');
            }
            $targetHandle = $edge['targetHandle'] ?? 'main_input';
            if ($targetHandle !== 'main_input' && !empty($targetHandle)) {
                foreach ([$edge['source'], $edge['target']] as $id) {
                    if (in_array($nodesById[$id]['type'], ['loop', 'split_in_batches', 'merge', 'switch', 'delay', 'stop_and_error', 'set_variable', 'code'], true)) {
                        throw new InvalidArgumentException('Los nodos de control no admiten conexiones por subpuertos.');
                    }
                }
                $subConnections[$edge['target']][$targetHandle][] = $edge['source'];
            } else {
                if (($nodesById[$edge['source']]['type'] ?? '') === 'switch'
                    && preg_match('/^(?:case_[1-8]|default)$/D', $edge['sourceHandle'] ?? '') !== 1) {
                    throw new InvalidArgumentException('Switch: utiliza case_1 a case_8 o default.');
                }
                $adjacencyList[$edge['source']][] = $edge;
                $incoming[$edge['target']][] = $edge;
            }
        }
        // También valida DAGs sin Loop para que Merge no espere ciclos indefinidamente.
        $this->validateLoopGraph($nodesById, $edges, $adjacencyList);
        $memoryScope = is_string($workflowData['workflow_id'] ?? null) && $workflowData['workflow_id'] !== ''
            ? $workflowData['workflow_id'] : WorkflowRuntime::hash($workflowData);
        $frames = isset($checkpoint['frames']) ? CheckpointCodec::decode($checkpoint['frames']) : [];
        $executions = $checkpoint['executions'] ?? 0;
        $resumeAt = $checkpoint['resume_at'] ?? 0;
        $tick = function () use (&$executions): void {
            if (++$executions > 10000) {
                throw new RuntimeException('Se alcanzó el límite global de 10000 ejecuciones de nodos.');
            }
        };
        $isBlock = static fn (string $type): bool => in_array($type, ['loop', 'split_in_batches'], true);
        // Un frame excluye cuerpos anidados: sus nodos se ejecutan en frames hijos.
        $scope = function (array $roots) use ($nodesById, $adjacencyList, $isBlock): array {
            $found = [];
            while ($roots !== []) {
                $id = array_shift($roots);
                if (isset($found[$id])) {
                    continue;
                }
                $found[$id] = true;
                foreach ($adjacencyList[$id] ?? [] as $edge) {
                    if (!$isBlock($nodesById[$id]['type']) || ($edge['sourceHandle'] ?? '') === 'done') {
                        $roots[] = $edge['target'];
                    }
                }
            }
            return $found;
        };
        $runBlock = function (array $roots, array $context, array &$history, array $path = []) use (
            &$runBlock, &$frames, $scope, $incoming, $adjacencyList, $nodesById, $subConnections, $tick, $memoryScope
        ): array {
            $key = json_encode($path, JSON_THROW_ON_ERROR);
            if (!isset($frames[$key])) {
                $frames[$key] = ['scope' => $scope($roots), 'roots' => array_fill_keys($roots, true),
                    'states' => [], 'started' => [], 'context' => $context, 'history' => [], 'outputs' => []];
            }
            $frame = &$frames[$key];
            $history = $frame['history'];
            while (count($frame['states']) < count($frame['scope'])) {
                $currentNodeId = null;
                // Barrera: todos los predecesores del frame deben estar resueltos, activos o descartados.
                foreach ($frame['scope'] as $id => $_) {
                    if (isset($frame['states'][$id])) {
                        continue;
                    }
                    $ready = true;
                    foreach ($incoming[$id] ?? [] as $edge) {
                        if (isset($frame['scope'][$edge['source']]) && !isset($frame['states'][$edge['source']])) {
                            $ready = false;
                            break;
                        }
                    }
                    if ($ready) {
                        $currentNodeId = $id;
                        break;
                    }
                }
                if ($currentNodeId === null) {
                    throw new RuntimeException('El bloque no puede continuar: dependencias sin resolver.');
                }
                $active = isset($frame['roots'][$currentNodeId]);
                $mergeInputs = [];
                $mergeSkipped = [];
                foreach ($incoming[$currentNodeId] ?? [] as $edge) {
                    $sourceId = $edge['source'];
                    if (!isset($frame['scope'][$sourceId])) {
                        continue;
                    }
                    $sourceOutput = $frame['outputs'][$sourceId] ?? [];
                    $selected = ($frame['states'][$sourceId] ?? '') === 'success'
                        && (!isset($sourceOutput['branch']) || $sourceOutput['branch'] === ($edge['sourceHandle'] ?? 'main_output'));
                    if ($selected) {
                        $active = true;
                        $mergeInputs[$sourceId] = $sourceOutput;
                    } else {
                        $mergeSkipped[] = $sourceId;
                    }
                }
                if (!$active) {
                    $frame['states'][$currentNodeId] = 'skipped';
                    continue;
                }
                $nodeData = $nodesById[$currentNodeId];
                $nodeType = $nodeData['type'];
                $startTime = microtime(true);
                $entry = ['node_id' => $currentNodeId, 'type' => $nodeType, 'loop_path' => $path];
                $suspendAfter = null;
                try {
                    if (!isset($frame['started'][$currentNodeId])) {
                        $tick();
                        $frame['started'][$currentNodeId] = true;
                    }
                    $nodeInstance = $this->registeredNodes[$nodeType] ?? null;
                    if ($nodeInstance === null) {
                        throw new RuntimeException("El tipo de nodo '{$nodeType}' no está registrado en el motor.");
                    }
                    $raw = $nodeData['data']['parameters'] ?? [];
                    if ($nodeInstance instanceof LoopNode) {
                        if (count($path) >= 16) {
                            throw new RuntimeException('Loop: se alcanzó la profundidad máxima de 16 niveles.');
                        }
                        $bodyIds = [];
                        foreach ($adjacencyList[$currentNodeId] ?? [] as $edge) {
                            if (($edge['sourceHandle'] ?? '') === 'body') {
                                $bodyIds[] = $edge['target'];
                            }
                        }
                        $loopContext = $frame['context'];
                        $pending = $bodyIds;
                        $bodyNodes = [];
                        while ($pending !== []) {
                            $id = array_pop($pending);
                            if (isset($bodyNodes[$id])) {
                                continue;
                            }
                            $bodyNodes[$id] = true;
                            unset($loopContext[$id]);
                            foreach ($adjacencyList[$id] ?? [] as $edge) {
                                $pending[] = $edge['target'];
                            }
                        }
                        $entry['iterations'] = [];
                        $output = $nodeInstance->execute($raw, $loopContext,
                            function (array $iterationContext, array $state) use (
                                &$runBlock, &$entry, $bodyIds, $currentNodeId, $path
                            ): array {
                                $iterationHistory = [];
                                $iterationPath = [...$path, ['node_id' => $currentNodeId, 'iteration' => $state['iteration']]];
                                try {
                                    return $runBlock($bodyIds, $iterationContext, $iterationHistory, $iterationPath);
                                } finally {
                                    $entry['iterations'][] = ['index' => $state['index'], 'iteration' => $state['iteration'],
                                        'history' => $iterationHistory];
                                }
                            }, $currentNodeId);
                    } else {
                        // Código y casos JSON nunca se interpolan como código fuente o texto JSON completo.
                        $parameters = $nodeInstance instanceof SwitchNode || $nodeType === 'code'
                            ? $raw : ExpressionEvaluator::evaluate($raw, $frame['context']);
                        unset($parameters['_merge_inputs'], $parameters['_merge_skipped']);
                        if ($nodeInstance instanceof MergeNode) {
                            $parameters['_merge_inputs'] = $mergeInputs;
                            $parameters['_merge_skipped'] = array_values(array_diff(array_unique($mergeSkipped), array_keys($mergeInputs)));
                        }
                        $subOutputs = [];
                        foreach ($subConnections[$currentNodeId] ?? [] as $handle => $sourceIds) {
                            foreach ($sourceIds as $sourceId) {
                                $entry['active_subnode'] = $sourceId;
                                $sourceType = $nodesById[$sourceId]['type'];
                                $instance = $this->registeredNodes[$sourceType] ?? null;
                                if ($instance === null) {
                                    // Compatibilidad con workflows guardados con puertos opcionales sin implementación.
                                    // El nodo principal sigue validando sus dependencias obligatorias (p. ej. model).
                                    $entry['skipped_subnodes'][] = ['node_id' => $sourceId, 'type' => $sourceType,
                                        'handle' => $handle, 'reason' => 'unregistered'];
                                    unset($entry['active_subnode']);
                                    continue;
                                }
                                $tick();
                                $subParams = ExpressionEvaluator::evaluate($nodesById[$sourceId]['data']['parameters'] ?? [], $frame['context']);
                                $subContext = $frame['context'];
                                if ($instance instanceof \P8P\Nodes\Core\SimpleMemoryNode) {
                                    $subContext['_memory_scope'] = $frame['context']['_workflow_id'] ?? $memoryScope;
                                    $subContext['_memory_node_id'] = $sourceId;
                                }
                                $subOutput = $instance->execute($subParams, $subContext);
                                if ($path !== [] && ($subOutput['success'] ?? null) === false) {
                                    throw new RuntimeException("El subnodo {$sourceId} devolvió success=false.");
                                }
                                $subOutputs[$handle][] = $subOutput;
                                unset($entry['active_subnode']);
                            }
                        }
                        $parameters['_sub_connections'] = $subOutputs;
                        if ($nodeType !== 'code') {
                            $logged = $parameters;
                            unset($logged['_merge_inputs']);
                            $entry['evaluated_parameters'] = $logged;
                        }
                        $output = $nodeInstance instanceof DelayNode
                            ? $nodeInstance->execute($parameters, $frame['context'], ($this->clock)())
                            : $nodeInstance->execute($parameters, $frame['context']);
                        if ($nodeInstance instanceof SetVariableNode) {
                            $changes = $output['_variable_changes'] ?? ['set' => [], 'unset' => []];
                            foreach ($changes['set'] as $name => $value) {
                                if (isset($nodesById[$name])) {
                                    throw new InvalidArgumentException('El nombre de variable no puede coincidir con un ID de nodo.');
                                }
                                $frame['context']['_variables'][$name] = $value;
                                unset($frame['context']['_deleted_variables'][$name]);
                            }
                            foreach ($changes['unset'] as $name) {
                                unset($frame['context']['_variables'][$name]);
                                $frame['context']['_deleted_variables'][$name] = true;
                            }
                            unset($output['_variable_changes']);
                        }
                        if ($nodeInstance instanceof DelayNode && $output['resume_at'] > ($this->clock)()) {
                            $suspendAfter = $output['resume_at'];
                        }
                    }
                    if ($path !== [] && ($output['success'] ?? null) === false) {
                        throw new RuntimeException("El nodo {$currentNodeId} devolvió success=false.");
                    }
                    $entry['status'] = 'success';
                    $entry['output'] = $output;
                    $frame['context'][$currentNodeId] = $output;
                    $frame['outputs'][$currentNodeId] = $output;
                    $frame['states'][$currentNodeId] = 'success';
                } catch (WorkflowSuspended $e) {
                    $entry['status'] = 'suspended';
                    throw $e;
                } catch (\Throwable $e) {
                    $entry['status'] = 'failed';
                    $entry['error'] = $e->getMessage();
                    throw $e;
                } finally {
                    $entry['execution_time_ms'] = round((microtime(true) - $startTime) * 1000, 2);
                    $frame['history'][$currentNodeId] = $entry;
                    $history = $frame['history'];
                }
                if ($suspendAfter !== null) {
                    throw new WorkflowSuspended($suspendAfter);
                }
            }
            $outputs = $frame['outputs'];
            // Estado explícito solo dentro del frame. Loop lo usa al reevaluar su condición.
            foreach (['_variables', '_deleted_variables'] as $name) {
                if (isset($frame['context'][$name])) {
                    $outputs[$name] = $frame['context'][$name];
                }
            }
            return $outputs;
        };

        $status = 'completed';
        $error = null;
        $history = $frames['[]']['history'] ?? [];
        try {
            if ($resumeAt > ($this->clock)()) {
                throw new WorkflowSuspended($resumeAt);
            }
            $runBlock([$this->findStartNode($nodes)['id']], $initialContext, $history);
        } catch (WorkflowSuspended $e) {
            $status = 'suspended';
            $resumeAt = $e->resumeAt;
        } catch (\Throwable $e) {
            $status = 'failed';
            $error = $e->getMessage();
        }
        $result = ['status' => $status, 'history' => $history, 'final_context' => $frames['[]']['context'] ?? $initialContext];
        if ($error !== null) {
            $result['error'] = $error;
        }
        if ($status === 'suspended') {
            $result['resume_at'] = $resumeAt;
            $result['checkpoint'] = ['version' => 1, 'workflow_hash' => $this->workflowHash($workflowData),
                'resume_at' => $resumeAt, 'executions' => $executions, 'frames' => CheckpointCodec::encode($frames)];
        }
        return $result;
    }

    /**
     * Un bloque estructurado termina en sus hojas. Prohibir ciclos y entradas externas
     * evita ejecutar efectos antes de descubrir un retorno o una continuación ambigua.
     */
    private function validateLoopGraph(array $nodesById, array $edges, array $adjacencyList): void
    {
        foreach ($edges as $edge) {
            if (!isset($nodesById[$edge['source']], $nodesById[$edge['target']])) {
                throw new InvalidArgumentException('Loop: una conexión referencia un nodo inexistente.');
            }
            $sourceLoop = in_array($nodesById[$edge['source']]['type'] ?? '', ['loop', 'split_in_batches'], true);
            $targetLoop = in_array($nodesById[$edge['target']]['type'] ?? '', ['loop', 'split_in_batches'], true);
            $targetHandle = $edge['targetHandle'] ?? 'main_input';
            if (($sourceLoop || $targetLoop) && $targetHandle !== 'main_input' && $targetHandle !== '') {
                throw new InvalidArgumentException('Loop no admite conexiones por subpuertos.');
            }
            if ($sourceLoop && !in_array($edge['sourceHandle'] ?? '', ['body', 'done'], true)) {
                throw new InvalidArgumentException('Loop: utiliza las salidas body y done.');
            }
        }
        $colors = [];
        $visit = function (string $id) use (&$visit, &$colors, $adjacencyList): void {
            if (($colors[$id] ?? 0) === 1) {
                throw new InvalidArgumentException('Loop: el grafo contiene un ciclo; el cuerpo no debe conectarse de vuelta.');
            }
            if (($colors[$id] ?? 0) === 2) {
                return;
            }
            $colors[$id] = 1;
            foreach ($adjacencyList[$id] ?? [] as $edge) {
                $visit($edge['target']);
            }
            $colors[$id] = 2;
        };
        foreach (array_keys($nodesById) as $id) {
            $visit((string) $id);
        }
        $reachable = function (array $roots) use ($adjacencyList): array {
            $found = [];
            while ($roots !== []) {
                $id = array_pop($roots);
                if (isset($found[$id])) {
                    continue;
                }
                $found[$id] = true;
                foreach ($adjacencyList[$id] ?? [] as $edge) {
                    $roots[] = $edge['target'];
                }
            }
            return $found;
        };
        foreach ($nodesById as $id => $node) {
            if (!in_array($node['type'] ?? '', ['loop', 'split_in_batches'], true)) {
                continue;
            }
            $bodyRoots = [];
            $doneRoots = [];
            foreach ($adjacencyList[$id] ?? [] as $edge) {
                if ($edge['sourceHandle'] === 'body') {
                    $bodyRoots[] = $edge['target'];
                } else {
                    $doneRoots[] = $edge['target'];
                }
            }
            if (count($bodyRoots) !== 1 || count($doneRoots) > 1) {
                throw new InvalidArgumentException('Loop: conecta exactamente un inicio de Cuerpo y como máximo un inicio de Finalizado.');
            }
            $body = $reachable($bodyRoots);
            if (array_intersect_key($body, $reachable($doneRoots)) !== []) {
                throw new InvalidArgumentException('Loop: Cuerpo y Finalizado deben ser bloques separados.');
            }
            foreach ($edges as $edge) {
                if (!empty($edge['targetHandle']) && $edge['targetHandle'] !== 'main_input') {
                    continue;
                }
                if (isset($body[$edge['target']]) && !isset($body[$edge['source']])
                    && !($edge['source'] === $id && ($edge['sourceHandle'] ?? '') === 'body')) {
                    throw new InvalidArgumentException('Loop: el cuerpo no puede recibir conexiones desde fuera de su bloque.');
                }
            }
        }
    }

    private function findStartNode(array $nodes): ?array
    {
        foreach ($nodes as $node) {
            if (($node['type'] ?? '') === 'start' || ($node['data']['is_start'] ?? false)) {
                return $node;
            }
        }
        return $nodes[0] ?? null;
    }
}
