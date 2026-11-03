import { useState, useEffect, useMemo } from 'react';
import * as LucideIcons from 'lucide-react';
import { X, Box, Sparkles, Search, ChevronLeft, ChevronRight } from 'lucide-react';

export default function AddNodeModal({ isOpen, onClose, onAddNode }) {
    return isOpen ? <AddNodeModalContent onClose={onClose} onAddNode={onAddNode} /> : null;
}

function AddNodeModalContent({ onClose, onAddNode }) {
    const [nodes, setNodes] = useState([]);
    const [loading, setLoading] = useState(true);
    const [searchQuery, setSearchQuery] = useState('');
    const [currentPage, setCurrentPage] = useState(1);
    const itemsPerPage = 6; // Cantidad de nodos por página

    useEffect(() => {
        let active = true;
        const controller = new AbortController();
        const fetchNodes = async () => {
            try {
                const token = localStorage.getItem('p8p_token');

                const res = await fetch('./nodes.php', {
                    method: 'GET',
                    signal: controller.signal,
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
                if (active && data.success) {
                    setNodes(data.nodes || []);
                }
            } catch (err) {
                if (err.name !== 'AbortError') console.error('Error al cargar nodos dinámicos:', err);
            } finally {
                if (active) setLoading(false);
            }
        };
        fetchNodes();
        return () => { active = false; controller.abort(); };
    }, []);

    // Filtrar nodos basándose en el input de búsqueda
    const filteredNodes = useMemo(() => {
        if (!searchQuery.trim()) return nodes;
        const query = searchQuery.toLowerCase();
        return nodes.filter(
            (node) =>
                (node.name && node.name.toLowerCase().includes(query)) ||
                (node.description && node.description.toLowerCase().includes(query)) ||
                (node.category && node.category.toLowerCase().includes(query))
        );
    }, [nodes, searchQuery]);

    // Calcular elementos paginados sobre los nodos filtrados
    const totalPages = Math.ceil(filteredNodes.length / itemsPerPage) || 1;
    const paginatedNodes = useMemo(() => {
        const start = (currentPage - 1) * itemsPerPage;
        return filteredNodes.slice(start, start + itemsPerPage);
    }, [filteredNodes, currentPage]);

    // Resetear a la página 1 cuando cambie la búsqueda
    const handleSearchChange = (e) => {
        setSearchQuery(e.target.value);
        setCurrentPage(1);
    };

    // Renderiza el ícono dinámico devuelto por PHP o Box por defecto
    const renderIcon = (iconName) => {
        if (!iconName) return <Box className="w-5 h-5" />;
        const IconComponent = LucideIcons[iconName] || LucideIcons[iconName.charAt(0).toUpperCase() + iconName.slice(1)];
        return IconComponent ? <IconComponent className="w-5 h-5" /> : <Box className="w-5 h-5" />;
    };

    // Renderiza el Badge correspondiente a la categoría
    const renderCategoryBadge = (category = 'Core') => {
        const cat = category.toUpperCase();
        if (cat === 'PREMIUM' || cat === 'PREMIER') {
            return (
                <span className="text-[9px] bg-amber-500/20 text-amber-300 border border-amber-500/30 px-1.5 py-0.5 rounded font-bold flex items-center gap-1">
                    <Sparkles className="w-2.5 h-2.5" /> PREMIUM
                </span>
            );
        }
        if (cat === 'COMMUNITY') {
            return (
                <span className="text-[9px] bg-sky-500/20 text-sky-300 border border-sky-500/30 px-1.5 py-0.5 rounded font-bold">
                    COMMUNITY
                </span>
            );
        }
        return (
            <span className="text-[9px] bg-indigo-500/20 text-indigo-300 border border-indigo-500/30 px-1.5 py-0.5 rounded font-bold">
                CORE
            </span>
        );
    };

    return (
        <div className="fixed inset-0 bg-slate-950/70 backdrop-blur-sm z-50 flex items-center justify-center p-4">
            <div className="bg-slate-800 border border-slate-700 rounded-xl w-full max-w-md p-5 shadow-2xl flex flex-col max-h-[85vh]">
                
                {/* Cabecera */}
                <div className="flex items-center justify-between border-b border-slate-700 pb-3 mb-3">
                    <h3 className="font-semibold text-slate-100 text-sm">Agregar Nodo o Conector</h3>
                    <button onClick={onClose} className="text-slate-400 hover:text-slate-200 p-1 rounded-lg hover:bg-slate-700 transition">
                        <X className="w-5 h-5" />
                    </button>
                </div>

                {/* Buscador */}
                {!loading && nodes.length > 0 && (
                    <div className="relative mb-3">
                        <span className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                            <Search className="w-4 h-4" />
                        </span>
                        <input
                            type="text"
                            value={searchQuery}
                            onChange={handleSearchChange}
                            placeholder="Buscar nodo por nombre o categoría..."
                            className="w-full bg-slate-900 border border-slate-700 rounded-xl pl-9 pr-3 py-2 text-xs text-slate-200 focus:outline-none focus:border-indigo-500 transition"
                            autoFocus
                        />
                    </div>
                )}

                {/* Contenido / Listado */}
                {loading ? (
                    <div className="text-center py-12 text-xs text-slate-400">Descubriendo nodos instalados...</div>
                ) : filteredNodes.length === 0 ? (
                    <div className="text-center py-10 text-xs text-slate-400">
                        No se encontraron nodos que coincidan con &quot;{searchQuery}&quot;
                    </div>
                ) : (
                    <div className="space-y-2 overflow-y-auto pr-1 flex-1 min-h-[280px]">
                        {paginatedNodes.map((node) => (
                            <button
                                key={node.type}
                                onClick={() => {
                                    onAddNode({
                                        type: node.type,
                                        label: node.name,
                                        isStart: node.is_start || false
                                    });
                                    onClose();
                                }}
                                className="w-full flex items-start gap-3 p-3 rounded-lg bg-slate-900/60 hover:bg-slate-700/60 border border-slate-700/50 transition text-left group"
                            >
                                <div className="p-2 rounded-lg bg-indigo-500/20 text-indigo-400 group-hover:bg-indigo-500 group-hover:text-white transition shrink-0 mt-0.5">
                                    {renderIcon(node.icon)}
                                </div>
                                <div className="flex-1 min-w-0">
                                    <div className="flex items-center justify-between">
                                        <span className="text-xs font-semibold text-slate-200 truncate pr-2">{node.name}</span>
                                        {renderCategoryBadge(node.category)}
                                    </div>
                                    <div className="text-[11px] text-slate-400 mt-0.5 line-clamp-1">
                                        {node.description || 'Sin descripción'}
                                    </div>
                                </div>
                            </button>
                        ))}
                    </div>
                )}

                {/* Paginación Inferior */}
                {!loading && totalPages > 1 && (
                    <div className="flex items-center justify-between border-t border-slate-700 pt-3 mt-3 text-xs text-slate-400">
                        <span>
                            Página {currentPage} de {totalPages} ({filteredNodes.length} nodos)
                        </span>
                        <div className="flex items-center gap-1">
                            <button
                                onClick={() => setCurrentPage((p) => Math.max(p - 1, 1))}
                                disabled={currentPage === 1}
                                className="p-1.5 bg-slate-900 hover:bg-slate-700 disabled:opacity-40 disabled:hover:bg-slate-900 rounded-lg text-slate-300 transition"
                            >
                                <ChevronLeft className="w-4 h-4" />
                            </button>
                            <button
                                onClick={() => setCurrentPage((p) => Math.min(p + 1, totalPages))}
                                disabled={currentPage === totalPages}
                                className="p-1.5 bg-slate-900 hover:bg-slate-700 disabled:opacity-40 disabled:hover:bg-slate-900 rounded-lg text-slate-300 transition"
                            >
                                <ChevronRight className="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}