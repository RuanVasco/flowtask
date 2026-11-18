
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

import { Background, Controls, ReactFlow, type ColorMode } from '@xyflow/react';
import { useEffect, useMemo, useState } from 'react';
import { FlowEdge } from './FlowEdge';
import { withPositions } from './layout';
import { toFlowEdges, toFlowNodes } from './mapping';
import { StepNode } from './StepNode';
import type { DiagramConfig, Graph } from './types';

const nodeTypes = { step: StepNode };
const edgeTypes = { flow: FlowEdge };

/** Follows the GLPI theme of the page (light or dark). */
function colorMode(): ColorMode {
    return document.documentElement.dataset.glpiThemeDark === '1' ? 'dark' : 'light';
}

type LoadState = { status: 'loading' } | { status: 'error' } | { status: 'ready'; graph: Graph };

async function fetchGraph(url: string): Promise<Graph> {
    const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }

    return (await response.json()) as Graph;
}

/**
 * Read only diagram of a version (spec 002, viewer). Saved positions are
 * used as they are; a version without any is laid out, and that layout is
 * not saved.
 */
export function Diagram({ config }: { config: DiagramConfig }) {
    const [state, setState] = useState<LoadState>({ status: 'loading' });

    useEffect(() => {
        let cancelled = false;
        fetchGraph(config.graph_url)
            .then((graph) => !cancelled && setState({ status: 'ready', graph: withPositions(graph) }))
            .catch(() => !cancelled && setState({ status: 'error' }));

        return () => {
            cancelled = true;
        };
    }, [config.graph_url]);

    const graph = state.status === 'ready' ? state.graph : null;
    const nodes = useMemo(() => (graph ? toFlowNodes(graph, config.strings) : []), [graph, config.strings]);
    const edges = useMemo(() => (graph ? toFlowEdges(graph, config.strings) : []), [graph, config.strings]);

    if (state.status !== 'ready') {
        return (
            <div className="ft-diagram__message">
                {state.status === 'loading' ? config.strings.loading : config.strings.load_error}
            </div>
        );
    }

    return (
        <ReactFlow
            className="ft-diagram--viewer"
            nodes={nodes}
            edges={edges}
            nodeTypes={nodeTypes}
            edgeTypes={edgeTypes}
            colorMode={colorMode()}
            nodesDraggable={false}
            nodesConnectable={false}
            elementsSelectable={false}
            edgesFocusable={false}
            nodesFocusable={false}
            fitView
            fitViewOptions={{ padding: 0.15, maxZoom: 1.25 }}
            minZoom={0.2}
        >
            <Background gap={16} />
            <Controls showInteractive={false} />
        </ReactFlow>
    );
}
