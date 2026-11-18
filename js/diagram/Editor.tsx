/**
 * -------------------------------------------------------------------------
 * Flowtask plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of Flowtask.
 *
 * Flowtask is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Flowtask is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Flowtask. If not, see <https://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2026 by Ruan Vasconcelos.
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://github.com/RuanVasco/flowtask
 * -------------------------------------------------------------------------
 */

import {
    Background,
    Controls,
    Panel,
    ReactFlow,
    ReactFlowProvider,
    useEdgesState,
    useNodesState,
    useReactFlow,
    type Connection,
    type Edge,
    type Node,
    type OnBeforeDelete,
} from '@xyflow/react';
import { useCallback, useEffect, useMemo, useRef, useState, type DragEvent } from 'react';
import { post, RefusedError, withId, type Answer } from './api';
import { canvasProps, colorMode } from './canvas';
import { StepPanel, type StepFields } from './StepPanel';
import { Toolbar } from './Toolbar';
import { NODE_SIZES, withPositions } from './layout';
import { conditionFor, toFlowEdge, toFlowEdges, toFlowNode, toFlowNodes, type StepFlowNode } from './mapping';
import type { EditorConfig, Graph, GraphEdge, GraphNode, NodeType } from './types';

/** Step types the palette offers; START comes with the draft. */
const PALETTE: NodeType[] = ['task', 'approval', 'end'];

const DRAG_TYPE = 'application/x-flowtask-step';

export interface Notice {
    kind: 'error' | 'warning';
    messages: string[];
}

interface Position {
    id: number;
    x: number;
    y: number;
}

/**
 * A step at (0, 0) is taken for one that was never placed (see layout.ts),
 * so the editor never saves that exact position.
 */
function offOrigin(position: Position): Position {
    return position.x === 0 && position.y === 0 ? { ...position, x: 1 } : position;
}

interface EditorProps {
    config: EditorConfig;
    graph: Graph;
    /**
     * Loads the saved graph again, to undo a change the server refused or
     * show what a change did elsewhere; the notice and the selected step
     * are kept.
     */
    reload: (keep?: Kept) => void;
    /** What the previous editor asked to keep across the reload. */
    kept?: Kept;
}

export interface Kept {
    notice?: Notice | null;
    selected?: string;
}

/**
 * Diagram of a draft that the user builds (spec 002, editor). Every action
 * is saved when it happens; a refused one is not applied, or is undone, and
 * its messages are shown on the diagram.
 */
export function Editor(props: EditorProps) {
    return (
        <ReactFlowProvider>
            <EditorCanvas {...props} />
        </ReactFlowProvider>
    );
}

function EditorCanvas({ config, graph, reload, kept }: EditorProps) {
    const { strings, urls, icons } = config;
    const placed = useMemo(() => withPositions(graph), [graph]);
    const [nodes, setNodes, onNodesChange] = useNodesState<StepFlowNode>(
        toFlowNodes(placed, strings, true).map((node) => (node.id === kept?.selected ? { ...node, selected: true } : node)),
    );
    const [edges, setEdges, onEdgesChange] = useEdgesState<Edge>(toFlowEdges(placed, strings));
    const [notice, setNotice] = useState<Notice | null>(kept?.notice ?? null);
    /** Side panel saves on their way, which publishing waits for. */
    const saving = useRef(new Set<Promise<unknown>>());
    const { screenToFlowPosition, deleteElements } = useReactFlow<StepFlowNode, Edge>();

    const fail = useCallback(
        (error: unknown) => {
            const messages = error instanceof RefusedError && error.messages.length > 0 ? error.messages : [strings.save_error];
            setNotice({ kind: 'error', messages });
        },
        [strings],
    );

    const report = useCallback((answer: Answer) => {
        if (answer.warnings !== undefined && answer.warnings.length > 0) {
            setNotice({ kind: 'warning', messages: answer.warnings });
        }
    }, []);

    /**
     * @param undoOnRefusal Load the saved graph again when the server refuses,
     *                      to put moved steps back
     */
    const savePositions = useCallback(
        (moved: Position[], undoOnRefusal: boolean) => {
            if (moved.length === 0) {
                return;
            }

            post(urls.positions, { positions: moved.map(offOrigin) }).then(report, (error: unknown) => {
                fail(error);
                if (undoOnRefusal) {
                    reload();
                }
            });
        },
        [urls, report, fail, reload],
    );

    // The automatic layout is saved in the editor (spec 002, AC-9), once.
    const layoutSaved = useRef(false);
    useEffect(() => {
        if (layoutSaved.current) {
            return;
        }
        layoutSaved.current = true;

        const saved = new Map(graph.nodes.map((node) => [node.id, node]));
        savePositions(
            placed.nodes
                .filter((node) => saved.get(node.id)?.x !== node.x || saved.get(node.id)?.y !== node.y)
                .map(({ id, x, y }) => ({ id, x, y })),
            false,
        );
    }, [graph, placed, savePositions]);

    const onNodeDragStop = useCallback(
        (_event: unknown, _node: Node, dragged: Node[]) => {
            savePositions(
                dragged.map((node) => ({ id: Number(node.id), x: Math.round(node.position.x), y: Math.round(node.position.y) })),
                true,
            );
        },
        [savePositions],
    );

    const onConnect = useCallback(
        async (connection: Connection) => {
            const source = nodes.find((node) => node.id === connection.source);
            const condition = source ? conditionFor(source.data.step.type, connection.sourceHandle) : null;
            if (!source || condition === null) {
                return;
            }

            try {
                const answer = await post<Answer & { edge: GraphEdge }>(urls.add_transition, {
                    source: Number(connection.source),
                    target: Number(connection.target),
                    condition,
                });
                setEdges((current) => [...current, toFlowEdge(answer.edge, source.data.step.type, strings)]);
                report(answer);
            } catch (error) {
                fail(error);
            }
        },
        [nodes, urls, strings, setEdges, report, fail],
    );

    /**
     * Deletes on the server first and keeps on the diagram what it refused.
     * Transitions of a deleted step go with it (spec 002, AC-5).
     */
    const onBeforeDelete: OnBeforeDelete<StepFlowNode, Edge> = useCallback(
        async ({ nodes: asked, edges: askedEdges }) => {
            let refusal: unknown = null;
            const settle = async <T extends Node | Edge>(items: T[], url: string): Promise<T[]> => {
                const results = await Promise.allSettled(items.map((item) => post(withId(url, item.id))));

                return items.filter((_item, i) => {
                    const result = results[i];
                    if (result?.status === 'fulfilled') {
                        report(result.value);

                        return true;
                    }
                    refusal ??= result?.reason;

                    return false;
                });
            };

            const deleted = await settle(asked, urls.delete_node);
            const deletedIds = new Set(deleted.map((node) => node.id));

            // Edges of a deleted step went with it on the server. React Flow
            // also passes the edges of a refused step: only the ones the user
            // selected are deleted then.
            const cascaded = askedEdges.filter((edge) => deletedIds.has(edge.source) || deletedIds.has(edge.target));
            const askedIds = new Set(asked.map((node) => node.id));
            const own = askedEdges.filter(
                (edge) =>
                    !cascaded.includes(edge) && (edge.selected === true || (!askedIds.has(edge.source) && !askedIds.has(edge.target))),
            );
            const deletedEdges = [...cascaded, ...(await settle(own, urls.delete_transition))];

            if (refusal !== null) {
                fail(refusal);
            }

            return { nodes: deleted, edges: deletedEdges };
        },
        [urls, report, fail],
    );

    const onDragOver = useCallback((event: DragEvent) => {
        if (event.dataTransfer.types.includes(DRAG_TYPE)) {
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
        }
    }, []);

    const onDrop = useCallback(
        async (event: DragEvent) => {
            const type = event.dataTransfer.getData(DRAG_TYPE) as NodeType;
            if (!PALETTE.includes(type)) {
                return;
            }
            event.preventDefault();

            const point = screenToFlowPosition({ x: event.clientX, y: event.clientY });
            const size = NODE_SIZES[type];
            try {
                const answer = await post<Answer & { node: GraphNode }>(urls.add_node, {
                    type,
                    name: strings.types[type],
                    x: Math.round(point.x - size.width / 2),
                    y: Math.round(point.y - size.height / 2),
                });
                setNodes((current) => [...current, toFlowNode(answer.node, strings, true)]);
                report(answer);
            } catch (error) {
                fail(error);
            }
        },
        [screenToFlowPosition, urls, strings, setNodes, report, fail],
    );

    /**
     * Saves the side panel. A new type may drop transitions the step can no
     * longer fire, so the graph is loaded again then.
     */
    const saveStep = useCallback(
        async (id: number, fields: StepFields): Promise<boolean> => {
            const before = nodes.find((node) => node.id === String(id))?.data.step;
            const request = post<Answer & { node: GraphNode | null }>(withId(urls.update_node, id), fields);
            saving.current.add(request);
            try {
                const answer = await request;
                const warnings = answer.warnings ?? [];
                const node = answer.node;
                if (node === null || before === undefined || before.type !== node.type) {
                    // A new type may have dropped transitions: show the graph as saved.
                    reload({ notice: warnings.length > 0 ? { kind: 'warning', messages: warnings } : null, selected: String(id) });
                } else {
                    report(answer);
                    setNodes((current) =>
                        current.map((item) =>
                            item.id === String(id)
                                ? { ...item, data: { ...item.data, failed: false, step: { ...node, x: before.x, y: before.y } } }
                                : item,
                        ),
                    );
                }

                return true;
            } catch (error) {
                fail(error);

                return false;
            } finally {
                saving.current.delete(request);
            }
        },
        [nodes, urls, report, fail, reload, setNodes],
    );

    /**
     * Publishes the draft; on failure every step with a problem is
     * highlighted and the problems are listed (spec 002, AC-6).
     */
    const [publishing, setPublishing] = useState(false);
    const publish = useCallback(async () => {
        const url = config.actions?.publish?.url;
        if (url === undefined) {
            return;
        }

        setPublishing(true);
        try {
            // A field left by clicking Publish saves on blur: publish what it saved.
            await Promise.allSettled([...saving.current]);
            await post(url);
            window.location.reload();
        } catch (error) {
            setPublishing(false);
            const problems = error instanceof RefusedError ? (error.data as { problems?: { node: number }[] } | null)?.problems : undefined;
            const failed = new Set((problems ?? []).map((problem) => String(problem.node)));
            setNodes((current) => current.map((node) => ({ ...node, data: { ...node.data, failed: failed.has(node.id) } })));
            if (error instanceof RefusedError && error.messages.length > 0) {
                setNotice({ kind: 'error', messages: [strings.publish_failed, ...error.messages] });
            } else {
                fail(error);
            }
        }
    }, [config.actions, setNodes, strings, fail]);

    const selectedNodes = nodes.filter((node) => node.selected && node.deletable !== false);
    const selectedEdges = edges.filter((edge) => edge.selected);
    const selected = nodes.filter((node) => node.selected);
    const configured = selected.length === 1 ? selected[0] : undefined;

    return (
        <>
        <Toolbar version={config.version} actions={config.actions} strings={strings} onPublish={() => void publish()} busy={publishing} />
        <div className="ft-diagram__canvas">
        <ReactFlow
            {...canvasProps}
            className="ft-diagram--editor"
            colorMode={colorMode()}
            nodes={nodes}
            edges={edges}
            onNodesChange={onNodesChange}
            onEdgesChange={onEdgesChange}
            onNodeDragStop={onNodeDragStop}
            onConnect={onConnect}
            onBeforeDelete={onBeforeDelete}
            onDragOver={onDragOver}
            onDrop={onDrop}
            deleteKeyCode={['Delete', 'Backspace']}
        >
            <Background gap={16} />
            <Controls showInteractive={false} />
            <Panel position="top-left" className="ft-palette">
                <div className="ft-palette__title">{strings.palette}</div>
                {PALETTE.map((type) => (
                    <div
                        key={type}
                        className={`ft-palette__item ft-palette__item--${type}`}
                        draggable
                        onDragStart={(event) => {
                            event.dataTransfer.setData(DRAG_TYPE, type);
                            event.dataTransfer.effectAllowed = 'move';
                        }}
                    >
                        <i className={icons[type]} aria-hidden="true"></i>
                        <span>{strings.types[type]}</span>
                    </div>
                ))}
            </Panel>
            <Panel position="top-right" className="ft-side">
                {(selectedNodes.length > 0 || selectedEdges.length > 0) && (
                    <button
                        type="button"
                        className="btn btn-sm btn-outline-danger"
                        onClick={() => void deleteElements({ nodes: selectedNodes, edges: selectedEdges })}
                    >
                        <i className="ti ti-trash" aria-hidden="true"></i>
                        <span>{strings.delete}</span>
                    </button>
                )}
                {configured !== undefined ? (
                    <StepPanel
                        key={configured.id}
                        step={configured.data.step}
                        config={config}
                        onSave={(fields) => saveStep(Number(configured.id), fields)}
                    />
                ) : (
                    <div className="ft-panel small text-secondary">{strings.panel_hint}</div>
                )}
            </Panel>
            {notice !== null && (
                <Panel position="top-center">
                    <div className={`alert alert-${notice.kind === 'error' ? 'danger' : 'warning'} ft-notice`} role="alert">
                        <div>
                            {notice.messages.map((message) => (
                                <div key={message}>{message}</div>
                            ))}
                        </div>
                        <button type="button" className="btn-close" aria-label={strings.close} onClick={() => setNotice(null)}></button>
                    </div>
                </Panel>
            )}
        </ReactFlow>
        </div>
        </>
    );
}
