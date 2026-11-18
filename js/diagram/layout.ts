
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

import dagre from '@dagrejs/dagre';
import type { Graph, GraphNode, NodeType } from './types';

export interface Size {
    width: number;
    height: number;
}

/**
 * Start and End are circles (their name hangs below, outside the box), the
 * others boxes. Steps of a rank share their center line, so handles align.
 */
export const NODE_SIZES: Record<NodeType, Size> = {
    start: { width: 120, height: 56 },
    end: { width: 120, height: 56 },
    task: { width: 220, height: 80 },
    approval: { width: 220, height: 80 },
};

/**
 * A version whose steps all sit at (0, 0) was built without the diagram and
 * is laid out automatically (spec 002, AC-9).
 */
export function needsLayout(graph: Graph): boolean {
    return graph.nodes.length > 0 && graph.nodes.every(atOrigin);
}

/**
 * Positions (top-left corners) of the steps, left to right.
 */
export function layout(graph: Graph): Map<number, { x: number; y: number }> {
    const g = new dagre.graphlib.Graph();
    g.setGraph({ rankdir: 'LR', nodesep: 48, ranksep: 96, marginx: 16, marginy: 16 });
    g.setDefaultEdgeLabel(() => ({}));

    for (const node of graph.nodes) {
        g.setNode(String(node.id), { ...NODE_SIZES[node.type] });
    }
    for (const edge of graph.edges) {
        g.setEdge(String(edge.source), String(edge.target));
    }

    dagre.layout(g);

    const positions = new Map<number, { x: number; y: number }>();
    for (const node of graph.nodes) {
        const placed = g.node(String(node.id));
        const size = NODE_SIZES[node.type];
        positions.set(node.id, {
            x: Math.round(placed.x - size.width / 2),
            y: Math.round(placed.y - size.height / 2),
        });
    }

    return positions;
}

/** Gap between the steps laid out in a row under the placed ones. */
const ROW_GAP = 48;

function atOrigin(node: GraphNode): boolean {
    return node.x === 0 && node.y === 0;
}

/**
 * The graph with every step placed: saved positions, or the automatic layout
 * when there are none. A step still at the origin in a graph that has
 * positions (added through a form) goes in a row below the others, as the
 * automatic layout never puts a step at the origin.
 */
export function withPositions(graph: Graph): Graph {
    if (needsLayout(graph)) {
        const positions = layout(graph);

        return { ...graph, nodes: graph.nodes.map((node) => ({ ...node, ...positions.get(node.id) })) };
    }

    const placed = graph.nodes.filter((node) => !atOrigin(node));
    if (placed.length === graph.nodes.length) {
        return graph;
    }

    const top = Math.max(...placed.map((node) => node.y + NODE_SIZES[node.type].height)) + ROW_GAP;
    let left = Math.min(...placed.map((node) => node.x));

    return {
        ...graph,
        nodes: graph.nodes.map((node) => {
            if (!atOrigin(node)) {
                return node;
            }

            const position = { x: left, y: top };
            left += NODE_SIZES[node.type].width + ROW_GAP;

            return { ...node, ...position };
        }),
    };
}
