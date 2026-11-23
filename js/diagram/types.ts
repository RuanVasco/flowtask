
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

/** Values of the side panel, with the names its pickers show. */
export interface StepConfig {
    actor_type: string;
    users_id: number;
    users_name: string;
    groups_id: number;
    groups_name: string;
    manager_level: number;
    missing_manager_behavior: string;
    tasktemplates_id: number;
    tasktemplates_name: string;
    solve_ticket: boolean;
    ticket_status: number;
}

export interface GraphNode {
    id: number;
    type: NodeType;
    name: string;
    icon: string;
    actor: string;
    x: number;
    y: number;
    state: StepState | null;
    /** What keeps the step from being published (editor only). */
    problems?: string[];
    /** Values of the side panel (editor only). */
    config?: StepConfig;
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
    edit: string;
    publish: string;
    discard: string;
    publish_failed: string;
    panel_hint: string;
    start_hint: string;
    name: string;
    type: string;
    actor: string;
    user: string;
    group: string;
    manager_level: string;
    manager_help: string;
    missing_manager: string;
    task_template: string;
    solve_ticket: string;
    ticket_status: string;
    none: string;
    search: string;
    no_results: string;
    problems: string;
}

/** Endpoints of the editor; `{id}` stands for the item to change. */
export interface EditUrls {
    add_node: string;
    update_node: string;
    positions: string;
    add_transition: string;
    delete_node: string;
    delete_transition: string;
}

/** A GLPI dropdown endpoint and the parameters it checks (IDOR token, rights, entities). */
export interface Picker {
    url: string;
    params: Record<string, string>;
}

export type PickerKind = 'user' | 'group' | 'tasktemplate';

export interface Options {
    step_types: Record<string, string>;
    actor_types: Record<string, string>;
    behaviors: Record<string, string>;
    /** Ticket::* status values, 0 for "do not change" (spec 003). */
    ticket_statuses: Record<string, string>;
    /** Status a step gets when its actor becomes the requester and it had none. */
    requester_ticket_status: number;
}

/** A form posted to a GLPI page (version form), as the version card does. */
export interface FormAction {
    url: string;
    fields: Record<string, string | number>;
    confirm?: string;
}

export interface Actions {
    edit?: FormAction;
    discard?: FormAction;
    publish?: { url: string };
}

export interface VersionInfo {
    label: string;
    status: 'draft' | 'published' | 'archived';
    status_label: string;
}

interface BaseConfig {
    graph_url: string;
    strings: Strings;
    /** Tabler icon of each step type. */
    icons: Record<NodeType, string>;
    /** Only on the workflow, which offers the version actions. */
    version?: VersionInfo;
    actions?: Actions;
}

export type ViewerConfig = BaseConfig & { mode: 'viewer' };

export type EditorConfig = BaseConfig & {
    mode: 'editor';
    urls: EditUrls;
    pickers: Record<PickerKind, Picker>;
    options: Options;
};

export type DiagramConfig = ViewerConfig | EditorConfig;
