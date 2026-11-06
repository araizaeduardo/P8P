import { BaseEdge, EdgeLabelRenderer, getBezierPath, useReactFlow } from '@xyflow/react';
import { Trash2 } from 'lucide-react';

export default function DeletableEdge({
    id,
    sourceX,
    sourceY,
    targetX,
    targetY,
    sourcePosition,
    targetPosition,
    style,
    markerStart,
    markerEnd,
    interactionWidth,
    pathOptions
}) {
    const { setEdges } = useReactFlow();
    const [path, labelX, labelY] = getBezierPath({
        sourceX,
        sourceY,
        targetX,
        targetY,
        sourcePosition,
        targetPosition,
        curvature: pathOptions?.curvature
    });

    const deleteConnection = (event) => {
        event.stopPropagation();
        setEdges((currentEdges) => currentEdges.filter((edge) => edge.id !== id));
    };

    return (
        <>
            <BaseEdge
                id={id}
                path={path}
                style={style}
                markerStart={markerStart}
                markerEnd={markerEnd}
                interactionWidth={interactionWidth}
            />
            <EdgeLabelRenderer>
                <button
                    type="button"
                    aria-label="Eliminar conexión"
                    title="Eliminar conexión"
                    className="nodrag nopan absolute flex h-7 w-7 items-center justify-center rounded-full border border-slate-600 bg-slate-900 text-slate-400 shadow-md transition-colors hover:border-red-400 hover:bg-red-950 hover:text-red-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-400"
                    style={{
                        transform: `translate(-50%, -50%) translate(${labelX}px, ${labelY}px)`,
                        pointerEvents: 'all'
                    }}
                    onPointerDown={(event) => event.stopPropagation()}
                    onKeyDown={(event) => event.stopPropagation()}
                    onClick={deleteConnection}
                >
                    <Trash2 className="h-3.5 w-3.5" aria-hidden="true" />
                </button>
            </EdgeLabelRenderer>
        </>
    );
}
