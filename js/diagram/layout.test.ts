
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
import { makeNode as node } from './fixtures.test-helper';
import { NODE_SIZES, needsLayout, withPositions } from './layout';
import type { Graph, GraphNode } from './types';

const linear: Graph = {
    nodes: [node(1, 'start'), node(2, 'task'), node(3, 'end')],
    edges: [
        { id: 10, source: 1, target: 2, condition: 'always', taken: false },
        { id: 11, source: 2, target: 3, condition: 'completed', taken: false },
    ],
};

describe('auto layout (spec 002, AC-9)', () => {
    it('lays out a version whose steps all sit at the origin', () => {
        expect(needsLayout(linear)).toBe(true);
    });

    it('keeps saved positions', () => {
        const moved = { ...linear, nodes: [node(1, 'start', 16, 40), node(2, 'task', 300, 40), node(3, 'end', 600, 40)] };

        expect(needsLayout(moved)).toBe(false);
        expect(withPositions(moved)).toBe(moved);
    });

    it('places the steps left to right without overlap', () => {
        const placed = withPositions(linear).nodes;
        const [start, task, end] = placed as [GraphNode, GraphNode, GraphNode];

        expect(start.x + NODE_SIZES.start.width).toBeLessThanOrEqual(task.x);
        expect(task.x + NODE_SIZES.task.width).toBeLessThanOrEqual(end.x);
    });

    it('puts steps left at the origin in a row below the placed ones', () => {
        const mixed = { ...linear, nodes: [node(1, 'start', 16, 40), node(2, 'task'), node(3, 'end')] };
        const [start, task, end] = withPositions(mixed).nodes as [GraphNode, GraphNode, GraphNode];

        expect(start).toMatchObject({ x: 16, y: 40 });
        expect(task.y).toBeGreaterThanOrEqual(40 + NODE_SIZES.start.height);
        expect(task.x).toBe(16);
        expect(end.y).toBe(task.y);
        expect(end.x).toBeGreaterThanOrEqual(task.x + NODE_SIZES.task.width);
    });

    it('leaves an empty graph alone', () => {
        expect(needsLayout({ nodes: [], edges: [] })).toBe(false);
    });
});
