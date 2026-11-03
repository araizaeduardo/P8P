import { useState, useEffect } from 'react';
import { X, Trash2, HelpCircle, Sliders } from 'lucide-react';

export default function NodeInspector({
                                          selectedNode,
                                          nodeSchema,
                                          onClose,
                                          onUpdateNode,
                                          onDeleteNode
                                      }) {
    const [credentialsList, setCredentialsList] = useState([]);

    // Cargar la lista pública de credenciales desde MariaDB
    useEffect(() => {
        const fetchCredentials = async () => {
            try {
                const token = localStorage.getItem('p8p_token');
                const res = await fetch('./credentials.php', {
                    headers: {
                        'Content-Type': 'application/json',
                        ...(token ? { 'Authorization': `Bearer ${token}` } : {})
                    }
                });

                if (res.status === 401) {
                    localStorage.removeItem('p8p_token');
                    localStorage.removeItem('p8p_user');
                    window.location.reload();
                    return;
                }

                const data = await res.json();
                if (data.success) {
                    setCredentialsList(data.credentials || []);
                }
            } catch (err) {
                console.error('Error al cargar lista de credenciales:', err);
            }
        };

        if (selectedNode) {
            fetchCredentials();
        }
    }, [selectedNode]);

    // Si no hay ningún nodo seleccionado, no renderizar el panel
    if (!selectedNode) return null;

    // Construir esquema seguro si nodeSchema viene undefined
    const effectiveSchema = nodeSchema || {
        name: selectedNode.data?.label || selectedNode.data?.type || selectedNode.type,
        description: 'Configuración del nodo',
        parameters: selectedNode.data?.parameters || {}
    };

    const nodeData = selectedNode?.data || {};
    const parameters = nodeData?.parameters || {};

    // Garantizar que schemaParams sea un Objeto plano para evitar fallos con Object.entries()
    const rawParams = effectiveSchema?.parameters;
    const schemaParams = (rawParams && typeof rawParams === 'object' && !Array.isArray(rawParams))
        ? rawParams
        : {};

    // Función segura para actualizar parámetros del nodo seleccionado
    const handleInputChange = (paramKey, value) => {
        const updatedParameters = {
            ...parameters,
            [paramKey]: value
        };

        if (typeof onUpdateNode === 'function') {
            onUpdateNode(selectedNode.id, {
                ...nodeData,
                parameters: updatedParameters
            });
        }
    };

    return (
        <aside className="w-80 bg-slate-900 border-l border-slate-800 flex flex-col h-full z-20 text-slate-100 shadow-xl">
            {/* Cabecera del Inspector */}
            <div className="p-4 border-b border-slate-800 flex items-center justify-between bg-slate-950/50">
                <div className="flex items-center gap-2">
                    <Sliders className="w-4 h-4 text-indigo-400" />
                    <h3 className="font-semibold text-sm">Configuración del Nodo</h3>
                </div>
                <button
                    onClick={onClose}
                    className="text-slate-400 hover:text-slate-200 p-1 rounded-lg hover:bg-slate-800 transition"
                >
                    <X className="w-4 h-4" />
                </button>
            </div>

            <div className="p-4 flex-1 overflow-y-auto space-y-5">
                {/* Información Básica del Nodo */}
                <div className="space-y-1">
                    <div className="text-xs text-slate-400 font-mono">ID: {selectedNode.id}</div>
                    <div className="text-base font-bold text-slate-100">
                        {nodeData.label || effectiveSchema?.name || selectedNode.type}
                    </div>
                    {effectiveSchema?.description && (
                        <p className="text-xs text-slate-400 mt-1">{effectiveSchema.description}</p>
                    )}
                </div>

                {/* BLOQUE DE AYUDA / INSTRUCCIONES DINÁMICO DESDE PHP */}
                {effectiveSchema?.help_text && (
                    <div className="p-3 bg-slate-950/80 rounded-xl border border-indigo-500/30 text-slate-300 text-[11px] space-y-1.5 shadow-sm">
                        <div className="font-semibold text-indigo-400 flex items-center gap-1.5">
                            <HelpCircle className="w-3.5 h-3.5" />
                            <span>Instrucciones de Uso</span>
                        </div>
                        <div className="text-slate-300 leading-relaxed text-[11px]">
                            {effectiveSchema.help_text}
                        </div>
                    </div>
                )}

                {/* Campos de Parámetros Dinámicos basados en Schema */}
                <div className="space-y-4 pt-2 border-t border-slate-800/80">
                    <h4 className="text-xs font-semibold text-slate-300 uppercase tracking-wider">
                        Parámetros
                    </h4>

                    {Object.keys(schemaParams).length === 0 ? (
                        <div className="text-xs text-slate-500 italic">
                            Este nodo no requiere parámetros de configuración adicionales.
                        </div>
                    ) : (
                        Object.entries(schemaParams).map(([key, config]) => (
                            <div key={key} className="space-y-1.5">
                                <label className="block text-xs font-medium text-slate-300">
                                    {config?.label || key}
                                    {config?.required && <span className="text-rose-400 ml-1">*</span>}
                                </label>

                                {/* Selección de Credencial dinámica (por clave o por tipo 'credential') */}
                                {(config?.type === 'credential' || key === 'credential_id') ? (
                                    <select
                                        value={parameters[key] ?? ''}
                                        onChange={(e) => handleInputChange(key, e.target.value)}
                                        className="w-full bg-slate-950 border border-slate-800 rounded-lg p-2 text-xs text-slate-200 focus:outline-none focus:border-indigo-500 font-mono"
                                    >
                                        <option value="">-- Seleccionar Credencial --</option>
                                        {credentialsList.map((cred) => (
                                            <option key={cred.id} value={cred.id}>
                                                {cred.name} ({cred.type})
                                            </option>
                                        ))}
                                    </select>
                                ) : config?.type === 'select' ? (
                                    <select
                                        value={parameters[key] ?? config?.default ?? ''}
                                        onChange={(e) => handleInputChange(key, e.target.value)}
                                        className="w-full bg-slate-950 border border-slate-800 rounded-lg p-2 text-xs text-slate-200 focus:outline-none focus:border-indigo-500 font-mono"
                                    >
                                        {config.options?.map((opt) => (
                                            <option key={opt.value} value={opt.value}>
                                                {opt.label}
                                            </option>
                                        ))}
                                    </select>
                                ) : config?.type === 'textarea' ? (
                                    <textarea
                                        rows={4}
                                        value={parameters[key] ?? config?.default ?? ''}
                                        onChange={(e) => handleInputChange(key, e.target.value)}
                                        placeholder={config?.placeholder || ''}
                                        className="w-full bg-slate-950 border border-slate-800 rounded-lg p-2 text-xs text-slate-200 focus:outline-none focus:border-indigo-500 font-mono resize-y"
                                    />
                                ) : (
                                    <input
                                        type={config?.type === 'number' ? 'number' : 'text'}
                                        value={parameters[key] ?? config?.default ?? ''}
                                        onChange={(e) => handleInputChange(key, e.target.value)}
                                        placeholder={config?.placeholder || ''}
                                        className="w-full bg-slate-950 border border-slate-800 rounded-lg p-2 text-xs text-slate-200 focus:outline-none focus:border-indigo-500 font-mono"
                                    />
                                )}
                            </div>
                        ))
                    )}
                </div>
            </div>

            {/* Botón de Eliminación */}
            <div className="p-4 border-t border-slate-800 bg-slate-950/50">
                <button
                    onClick={() => onDeleteNode && onDeleteNode(selectedNode.id)}
                    className="w-full flex items-center justify-center gap-2 py-2 px-3 bg-rose-500/10 hover:bg-rose-500/20 text-rose-400 border border-rose-500/30 rounded-xl text-xs font-semibold transition"
                >
                    <Trash2 className="w-4 h-4" />
                    <span>Eliminar Nodo</span>
                </button>
            </div>
        </aside>
    );
}
