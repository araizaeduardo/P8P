import { useState, useEffect, useCallback } from 'react';
import Canvas from './components/Canvas.jsx';
import AddNodeModal from './components/AddNodeModal.jsx';
import NodeInspector from './components/NodeInspector.jsx';
import LoadWorkflowModal from './components/LoadWorkflowModal.jsx';
import CredentialsModal from './components/CredentialsModal.jsx';
import ExecutionLogsModal from './components/ExecutionLogsModal.jsx';
import TriggerManager from './components/TriggerManager.jsx';
import LoginModal from './components/LoginModal.jsx';
import NewWorkflowModal from './components/NewWorkflowModal.jsx';

import {
  Play,
  Plus,
  Save,
  Layers,
  FolderOpen,
  Key,
  Activity,
  LogOut,
  User,
  FilePlus,
  Copy,
  Check
} from 'lucide-react';

export default function App() {
  // Autenticación
  const [isAuthenticated, setIsAuthenticated] = useState(() => Boolean(localStorage.getItem('p8p_token') && localStorage.getItem('p8p_user')));
  const [user, setUser] = useState(() => localStorage.getItem('p8p_user'));

  // Modales
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [isLoadModalOpen, setIsLoadModalOpen] = useState(false);
  const [isCredentialsOpen, setIsCredentialsOpen] = useState(false);
  const [isLogsOpen, setIsLogsOpen] = useState(false);
  const [isTriggersOpen, setIsTriggersOpen] = useState(false);
  const [isNewModalOpen, setIsNewModalOpen] = useState(false);

  // Lienzo y workflow
  const [selectedNode, setSelectedNode] = useState(null);
  const [nodes, setNodes] = useState([]);
  const [edges, setEdges] = useState([]);
  const [workflowId, setWorkflowId] = useState(null);
  const [workflowName, setWorkflowName] = useState('Workflow Principal');
  const [copiedId, setCopiedId] = useState(false);

  // Ejecución y guardado
  const [executionResult, setExecutionResult] = useState(null);
  const [isRunning, setIsRunning] = useState(false);
  const [isSaving, setIsSaving] = useState(false);

  // Esquemas de nodos del backend
  const [nodeSchemas, setNodeSchemas] = useState([]);

  // Cerrar sesión
  const handleLogout = useCallback(() => {
    localStorage.removeItem('p8p_token');
    localStorage.removeItem('p8p_user');

    setIsAuthenticated(false);
    setUser(null);
  }, []);

  // Peticiones autenticadas con Bearer token
  const authenticatedFetch = useCallback(async (url, options = {}) => {
    const token = localStorage.getItem('p8p_token');

    const headers = {
      'Content-Type': 'application/json',
      ...(options.headers || {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {})
    };

    const response = await fetch(url, {
      ...options,
      headers
    });

    if (response.status === 401) {
      handleLogout();

      throw new Error(
          'Sesión expirada o no autorizada. Por favor, inicia sesión nuevamente.'
      );
    }

    return response;
  }, [handleLogout]);

  // Cargar esquemas después de autenticarse
  useEffect(() => {
    if (!isAuthenticated) return;

    const fetchSchemas = async () => {
      try {
        const response = await authenticatedFetch(
            './nodes.php'
        );

        const data = await response.json();

        if (data.success && Array.isArray(data.nodes)) {
          setNodeSchemas(data.nodes);
        }
      } catch (err) {
        console.error('Error al obtener esquemas de nodos:', err);
      }
    };

    fetchSchemas();
  }, [isAuthenticated, authenticatedFetch]);

  // LoginModal guarda el token y entrega el usuario
  const handleLoginSuccess = (userData) => {
    setIsAuthenticated(true);
    setUser(userData.username);
  };

  // Confirmación desde NewWorkflowModal
  const handleNewWorkflow = () => {
    if (isRunning || isSaving) return;

    setIsNewModalOpen(false);
    setNodes([]);
    setEdges([]);
    setWorkflowId(null);
    setWorkflowName('Mi Nuevo Workflow P8P');
    setSelectedNode(null);
    setExecutionResult(null);
    setCopiedId(false);

    setIsModalOpen(false);
    setIsLoadModalOpen(false);
    setIsCredentialsOpen(false);
    setIsLogsOpen(false);
    setIsTriggersOpen(false);
  };

  // Copiar ID completo
  const handleCopyId = async () => {
    if (workflowId == null) return;

    try {
      await navigator.clipboard.writeText(String(workflowId));

      setCopiedId(true);
      setTimeout(() => setCopiedId(false), 2000);
    } catch {
      alert('No se pudo copiar el ID al portapapeles.');
    }
  };

  // Agregar nodo
  const handleAddNode = (nodeType) => {
    const newNode = {
      id: `node_${Date.now()}`,
      type: 'customNode',
      position: {
        x: 250 + nodes.length * 30,
        y: 150 + nodes.length * 30
      },
      data: {
        label: nodeType.label,
        type: nodeType.type,
        isStart: nodeType.isStart,
        parameters:
            nodeType.type === 'http_request'
                ? {
                  url: 'https://www.p8p.info/v1/api/1',
                  method: 'GET'
                }
                : nodeType.type === 'set_variable'
                    ? {
                      variable_name: 'status',
                      variable_value: 'active'
                    }
                    : {}
      }
    };

    setNodes((prev) => [...prev, newNode]);
  };

  // Actualizar nodo
  const handleUpdateNode = (nodeId, newData) => {
    setNodes((prevNodes) =>
        prevNodes.map((node) =>
            node.id === nodeId
                ? { ...node, data: newData }
                : node
        )
    );

    setSelectedNode((prev) =>
        prev ? { ...prev, data: newData } : null
    );
  };

  // Eliminar nodo y conexiones
  const handleDeleteNode = (nodeId) => {
    setNodes((prev) =>
        prev.filter((node) => node.id !== nodeId)
    );

    setEdges((prev) =>
        prev.filter(
            (edge) =>
                edge.source !== nodeId &&
                edge.target !== nodeId
        )
    );

    setSelectedNode(null);
  };

  // Cargar workflow
  const handleLoadWorkflow = async (id) => {
    try {
      const response = await authenticatedFetch(
          `./get_workflows.php?id=${encodeURIComponent(id)}`
      );

      const data = await response.json();

      if (data.success && data.workflow) {
        const { structure } = data.workflow;

        setWorkflowId(data.workflow.id);
        setWorkflowName(
            data.workflow.name || 'Workflow Principal'
        );

        setSelectedNode(null);
        setExecutionResult(null);
        setCopiedId(false);
        setNodes(structure?.nodes || []);
        setEdges(structure?.edges || []);
        setIsLoadModalOpen(false);
      } else {
        alert(
            `Error al obtener workflow: ${
                data.error || 'Respuesta inválida'
            }`
        );
      }
    } catch (err) {
      alert(
          `Error de conexión al cargar el workflow: ${err.message}`
      );
    }
  };

  // Ejecutar workflow
  const handleTestWorkflow = async () => {
    setIsRunning(true);
    setExecutionResult(null);

    const payload = {
      workflow_id: workflowId,
      nodes: nodes.map((node) => ({
        id: node.id,
        type: node.data.type,
        data: {
          is_start: node.data.isStart,
          parameters: node.data.parameters || {}
        }
      })),
      edges: edges.map((edge) => ({
        source: edge.source,
        target: edge.target,
        sourceHandle: edge.sourceHandle,
        targetHandle: edge.targetHandle
      }))
    };

    try {
      const response = await authenticatedFetch(
          './api.php',
          {
            method: 'POST',
            body: JSON.stringify(payload)
          }
      );

      const data = await response.json();
      setExecutionResult(data);
    } catch (err) {
      setExecutionResult({
        error:
            'Error al conectar con el backend PHP: ' +
            err.message
      });
    } finally {
      setIsRunning(false);
    }
  };

  // Guardar workflow
  const handleSaveWorkflow = async () => {
    setIsSaving(true);

    const payload = {
      id: workflowId,
      name: workflowName,
      nodes,
      edges
    };

    try {
      const response = await authenticatedFetch(
          './save_workflow.php',
          {
            method: 'POST',
            body: JSON.stringify(payload)
          }
      );

      const data = await response.json();

      if (data.success) {
        setWorkflowId(data.workflow_id);

        alert(
            `Workflow Guardado con Éxito. ID: ${data.workflow_id}`
        );
      } else {
        alert(`Error al guardar: ${data.error}`);
      }
    } catch (err) {
      alert(
          `Error de conexión con la base de datos: ${err.message}`
      );
    } finally {
      setIsSaving(false);
    }
  };

  // Esquema del nodo seleccionado
  const selectedNodeType =
      selectedNode?.data?.type || selectedNode?.type;

  const selectedNodeSchema = nodeSchemas.find(
      (schema) => schema.type === selectedNodeType
  );

  // Mostrar login cuando no existe sesión
  if (!isAuthenticated) {
    return (
        <div className="w-screen h-screen bg-slate-950 flex items-center justify-center">
          <LoginModal
              isOpen={true}
              onLoginSuccess={handleLoginSuccess}
          />
        </div>
    );
  }

  return (
      <div className="flex flex-col h-screen w-screen bg-slate-900 text-slate-100 overflow-hidden">
        {/* Barra superior */}
        <header className="min-h-14 shrink-0 flex-wrap gap-3 py-2 border-b border-slate-800 bg-slate-950/80 backdrop-blur px-4 flex items-center justify-between z-10">
          <div className="flex items-center gap-3">
            <div className="bg-indigo-600 p-1.5 rounded-lg text-white font-bold tracking-wider text-sm flex items-center gap-1">
              <Layers className="w-4 h-4" />
              P8P
            </div>

            <span className="text-slate-500">/</span>

            <input
                type="text"
                value={workflowName}
                onChange={(e) =>
                    setWorkflowName(e.target.value)
                }
                aria-label="Nombre del workflow"
                placeholder="Nombre del workflow"
                className="w-44 bg-slate-900 border border-slate-700 rounded-lg px-2 py-1 text-xs text-slate-100 focus:outline-none focus:border-indigo-500"
            />

            {workflowId != null && (
                <button
                    onClick={handleCopyId}
                    title="Copiar ID completo"
                    aria-label="Copiar ID completo"
                    className="flex items-center gap-1.5 text-[10px] font-mono bg-slate-800 border border-slate-700 px-2 py-1 rounded text-indigo-400 hover:text-indigo-300"
                >
                  ID: {String(workflowId).substring(0, 8)}...
                  {copiedId ? (
                      <Check className="w-3 h-3 text-emerald-400" />
                  ) : (
                      <Copy className="w-3 h-3" />
                  )}
                </button>
            )}
          </div>

          <div className="flex flex-wrap items-center gap-2">
            {/* Agregar nodo */}
            <button
                onClick={() => setIsModalOpen(true)}
                className="flex items-center gap-1.5 px-3 py-1.5 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-xs font-medium transition"
            >
              <Plus className="w-3.5 h-3.5" />
              Nodo
            </button>

            {/* Nuevo workflow */}
            <button
                onClick={() => setIsNewModalOpen(true)}
                disabled={isSaving || isRunning}
                title="Crear un nuevo workflow"
                className="flex items-center gap-1.5 px-3 py-1.5 bg-slate-800 hover:bg-slate-700 disabled:opacity-50 text-slate-200 rounded-lg text-xs font-medium transition border border-slate-700"
            >
              <FilePlus className="w-3.5 h-3.5 text-indigo-400" />
              Nuevo
            </button>

            {/* Abrir */}
            <button
                onClick={() => setIsLoadModalOpen(true)}
                className="flex items-center gap-1.5 px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-lg text-xs font-medium transition border border-slate-700"
            >
              <FolderOpen className="w-3.5 h-3.5 text-indigo-400" />
              Abrir
            </button>

            {/* Guardar */}
            <button
                onClick={handleSaveWorkflow}
                disabled={isSaving || nodes.length === 0}
                className="flex items-center gap-1.5 px-3 py-1.5 bg-slate-800 hover:bg-slate-700 disabled:opacity-50 text-slate-200 rounded-lg text-xs font-medium transition border border-slate-700"
            >
              <Save className="w-3.5 h-3.5" />
              {isSaving ? 'Guardando...' : 'Guardar'}
            </button>

            {/* Credenciales */}
            <button
                onClick={() => setIsCredentialsOpen(true)}
                className="flex items-center gap-1.5 px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-lg text-xs font-medium border border-slate-700 transition"
            >
              <Key className="w-3.5 h-3.5 text-indigo-400" />
              Llaves
            </button>

            <button
                onClick={() => setIsTriggersOpen(true)}
                className="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-lg text-xs font-medium border border-slate-700"
            >
              Triggers
            </button>

            {/* Logs */}
            <button
                onClick={() => setIsLogsOpen(true)}
                className="flex items-center gap-1.5 px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-lg text-xs font-medium border border-slate-700 transition"
            >
              <Activity className="w-3.5 h-3.5 text-indigo-400" />
              Logs
            </button>

            {/* Ejecutar */}
            <button
                onClick={handleTestWorkflow}
                disabled={isRunning || nodes.length === 0}
                className="flex items-center gap-1.5 px-3 py-1.5 bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white rounded-lg text-xs font-medium transition shadow-lg shadow-emerald-900/20"
            >
              <Play className="w-3.5 h-3.5" />
              {isRunning ? 'Ejecutando...' : 'Test'}
            </button>

            <div className="h-5 w-[1px] bg-slate-800 mx-1" />

            {/* Usuario */}
            <div className="flex items-center gap-2 bg-slate-900 border border-slate-800 px-2.5 py-1 rounded-lg text-xs">
              <User className="w-3.5 h-3.5 text-indigo-400" />
              <span className="text-slate-300 font-medium">
              {user}
            </span>
            </div>

            {/* Salir */}
            <button
                onClick={handleLogout}
                title="Cerrar Sesión"
                className="p-1.5 bg-rose-500/10 hover:bg-rose-500/20 text-rose-300 border border-rose-500/30 rounded-lg transition"
            >
              <LogOut className="w-3.5 h-3.5" />
            </button>
          </div>
        </header>

        {/* Lienzo y paneles */}
        <main className="flex-1 relative flex overflow-hidden">
          <div className="flex-1 relative">
            <Canvas
                nodes={nodes}
                setNodes={setNodes}
                edges={edges}
                setEdges={setEdges}
                onNodeSelect={setSelectedNode}
            />
          </div>

          <AddNodeModal
              isOpen={isModalOpen}
              onClose={() => setIsModalOpen(false)}
              onAddNode={handleAddNode}
          />

          <NewWorkflowModal
              isOpen={isNewModalOpen}
              onClose={() => setIsNewModalOpen(false)}
              onConfirm={handleNewWorkflow}
          />

          <LoadWorkflowModal
              isOpen={isLoadModalOpen}
              onClose={() => setIsLoadModalOpen(false)}
              onLoadWorkflow={handleLoadWorkflow}
          />

          <CredentialsModal
              isOpen={isCredentialsOpen}
              onClose={() => setIsCredentialsOpen(false)}
          />

          <ExecutionLogsModal
              isOpen={isLogsOpen}
              onClose={() => setIsLogsOpen(false)}
          />
          {isTriggersOpen && (
              <TriggerManager workflowId={workflowId} nodes={nodes} onClose={() => setIsTriggersOpen(false)} />
          )}

          {/* Inspector */}
          {selectedNode && (
              <NodeInspector
                  selectedNode={selectedNode}
                  nodeSchema={selectedNodeSchema}
                  onClose={() => setSelectedNode(null)}
                  onUpdateNode={handleUpdateNode}
                  onDeleteNode={handleDeleteNode}
              />
          )}

          {/* Resultado de ejecución */}
          {executionResult && (
              <div className="absolute bottom-4 left-4 right-4 max-h-48 bg-slate-950/90 border border-slate-700 rounded-xl p-3 font-mono text-xs overflow-auto z-30 shadow-2xl">
                <div className="flex justify-between items-center mb-2 border-b border-slate-800 pb-1 text-slate-400">
              <span>
                Resultado de Ejecución (Backend PHP)
              </span>

                  <button
                      onClick={() => setExecutionResult(null)}
                      className="hover:text-white"
                  >
                    ✕
                  </button>
                </div>

                <pre className="text-emerald-400">
              {JSON.stringify(executionResult, null, 2)}
            </pre>
              </div>
          )}
        </main>
      </div>
  );
}
