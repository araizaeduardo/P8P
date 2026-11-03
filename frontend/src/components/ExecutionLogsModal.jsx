import { useState, useEffect, useCallback } from 'react';
import { apiFetch } from '../api/client';
import { X, Activity, CheckCircle2, XCircle, Clock, ChevronRight, RefreshCw } from 'lucide-react';

export default function ExecutionLogsModal({ isOpen, onClose }) {
    const [logs, setLogs] = useState([]);
    const [loading, setLoading] = useState(false);
    const [selectedLog, setSelectedLog] = useState(null);

    const fetchLogs = useCallback(async () => {
        try {
            const data = await apiFetch('./logs.php');
            if (data.success) {
                setLogs(data.logs || []);
                setSelectedLog(current => data.logs?.find(log => log.id === current?.id) || data.logs?.[0] || null);
            }
        } catch (err) {
            console.error('Error al cargar logs:', err);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        if (!isOpen) return;
        let cancelled = false;
        apiFetch('./logs.php').then(data => {
            if (!cancelled && data.success) {
                setLogs(data.logs || []);
                setSelectedLog(current => data.logs?.find(log => log.id === current?.id) || data.logs?.[0] || null);
            }
        }).catch(err => console.error('Error al cargar logs:', err));
        return () => { cancelled = true; };
    }, [isOpen]);

    if (!isOpen) return null;

    return (
        <div className="fixed inset-0 bg-slate-950/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
            <div className="bg-slate-900 border border-slate-800 rounded-2xl w-full max-w-5xl h-[85vh] flex flex-col shadow-2xl overflow-hidden">

                {/* Cabecera */}
                <div className="p-4 border-b border-slate-800 bg-slate-950/60 flex items-center justify-between">
                    <div className="flex items-center gap-2.5">
                        <div className="p-2 bg-indigo-500/20 text-indigo-400 rounded-lg">
                            <Activity className="w-5 h-5" />
                        </div>
                        <div>
                            <h3 className="font-semibold text-slate-100 text-sm">Historial de Ejecuciones (Execution Logs)</h3>
                            <p className="text-[11px] text-slate-400">Inspecciona los trabajos en cola, estado de nodos y tiempos de respuesta</p>
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        <button
                            onClick={fetchLogs}
                            disabled={loading}
                            className="p-1.5 text-slate-400 hover:text-slate-200 bg-slate-800 hover:bg-slate-700 border border-slate-700 rounded-lg transition"
                            title="Recargar"
                        >
                            <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} />
                        </button>
                        <button
                            onClick={onClose}
                            className="p-1.5 text-slate-400 hover:text-slate-200 bg-slate-800 hover:bg-slate-700 border border-slate-700 rounded-lg transition"
                        >
                            <X className="w-4 h-4" />
                        </button>
                    </div>
                </div>

                {/* Cuerpo principal en 2 columnas */}
                <div className="flex-1 flex overflow-hidden">

                    {/* Lista de Ejecuciones (Izquierda) */}
                    <div className="w-1/3 border-r border-slate-800 bg-slate-950/40 overflow-y-auto p-2 space-y-1.5">
                        {logs.length === 0 ? (
                            <div className="text-center py-10 text-xs text-slate-500 italic">No hay ejecuciones registradas.</div>
                        ) : (
                            logs.map((log) => {
                                const isSuccess = log.status === 'completed' && log.result?.status !== 'failed'
                                    && !Object.values(log.result?.history || {}).some(node => node.status === 'failed');
                                const isSelected = selectedLog?.id === log.id;

                                return (
                                    <button
                                        key={log.id}
                                        onClick={() => setSelectedLog(log)}
                                        className={`w-full text-left p-3 rounded-xl border transition flex items-center justify-between group ${
                                            isSelected
                                                ? 'bg-indigo-600/15 border-indigo-500/50 text-slate-100'
                                                : 'bg-slate-900/50 border-slate-800/80 hover:bg-slate-800/50 text-slate-400'
                                        }`}
                                    >
                                        <div className="space-y-1">
                                            <div className="flex items-center gap-2">
                                                {isSuccess ? (
                                                    <CheckCircle2 className="w-3.5 h-3.5 text-emerald-400" />
                                                ) : (
                                                    <XCircle className="w-3.5 h-3.5 text-rose-400" />
                                                )}
                                                <span className="text-xs font-mono font-semibold text-slate-200">
                                                    #{log.id.toString().substring(0, 8)}
                                                </span>
                                            </div>
                                            <div className="text-[10px] text-slate-500 flex items-center gap-2">
                                                <span className="flex items-center gap-1">
                                                    <Clock className="w-2.5 h-2.5" />
                                                    {log.execution_time_ms} ms
                                                </span>
                                                <span>•</span>
                                                <span>{log.created_at}</span>
                                            </div>
                                        </div>
                                        <ChevronRight className={`w-4 h-4 text-slate-600 group-hover:text-slate-300 ${isSelected ? 'text-indigo-400' : ''}`} />
                                    </button>
                                );
                            })
                        )}
                    </div>

                    {/* Detalle de la Ejecución (Derecha) */}
                    <div className="flex-1 overflow-y-auto p-5 bg-slate-900/80 space-y-4">
                        {selectedLog ? (
                            <>
                                {/* Header Resumen */}
                                <div className="grid grid-cols-3 gap-3">
                                    <div className="p-3 bg-slate-950/60 border border-slate-800 rounded-xl">
                                        <div className="text-[10px] text-slate-500 uppercase tracking-wider font-semibold">Estado</div>
                                        <div className={`text-xs font-bold capitalize mt-0.5 flex items-center gap-1.5 ${selectedLog.status === 'failed' || selectedLog.result?.status === 'failed' ? 'text-rose-400' : 'text-slate-200'}`}>
                                            {selectedLog.status}
                                        </div>
                                    </div>
                                    <div className="p-3 bg-slate-950/60 border border-slate-800 rounded-xl">
                                        <div className="text-[10px] text-slate-500 uppercase tracking-wider font-semibold">Duración Total</div>
                                        <div className="text-xs font-bold mt-0.5 text-indigo-300 font-mono">
                                            {selectedLog.execution_time_ms} ms
                                        </div>
                                    </div>
                                    <div className="p-3 bg-slate-950/60 border border-slate-800 rounded-xl">
                                        <div className="text-[10px] text-slate-500 uppercase tracking-wider font-semibold">Fecha</div>
                                        <div className="text-xs font-semibold mt-0.5 text-slate-300">
                                            {selectedLog.created_at}
                                        </div>
                                    </div>
                                </div>

                                {/* Trazabilidad por Nodo (History) */}
                                <div className="space-y-2">
                                    <h4 className="text-xs font-semibold text-slate-300 uppercase tracking-wider">
                                        Traza de Nodos Ejecutados
                                    </h4>
                                    <div className="space-y-2">
                                        {selectedLog.result?.history &&
                                            Object.entries(selectedLog.result.history).map(([nodeId, nodeExec]) => (
                                                <div
                                                    key={nodeId}
                                                    className="p-3 bg-slate-950 border border-slate-800 rounded-xl font-mono text-xs space-y-2"
                                                >
                                                    <div className="flex items-center justify-between border-b border-slate-800/80 pb-2">
                                                        <span className="text-indigo-400 font-semibold">{nodeId} ({nodeExec.type})</span>
                                                        <span className={`text-[10px] px-2 py-0.5 rounded font-bold ${
                                                            nodeExec.status === 'success' ? 'bg-emerald-500/20 text-emerald-300' : 'bg-rose-500/20 text-rose-300'
                                                        }`}>
                                                            {nodeExec.status} ({nodeExec.execution_time_ms} ms)
                                                        </span>
                                                    </div>
                                                    <div className="text-[11px] text-slate-400 space-y-1">
                                                        <div className="text-slate-500 text-[10px] font-semibold uppercase">Output:</div>
                                                        <pre className="bg-slate-900 p-2 rounded text-[10px] text-slate-300 overflow-x-auto border border-slate-800">
                                                            {JSON.stringify(nodeExec.output, null, 2)}
                                                        </pre>
                                                    </div>
                                                </div>
                                            ))}
                                    </div>
                                </div>

                                {/* Payload RAW */}
                                <div className="space-y-1 pt-2">
                                    <h4 className="text-xs font-semibold text-slate-400 uppercase tracking-wider">Payload Completo (JSON)</h4>
                                    <pre className="p-3 bg-slate-950 border border-slate-800 rounded-xl font-mono text-[11px] text-slate-300 overflow-x-auto max-h-40">
                                        {JSON.stringify(selectedLog.result, null, 2)}
                                    </pre>
                                </div>
                            </>
                        ) : (
                            <div className="text-center py-20 text-xs text-slate-500">
                                Selecciona una ejecución para ver sus detalles.
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}
