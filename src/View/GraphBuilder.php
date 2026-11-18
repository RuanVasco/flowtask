<?php

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

namespace GlpiPlugin\Flowtask\View;

use DBmysql;
use GlpiPlugin\Flowtask\Definition\StepConfiguration;
use GlpiPlugin\Flowtask\Enum\NodeType;
use GlpiPlugin\Flowtask\Enum\StepStatus;
use GlpiPlugin\Flowtask\Enum\TransitionCondition;
use GlpiPlugin\Flowtask\Instance;
use GlpiPlugin\Flowtask\InstanceStep;
use GlpiPlugin\Flowtask\Node;
use GlpiPlugin\Flowtask\Support\Fields;
use GlpiPlugin\Flowtask\Transition;
use GlpiPlugin\Flowtask\WorkflowVersion;
use Group;
use TaskTemplate;
use User;

/**
 * Graph of a version as the diagram reads it (spec 002), with the progress
 * of an instance when it is shown on a ticket (AC-8).
 *
 * @phpstan-type GraphNode array{id: int, type: string, name: string, icon: string, actor: string, x: int, y: int, state: string|null, problems?: list<string>, config?: array<string, mixed>}
 * @phpstan-type GraphEdge array{id: int, source: int, target: int, condition: string, taken: bool}
 * @phpstan-type Graph array{nodes: list<GraphNode>, edges: list<GraphEdge>}
 */
final readonly class GraphBuilder
{
    public function __construct(private DBmysql $db) {}

    /**
     * @return Graph
     */
    public function build(WorkflowVersion $version, ?Instance $instance = null, bool $editable = false): array
    {
        [$states, $taken] = $instance instanceof Instance ? $this->progress($instance) : [[], []];

        $nodes    = [];
        $start_id = 0;
        foreach (Node::getForVersion($version->getID()) as $node) {
            $data = $this->node($node, $states[$node->getID()] ?? null, $editable);
            if ($data === null) {
                continue;
            }

            if ($node->isStart()) {
                $start_id = $node->getID();
            }

            $nodes[] = $data;
        }

        // START is not recorded as a step: it is passed once anything was
        // entered, and the instance waits on it when it got stuck leaving it.
        if ($instance instanceof Instance && $start_id > 0) {
            $start_state = match (true) {
                $states !== []        => StepStatus::DONE,
                $instance->isActive() => StepStatus::ACTIVE,
                default               => null,
            };
            foreach ($nodes as $i => $node) {
                if ($node['id'] === $start_id) {
                    $nodes[$i]['state'] = $start_state?->value;
                }
            }

            if ($states !== []) {
                $taken[$start_id][TransitionCondition::ALWAYS->value] = true;
            }
        }

        $node_ids = array_column($nodes, 'id');

        $edges = [];
        $rows  = $this->db->request([
            'FROM'  => Transition::getTable(),
            'WHERE' => [Transition::$items_id => $version->getID()],
            'ORDER' => 'id',
        ]);
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $edge = $this->edge($row, $taken);
            if (in_array($edge['source'], $node_ids, true) && in_array($edge['target'], $node_ids, true)) {
                $edges[] = $edge;
            }
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * A step as the diagram draws it, or null when it has no valid type.
     *
     * @param bool $editable Add what the editor needs: the problems that
     *                       keep the step from being published and the
     *                       values of the side panel
     *
     * @return GraphNode|null
     */
    public function node(Node $node, ?string $state = null, bool $editable = false): ?array
    {
        $type = $node->getNodeType();
        if (!$type instanceof NodeType) {
            return null;
        }

        $data = [
            'id'    => $node->getID(),
            'type'  => $type->value,
            'name'  => $node->getName(),
            'icon'  => $type->icon(),
            'actor' => $node->getActorLabel(),
            'x'     => Fields::int($node->fields, 'position_x'),
            'y'     => Fields::int($node->fields, 'position_y'),
            'state' => $state,
        ];

        return $editable ? $data + $this->editorData($node) : $data;
    }

    /**
     * @return array{problems: list<string>, config: array<string, mixed>}
     */
    private function editorData(Node $node): array
    {
        $users_id         = Fields::int($node->fields, 'users_id');
        $groups_id        = Fields::int($node->fields, 'groups_id');
        $tasktemplates_id = Fields::int($node->fields, 'tasktemplates_id');

        return [
            // What keeps the step from being published (spec 002, warning markers).
            'problems' => StepConfiguration::getErrors($node->fields),
            // Values of the side panel, with the names its pickers show.
            'config'   => [
                'actor_type'               => Fields::string($node->fields, 'actor_type'),
                'users_id'                 => $users_id,
                'users_name'               => $users_id > 0 ? User::getFriendlyNameById($users_id) : '',
                'groups_id'                => $groups_id,
                'groups_name'              => $groups_id > 0 ? Group::getFriendlyNameById($groups_id) : '',
                'manager_level'            => max(1, Fields::int($node->fields, 'manager_level')),
                'missing_manager_behavior' => Fields::string($node->fields, 'missing_manager_behavior'),
                'tasktemplates_id'         => $tasktemplates_id,
                'tasktemplates_name'       => $tasktemplates_id > 0 ? TaskTemplate::getFriendlyNameById($tasktemplates_id) : '',
                'solve_ticket'             => Fields::int($node->fields, 'solve_ticket') > 0,
            ],
        ];
    }

    /**
     * A transition as the diagram draws it.
     *
     * @param array<mixed>                     $row   Transition row
     * @param array<int, array<string, true>> $taken Conditions each step followed
     *
     * @return GraphEdge
     */
    public function edge(array $row, array $taken = []): array
    {
        $source    = Fields::int($row, 'plugin_flowtask_nodes_id_source');
        $condition = Fields::string($row, 'trigger_condition');

        return [
            'id'        => Fields::int($row, 'id'),
            'source'    => $source,
            'target'    => Fields::int($row, 'plugin_flowtask_nodes_id_target'),
            'condition' => $condition,
            'taken'     => isset($taken[$source][$condition]),
        ];
    }

    /**
     * Status of the latest pass through each step, and the conditions each
     * step followed.
     *
     * @return array{array<int, string>, array<int, array<string, true>>}
     */
    private function progress(Instance $instance): array
    {
        $states = [];
        $taken  = [];
        $rows   = $this->db->request([
            'FROM'  => InstanceStep::getTable(),
            'WHERE' => [InstanceStep::$items_id => $instance->getID()],
            'ORDER' => 'id',
        ]);
        foreach ($rows as $row) {
            $node_id = Fields::int($row, 'plugin_flowtask_nodes_id');
            $status  = StepStatus::tryFrom(Fields::string($row, 'status'));
            if ($status instanceof StepStatus) {
                $states[$node_id] = $status->value;
            }

            $outcome = TransitionCondition::tryFrom(Fields::string($row, 'outcome'));
            if ($outcome instanceof TransitionCondition) {
                $taken[$node_id][$outcome->value] = true;
            }
        }

        return [$states, $taken];
    }
}
