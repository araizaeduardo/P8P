import { useEffect, useState } from 'react';
import { FolderOpen, X, Clock } from 'lucide-react';

export default function LoadWorkflowModal({ isOpen, onClose, onLoadWorkflow }) {
    return isOpen ? <LoadWorkflowModalContent onClose={onClose} onLoadWorkflow={onLoadWorkflow} /> : null;
}

function LoadWorkflowModalContent({ onClose, onLoadWorkflow }) {
    const [workflows, setWorkflows] = useState([]);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        let active = true;
        const controller = new AbortController();
        const fetchWorkflows = async () => {
            try {
                // Extraer el token guardado en el navegador durante el inicio de sesión
                const token = localStorage.getItem('p8p_token');

                const res = await fetch('./get_workflows.php', {
                    method: 'GET',
                    signal: controller.signal,
                    headers: {
                        'Content-Type': 'application/json',
                        ...(token ? { 'Authorization': `Bearer ${token}` } : {})
                    }
                });

                // Si el backend devuelve 401 Unauthorized, expira la sesión local
                if (res.status === 401) {
                    localStorage.removeItem('p8p_token');
                    localStorage.removeItem('p8p_user');
                    window.location.reload();
                    return;
                }

                const data = await res.json();
                if (active && data.success && Array.isArray(data.workflows)) {
                    setWorkflows(data.workflows);
                }
            } catch (err) {
                if (err.name !== 'AbortError') console.error('Error al cargar workflows:', err);
            } finally {
                if (active) setLoading(false);
            }
        };
        fetchWorkflows();
        return () => { active = false; controller.abort(); };
    }, []);

    return (
        <div className="fixed inset-0 bg-slate-950/70 backdrop-blur-sm z-50 flex items-center justify-center p-4">
            <div className="bg-slate-800 border border-slate-700 rounded-xl w-full max-w-lg p-5 shadow-2xl">
                <div className="flex items-center justify-between border-b border-slate-700 pb-3 mb-4">
                    <div className="flex items-center gap-2">
                        <FolderOpen className="w-5 h-5 text-indigo-400" />
                        <h3 className="font-semibold text-slate-100">Abrir Workflow Guardado</h3>
                    </div>
                    <button
                        onClick={onClose}
                        className="text-slate-400 hover:text-slate-200 p-1 rounded-lg hover:bg-slate-700"
                    >
                        <X className="w-5 h-5" />
                    </button>
                </div>

                {loading ? (
                    <div className="py-8 text-center text-slate-400 text-sm">Cargando flujos desde MariaDB...</div>
                ) : workflows.length === 0 ? (
                    <div className="py-8 text-center text-slate-400 text-sm">No hay workflows guardados aún.</div>
                ) : (
                    <div className="space-y-2 max-h-64 overflow-y-auto pr-1">
                        {workflows.map((wf) => (
                            <button
                                key={wf.id}
                                onClick={() => onLoadWorkflow(wf.id)}
                                className="w-full flex items-center justify-between p-3 rounded-lg bg-slate-900/60 hover:bg-slate-700/60 border border-slate-700/50 hover:border-indigo-500/50 transition text-left group"
                            >
                                <div>
                                    <div className="text-sm font-semibold text-slate-200 group-hover:text-indigo-300 transition">
                                        {wf.name}
                                    </div>
                                    <div className="text-xs text-slate-500 font-mono flex items-center gap-1 mt-0.5">
                                        <Clock className="w-3 h-3" /> {wf.updated_at}
                                    </div>
                                </div>
                                <span className="text-[10px] font-mono bg-indigo-500/10 text-indigo-400 border border-indigo-500/20 px-2 py-1 rounded">
                                    {wf.id.substring(0, 8)}...
                                </span>
                            </button>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}
