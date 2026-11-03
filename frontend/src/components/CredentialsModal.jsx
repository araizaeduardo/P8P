import { useState, useEffect } from 'react';
import { Key, Plus, Trash2, X, ShieldCheck, Lock } from 'lucide-react';

async function fetchCredentials() {
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
            return null;
        }

        const data = await res.json();
        if (data.success) return data.credentials || [];
    } catch (err) {
        console.error('Error al cargar credenciales:', err);
    }
    return null;
}

export default function CredentialsModal({ isOpen, onClose }) {
    const [credentials, setCredentials] = useState([]);
    const [isAdding, setIsAdding] = useState(false);
    const [name, setName] = useState('');
    const [type, setType] = useState('gemini_api');
    const [apiKey, setApiKey] = useState('');
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        if (!isOpen) return;
        let active = true;
        fetchCredentials().then((list) => {
            if (active && list !== null) setCredentials(list);
        });
        return () => { active = false; };
    }, [isOpen]);

    const refreshCredentials = async () => {
        const list = await fetchCredentials();
        if (list !== null) setCredentials(list);
    };

    const handleSave = async (e) => {
        e.preventDefault();
        if (!name || !apiKey) return;

        setLoading(true);
        try {
            const token = localStorage.getItem('p8p_token');
            const res = await fetch('./credentials.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    ...(token ? { 'Authorization': `Bearer ${token}` } : {})
                },
                body: JSON.stringify({
                    name,
                    type,
                    data: { api_key: apiKey }
                })
            });

            if (res.status === 401) {
                localStorage.removeItem('p8p_token');
                localStorage.removeItem('p8p_user');
                window.location.reload();
                return;
            }

            const data = await res.json();
            if (data.success) {
                setName('');
                setApiKey('');
                setIsAdding(false);
                refreshCredentials();
            }
        } catch (err) {
            alert('Error al guardar credencial: ' + err.message);
        } finally {
            setLoading(false);
        }
    };

    const handleDelete = async (id) => {
        if (!confirm('¿Seguro que deseas eliminar esta credencial?')) return;
        try {
            const token = localStorage.getItem('p8p_token');
            const res = await fetch(`./credentials.php?id=${id}`, {
                method: 'DELETE',
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

            refreshCredentials();
        } catch {
            alert('Error al eliminar credencial');
        }
    };

    if (!isOpen) return null;

    return (
        <div className="fixed inset-0 bg-slate-950/75 backdrop-blur-sm z-50 flex items-center justify-center p-4">
            <div className="bg-slate-800 border border-slate-700 rounded-xl w-full max-w-lg p-5 shadow-2xl flex flex-col max-h-[85vh]">
                {/* Header */}
                <div className="flex items-center justify-between border-b border-slate-700 pb-3 mb-4">
                    <div className="flex items-center gap-2 text-indigo-400">
                        <ShieldCheck className="w-5 h-5" />
                        <h3 className="font-semibold text-slate-100 text-sm">Gestor de Credenciales Seguras</h3>
                    </div>
                    <button onClick={onClose} className="text-slate-400 hover:text-slate-200 p-1 rounded-lg hover:bg-slate-700">
                        <X className="w-4 h-4" />
                    </button>
                </div>

                {/* Form para agregar */}
                {isAdding ? (
                    <form onSubmit={handleSave} className="space-y-3 bg-slate-900/60 p-4 rounded-xl border border-slate-700/60 mb-4">
                        <div>
                            <label className="block text-xs font-medium text-slate-300 mb-1">Nombre de la Credencial</label>
                            <input
                                type="text"
                                required
                                value={name}
                                onChange={(e) => setName(e.target.value)}
                                placeholder="ej. Mi Google AI Studio Key"
                                className="w-full bg-slate-900 border border-slate-700 rounded p-2 text-xs text-slate-200 focus:outline-none focus:border-indigo-500"
                            />
                        </div>

                        <div>
                            <label className="block text-xs font-medium text-slate-300 mb-1">Tipo de Servicio</label>
                            <select
                                value={type}
                                onChange={(e) => setType(e.target.value)}
                                className="w-full bg-slate-900 border border-slate-700 rounded p-2 text-xs text-slate-200 focus:outline-none focus:border-indigo-500"
                            >
                                <option value="gemini_api">Google Gemini API</option>
                                <option value="openai_api">OpenAI API</option>
                                <option value="generic_bearer">Bearer Token / API Key</option>
                            </select>
                        </div>

                        <div>
                            <label className="block text-xs font-medium text-slate-300 mb-1 flex items-center gap-1">
                                <Lock className="w-3 h-3 text-emerald-400" /> API Key (Encriptado AES-256)
                            </label>
                            <input
                                type="password"
                                required
                                value={apiKey}
                                onChange={(e) => setApiKey(e.target.value)}
                                placeholder="AIzaSy..."
                                className="w-full bg-slate-900 border border-slate-700 rounded p-2 text-xs text-slate-200 font-mono focus:outline-none focus:border-indigo-500"
                            />
                        </div>

                        <div className="flex gap-2 justify-end pt-2">
                            <button
                                type="button"
                                onClick={() => setIsAdding(false)}
                                className="px-3 py-1.5 text-xs text-slate-400 hover:bg-slate-700 rounded-lg transition"
                            >
                                Cancelar
                            </button>
                            <button
                                type="submit"
                                disabled={loading}
                                className="px-3 py-1.5 text-xs bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg transition font-medium"
                            >
                                {loading ? 'Guardando...' : 'Guardar Credencial'}
                            </button>
                        </div>
                    </form>
                ) : (
                    <button
                        onClick={() => setIsAdding(true)}
                        className="w-full flex items-center justify-center gap-2 p-2.5 mb-4 border border-dashed border-slate-600 hover:border-indigo-500/80 rounded-xl text-xs text-slate-300 hover:text-indigo-400 bg-slate-900/30 transition"
                    >
                        <Plus className="w-4 h-4" /> Agregar Nueva Credencial
                    </button>
                )}

                {/* Lista de Credenciales */}
                <div className="flex-1 overflow-y-auto space-y-2 pr-1">
                    {credentials.length === 0 ? (
                        <div className="text-center py-8 text-xs text-slate-500">
                            No hay credenciales guardadas. Las claves guardadas se cifran automáticamente.
                        </div>
                    ) : (
                        credentials.map((cred) => (
                            <div
                                key={cred.id}
                                className="flex items-center justify-between p-3 bg-slate-900/80 border border-slate-700/60 rounded-xl"
                            >
                                <div className="flex items-center gap-3">
                                    <div className="p-2 rounded-lg bg-indigo-500/10 text-indigo-400">
                                        <Key className="w-4 h-4" />
                                    </div>
                                    <div>
                                        <div className="text-xs font-semibold text-slate-200">{cred.name}</div>
                                        <div className="text-[10px] text-slate-400 font-mono uppercase">{cred.type}</div>
                                    </div>
                                </div>

                                <button
                                    onClick={() => handleDelete(cred.id)}
                                    className="p-1.5 text-slate-500 hover:text-red-400 hover:bg-red-500/10 rounded-lg transition"
                                    title="Eliminar credencial"
                                >
                                    <Trash2 className="w-4 h-4" />
                                </button>
                            </div>
                        ))
                    )}
                </div>
            </div>
        </div>
    );
}
