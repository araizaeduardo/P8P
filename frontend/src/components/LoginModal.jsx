import { useState } from 'react';
import { Lock, LogIn } from 'lucide-react';

export default function LoginModal({ isOpen, onLoginSuccess }) {
    const [username, setUsername] = useState('admin');
    const [password, setPassword] = useState('');
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(false);

    if (!isOpen) return null;

    const handleSubmit = async (e) => {
        e.preventDefault();
        setLoading(true);
        setError('');

        try {
            const res = await fetch('./auth.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username, password })
            });

            const data = await res.json();
            if (data.success) {
                localStorage.setItem('p8p_token', data.token);
                localStorage.setItem('p8p_user', data.user.username);
                onLoginSuccess(data.user);
            } else {
                setError(data.error || 'Credenciales inválidas');
            }
        } catch {
            setError('Error de conexión con el servidor de autenticación');
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="fixed inset-0 bg-slate-950/90 backdrop-blur-md z-50 flex items-center justify-center p-4">
            <div className="bg-slate-900 border border-slate-800 p-6 rounded-2xl w-full max-w-sm shadow-2xl space-y-4">
                <div className="text-center space-y-1">
                    <div className="w-12 h-12 bg-indigo-600/20 text-indigo-400 rounded-xl flex items-center justify-center mx-auto mb-2 border border-indigo-500/30">
                        <Lock className="w-6 h-6" />
                    </div>
                    <h3 className="text-lg font-bold text-slate-100">P8P Admin Control</h3>
                    <p className="text-xs text-slate-400">Ingresa tus credenciales configuradas en el .env</p>
                </div>

                {error && (
                    <div className="p-2.5 bg-rose-500/10 border border-rose-500/30 text-rose-300 text-xs rounded-lg text-center">
                        {error}
                    </div>
                )}

                <form onSubmit={handleSubmit} className="space-y-3 text-xs">
                    <div>
                        <label className="block text-slate-400 mb-1 font-medium">Usuario Admin</label>
                        <input
                            type="text"
                            value={username}
                            onChange={(e) => setUsername(e.target.value)}
                            className="w-full bg-slate-950 border border-slate-800 p-2.5 rounded-lg text-slate-200 outline-none focus:border-indigo-500 transition"
                            required
                        />
                    </div>
                    <div>
                        <label className="block text-slate-400 mb-1 font-medium">Contraseña</label>
                        <input
                            type="password"
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                            className="w-full bg-slate-950 border border-slate-800 p-2.5 rounded-lg text-slate-200 outline-none focus:border-indigo-500 transition"
                            required
                        />
                    </div>

                    <button
                        type="submit"
                        disabled={loading}
                        className="w-full py-2.5 bg-indigo-600 hover:bg-indigo-500 font-bold rounded-lg text-white transition flex items-center justify-center gap-2 mt-2"
                    >
                        <LogIn className="w-4 h-4" />
                        {loading ? 'Verificando...' : 'Iniciar Sesión'}
                    </button>
                </form>
            </div>
        </div>
    );
}
