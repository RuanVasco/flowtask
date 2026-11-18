
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

import { describe, expect, it } from 'vitest';
import { conditionFor, toFlowEdges, toFlowNodes } from './mapping';
import type { Graph, GraphNode, NodeType, Strings } from './types';

function node(id: number, type: NodeType): GraphNode {
    return { id, type, name: `Step ${id}`, icon: '', actor: '', x: id * 10, y: 0, state: null };
}

const strings: Strings = {
    types: { start: 'Start', task: 'Task', approval: 'Approval', end: 'End' },
    conditions: { always: 'Always', completed: 'Completed', approved: 'Approved', rejected: 'Rejected' },
    states: { active: 'In progress', done: 'Done', skipped: 'Skipped', cancelled: 'Cancelled' },
    loading: 'Loading',
    load_error: 'Error',
    save_error: 'Not saved',
    palette: 'Palette',
    delete: 'Delete',
    close: 'Close',
};

const graph: Graph = {
    nodes: [node(1, 'start'), node(2, 'approval'), node(3, 'end'), node(4, 'end')],
    edges: [
        { id: 10, source: 1, target: 2, condition: 'always', taken: true },
        { id: 11, source: 2, target: 3, condition: 'approved', taken: false },
        { id: 12, source: 2, target: 4, condition: 'rejected', taken: false },
    ],
};

describe('graph mapping', () => {
    it('keeps the saved position of each step', () => {
        expect(toFlowNodes(graph, strings).map((n) => n.position)).toEqual([
            { x: 10, y: 0 },
            { x: 20, y: 0 },
            { x: 30, y: 0 },
            { x: 40, y: 0 },
        ]);
    });

    it('labels only the edges leaving an approval, from the handle of their answer', () => {
        const edges = toFlowEdges(graph, strings);

        expect(edges.map((e) => [e.sourceHandle, e.label])).toEqual([
            [null, undefined],
            ['approved', 'Approved'],
            ['rejected', 'Rejected'],
        ]);
    });

    it('marks the transitions the instance followed (spec 002, AC-8)', () => {
        expect(toFlowEdges(graph, strings).map((e) => e.className?.includes('ft-edge--taken'))).toEqual([true, false, false]);
    });

    it('keeps the start step on the diagram (spec 002, AC-5)', () => {
        expect(toFlowNodes(graph, strings).map((n) => n.deletable)).toEqual([false, true, true, true]);
    });
});

describe('condition of a drawn transition (spec 002, AC-3)', () => {
    it('follows the source type when only one condition fits', () => {
        expect(conditionFor('start', null)).toBe('always');
        expect(conditionFor('task', null)).toBe('completed');
    });

    it('follows the handle of an approval', () => {
        expect(conditionFor('approval', 'approved')).toBe('approved');
        expect(conditionFor('approval', 'rejected')).toBe('rejected');
        expect(conditionFor('approval', null)).toBeNull();
    });

    it('has none out of an end step', () => {
        expect(conditionFor('end', null)).toBeNull();
    });
});
