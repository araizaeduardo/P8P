import { Handle, Position } from '@xyflow/react';
import { Globe, Play, Settings, Bot, Cpu, Database, Wrench, Sparkles, GitFork, MessageSquare } from 'lucide-react';
const icons = {
    start: Play,
    chat_trigger: MessageSquare,
    http_request: Globe,
    if_condition: GitFork,
    ai_agent: Bot,
    gemini_model: Sparkles,
    ollama_model: Cpu,
    simple_memory: Database,
    mcp_tool: Wrench,
    default: Settings
};

export default function CustomNode({ data, selected }) {
    const IconComponent = icons[data.type] || icons.default;
    const isAgent = data.type === 'ai_agent';
    const isIfCondition = data.type === 'if_condition';
    const isLoop = data.type === 'loop' || data.type === 'split_in_batches';
    const isSwitch = data.type === 'switch';
    let switchCases = [];
    if (isSwitch) {
        try {
            const cases = data.parameters?.cases;
            const parsed = typeof cases === 'string' ? JSON.parse(cases) : cases;
            switchCases = Array.isArray(parsed) ? parsed.slice(0, 8) : [];
        } catch {
            // Los casos también pueden provenir de una expresión que solo resuelve el backend.
        }
    }

    const isModelNode = ['ollama_model', 'gemini_model', 'openai_model', 'anthropic_model'].includes(data.type);
    const isMemoryNode = data.type === 'simple_memory';
    const isToolNode = data.type === 'mcp_tool';
    const isSubNode = isModelNode || isMemoryNode || isToolNode;

    return (
        <div className={`px-4 py-3 shadow-xl rounded-xl bg-slate-800 border-2 min-w-[200px] relative transition-all ${
            selected ? 'border-indigo-500 ring-2 ring-indigo-500/30' : 'border-slate-700'
        }`}>
            {/* Entrada Principal (Izquierda) */}
            {!data.isStart && !isSubNode && (
                <Handle
                    type="target"
                    position={Position.Left}
                    id="main_input"
                    className="!w-3 !h-3 !bg-indigo-400 !border-2 !border-slate-800"
                />
            )}

            <div className="flex items-center gap-3">
                <div className={`p-2 rounded-lg ${
                    isAgent ? 'bg-purple-500/20 text-purple-400' :
                        isIfCondition ? 'bg-amber-500/20 text-amber-400' :
                            data.isStart ? 'bg-emerald-500/20 text-emerald-400' : 'bg-indigo-500/20 text-indigo-400'
                }`}>
                    <IconComponent className="w-5 h-5" />
                </div>
                <div>
                    <div className="text-[10px] text-slate-400 font-mono uppercase tracking-wider">
                        {data.type || 'Node'}
                    </div>
                    <div className="text-xs font-semibold text-slate-100">
                        {data.label}
                    </div>
                </div>
            </div>

            {/* Salida Estándar a la Derecha */}
            {!isModelNode && !isMemoryNode && !isIfCondition && !isLoop && !isSwitch && (
                <Handle
                    type="source"
                    position={isToolNode ? Position.Top : Position.Right}
                    id={isToolNode ? 'tools' : 'main_output'}
                    className="!w-3 !h-3 !bg-indigo-400 !border-2 !border-slate-800"
                />
            )}

            {/* SALIDAS DOBLES PARA IF CONDITION (True / False) */}
            {isIfCondition && (
                <div className="flex flex-col gap-2 mt-3 pt-2 border-t border-slate-700/60 text-[10px] font-mono">
                    <div className="flex items-center justify-end text-emerald-400 font-bold relative">
                        <span>TRUE</span>
                        <Handle
                            type="source"
                            position={Position.Right}
                            id="true"
                            style={{ top: '65%' }}
                            className="!w-2.5 !h-2.5 !bg-emerald-400 !border-2 !border-slate-800"
                        />
                    </div>
                    <div className="flex items-center justify-end text-rose-400 font-bold relative">
                        <span>FALSE</span>
                        <Handle
                            type="source"
                            position={Position.Right}
                            id="false"
                            style={{ top: '88%' }}
                            className="!w-2.5 !h-2.5 !bg-rose-400 !border-2 !border-slate-800"
                        />
                    </div>
                </div>
            )}

            {isLoop && (
                <div className="flex flex-col gap-2 mt-3 pt-2 border-t border-slate-700/60 text-[10px] font-mono">
                    <div className="flex items-center justify-end text-amber-400 font-bold relative">
                        <span>Cuerpo</span>
                        <Handle type="source" position={Position.Right} id="body"
                            className="!w-2.5 !h-2.5 !bg-amber-400 !border-2 !border-slate-800" />
                    </div>
                    <div className="flex items-center justify-end text-emerald-400 font-bold relative">
                        <span>Finalizado</span>
                        <Handle type="source" position={Position.Right} id="done"
                            className="!w-2.5 !h-2.5 !bg-emerald-400 !border-2 !border-slate-800" />
                    </div>
                </div>
            )}

            {isSwitch && (
                <div className="flex flex-col gap-2 mt-3 pt-2 border-t border-slate-700/60 text-[10px] font-mono">
                    {Array.from({ length: 8 }, (_, index) => (
                        <div key={index} className="flex items-center justify-end text-amber-400 font-bold relative">
                            <span>{typeof switchCases[index]?.label === 'string'
                                ? switchCases[index].label.slice(0, 40) : `Caso ${index + 1}`}</span>
                            <Handle type="source" position={Position.Right} id={`case_${index + 1}`}
                                className="!w-2.5 !h-2.5 !bg-amber-400 !border-2 !border-slate-800" />
                        </div>
                    ))}
                    <div className="flex items-center justify-end text-slate-300 font-bold relative">
                        <span>Predeterminada</span>
                        <Handle type="source" position={Position.Right} id="default"
                            className="!w-2.5 !h-2.5 !bg-slate-400 !border-2 !border-slate-800" />
                    </div>
                </div>
            )}

            {/* SUB-PUERTOS INFERIORES PARA AI AGENT */}
            {isAgent && (
                <div className="flex justify-between items-center pt-3 mt-3 border-t border-slate-700/60 text-[9px] text-slate-400 font-mono">
                    <div className="relative flex flex-col items-center">
                        <span>Model*</span>
                        <Handle
                            type="target"
                            position={Position.Bottom}
                            id="model"
                            className="!w-2.5 !h-2.5 !bg-purple-400 !border-2 !border-slate-800 !-bottom-4"
                        />
                    </div>
                    <div className="relative flex flex-col items-center">
                        <span>Memory</span>
                        <Handle
                            type="target"
                            position={Position.Bottom}
                            id="memory"
                            className="!w-2.5 !h-2.5 !bg-emerald-400 !border-2 !border-slate-800 !-bottom-4"
                        />
                    </div>
                    <div className="relative flex flex-col items-center">
                        <span>Tools</span>
                        <Handle
                            type="target"
                            position={Position.Bottom}
                            id="tools"
                            className="!w-2.5 !h-2.5 !bg-amber-400 !border-2 !border-slate-800 !-bottom-4"
                        />
                    </div>
                </div>
            )}

            {/* Salida Superior para Sub-Nodos */}
            {(isModelNode || isMemoryNode) && (
                <Handle
                    type="source"
                    position={Position.Top}
                    id={isModelNode ? 'model' : 'memory'}
                    className="!w-3 !h-3 !bg-purple-400 !border-2 !border-slate-800"
                />
            )}
        </div>
    );
}
