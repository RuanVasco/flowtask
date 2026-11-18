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

import { Background, Controls, ReactFlow } from '@xyflow/react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { canvasProps, colorMode } from './canvas';
import { Editor } from './Editor';
import { withPositions } from './layout';
import { toFlowEdges, toFlowNodes } from './mapping';
import type { DiagramConfig, Graph, Strings } from './types';

/** `generation` counts the graphs loaded, so the editor restarts from each new one. */
type LoadState = { status: 'loading' } | { status: 'error' } | { status: 'ready'; graph: Graph; generation: number };

async function fetchGraph(url: string): Promise<Graph> {
    const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }

    return (await response.json()) as Graph;
}

/**
 * Loads the graph of the version and shows it in the viewer or the editor
 * (spec 002).
 */
export function Diagram({ config }: { config: DiagramConfig }) {
    const [state, setState] = useState<LoadState>({ status: 'loading' });
    const [loads, setLoads] = useState(0);
    const reload = useCallback(() => setLoads((count) => count + 1), []);

    useEffect(() => {
        let cancelled = false;
        fetchGraph(config.graph_url)
            .then(
                (graph) =>
                    !cancelled &&
                    setState((previous) => ({
                        status: 'ready',
                        graph,
                        generation: previous.status === 'ready' ? previous.generation + 1 : 0,
                    })),
            )
            .catch(() => !cancelled && setState({ status: 'error' }));

        return () => {
            cancelled = true;
        };
    }, [config.graph_url, loads]);

    if (state.status !== 'ready') {
        return (
            <div className="ft-diagram__message">
                {state.status === 'loading' ? config.strings.loading : config.strings.load_error}
            </div>
        );
    }

    return config.mode === 'editor' ? (
        <Editor key={state.generation} config={config} graph={state.graph} reload={reload} />
    ) : (
        <Viewer graph={state.graph} strings={config.strings} />
    );
}

/**
 * Read only diagram (spec 002, viewer). A version without saved positions
 * is laid out, and that layout is not saved.
 */
function Viewer({ graph, strings }: { graph: Graph; strings: Strings }) {
    const placed = useMemo(() => withPositions(graph), [graph]);
    const nodes = useMemo(() => toFlowNodes(placed, strings), [placed, strings]);
    const edges = useMemo(() => toFlowEdges(placed, strings), [placed, strings]);

    return (
        <ReactFlow
            {...canvasProps}
            className="ft-diagram--viewer"
            colorMode={colorMode()}
            nodes={nodes}
            edges={edges}
            nodesDraggable={false}
            nodesConnectable={false}
            elementsSelectable={false}
            edgesFocusable={false}
            nodesFocusable={false}
        >
            <Background gap={16} />
            <Controls showInteractive={false} />
        </ReactFlow>
    );
}
