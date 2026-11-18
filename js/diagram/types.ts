
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

/**
 * Shapes shared with the server (src/View/GraphBuilder.php and
 * src/View/DiagramView.php). The bundle has no strings of its own: every
 * label comes translated from the server (spec 002, look).
 */

export type NodeType = 'start' | 'task' | 'approval' | 'end';

export type Condition = 'always' | 'completed' | 'approved' | 'rejected';

/** Status of the latest pass of an instance through a step (ticket tab). */
export type StepState = 'active' | 'done' | 'skipped' | 'cancelled';

export interface GraphNode {
    id: number;
    type: NodeType;
    name: string;
    icon: string;
    actor: string;
    x: number;
    y: number;
    state: StepState | null;
}

export interface GraphEdge {
    id: number;
    source: number;
    target: number;
    condition: Condition;
    /** The instance followed this transition. */
    taken: boolean;
}

export interface Graph {
    nodes: GraphNode[];
    edges: GraphEdge[];
}

export interface Strings {
    types: Record<NodeType, string>;
    conditions: Record<Condition, string>;
    states: Record<StepState, string>;
    loading: string;
    load_error: string;
    save_error: string;
    palette: string;
    delete: string;
    close: string;
}

/** Endpoints of the editor; `{id}` stands for the item to delete. */
export interface EditUrls {
    add_node: string;
    positions: string;
    add_transition: string;
    delete_node: string;
    delete_transition: string;
}

interface BaseConfig {
    graph_url: string;
    strings: Strings;
    /** Tabler icon of each step type. */
    icons: Record<NodeType, string>;
}

export type ViewerConfig = BaseConfig & { mode: 'viewer' };

export type EditorConfig = BaseConfig & { mode: 'editor'; urls: EditUrls };

export type DiagramConfig = ViewerConfig | EditorConfig;
