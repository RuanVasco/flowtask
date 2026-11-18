
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

import { MarkerType, type Edge, type Node } from '@xyflow/react';
import { NODE_SIZES } from './layout';
import type { Graph, GraphEdge, GraphNode, Strings } from './types';

export interface StepData extends Record<string, unknown> {
    step: GraphNode;
    strings: Strings;
}

export type StepFlowNode = Node<StepData, 'step'>;

export function toFlowNodes(graph: Graph, strings: Strings): StepFlowNode[] {
    return graph.nodes.map((step) => ({
        id: String(step.id),
        type: 'step',
        position: { x: step.x, y: step.y },
        data: { step, strings },
        ...NODE_SIZES[step.type],
    }));
}

/**
 * An Approval has one output per answer, so its edges leave from the handle
 * of their condition and show it as a label (spec 002, look).
 */
export function toFlowEdges(graph: Graph, strings: Strings): Edge[] {
    const types = new Map(graph.nodes.map((node) => [node.id, node.type]));

    return graph.edges.map((edge: GraphEdge) => {
        const fromApproval = types.get(edge.source) === 'approval';
        const classes = ['ft-edge', `ft-edge--${edge.condition}`];
        if (edge.taken) {
            classes.push('ft-edge--taken');
        }

        return {
            id: String(edge.id),
            type: 'flow',
            source: String(edge.source),
            target: String(edge.target),
            sourceHandle: fromApproval ? edge.condition : null,
            label: fromApproval ? strings.conditions[edge.condition] : undefined,
            className: classes.join(' '),
            data: { condition: edge.condition },
            markerEnd: { type: MarkerType.ArrowClosed },
        };
    });
}
