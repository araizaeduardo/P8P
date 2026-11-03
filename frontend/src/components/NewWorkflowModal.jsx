import { FilePlus, AlertTriangle, X } from 'lucide-react';

export default function NewWorkflowModal({ isOpen, onClose, onConfirm }) {
    if (!isOpen) return null;

    return (
        <div className="fixed inset-0 bg-slate-950/70 backdrop-blur-sm z-50 flex items-center justify-center p-4">
            <div className="bg-slate-800 border border-slate-700 rounded-xl w-full max-w-md p-5 shadow-2xl flex flex-col space-y-4">
                <div className="flex items-center justify-between border-b border-slate-700 pb-3">
                    <div className="flex items-center gap-2 text-indigo-400">
                        <FilePlus className="w-5 h-5" />
                        <h3 className="font-semibold text-slate-100 text-sm">Crear Nuevo Workflow</h3>
                    </div>
                    <button
                        onClick={onClose}
                        className="text-slate-400 hover:text-slate-200 p-1 rounded-lg hover:bg-slate-700 transition"
                    >
                        <X className="w-4 h-4" />
                    </button>
                </div>

                <div className="p-3 bg-amber-500/10 border border-amber-500/30 rounded-xl flex items-start gap-3 text-amber-300 text-xs">
                    <AlertTriangle className="w-5 h-5 shrink-0 mt-0.5 text-amber-400" />
                    <p className="leading-relaxed">
                        ¿Estás seguro de reiniciar el lienzo? Los cambios que no hayas guardado en el workflow actual se perderán.
                    </p>
                </div>

                <div className="flex gap-2 justify-end pt-2">
                    <button
                        type="button"
                        onClick={onClose}
                        className="px-3.5 py-2 text-xs font-medium text-slate-300 hover:bg-slate-700 rounded-lg transition"
                    >
                        Cancelar
                    </button>
                    <button
                        type="button"
                        onClick={() => {
                            onConfirm();
                            onClose();
                        }}
                        className="px-3.5 py-2 text-xs font-semibold bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg transition"
                    >
                        Sí, Limpiar Lienzo
                    </button>
                </div>
            </div>
        </div>
    );
}
