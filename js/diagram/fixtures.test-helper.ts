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

import type { GraphNode, NodeType, Strings } from './types';

/** A step as the server sends it, for unit tests. */
export function makeNode(id: number, type: NodeType, x = 0, y = 0): GraphNode {
    return {
        id,
        type,
        name: `Step ${id}`,
        icon: '',
        actor: '',
        x,
        y,
        state: null,
        problems: [],
        config: {
            actor_type: '',
            users_id: 0,
            users_name: '',
            groups_id: 0,
            groups_name: '',
            manager_level: 1,
            missing_manager_behavior: 'block',
            tasktemplates_id: 0,
            tasktemplates_name: '',
            solve_ticket: false,
        },
    };
}

/** Labels the tests read; the others are never shown by them. */
export const strings = {
    types: { start: 'Start', task: 'Task', approval: 'Approval', end: 'End' },
    conditions: { always: 'Always', completed: 'Completed', approved: 'Approved', rejected: 'Rejected' },
    states: { active: 'In progress', done: 'Done', skipped: 'Skipped', cancelled: 'Cancelled' },
} as Strings;
