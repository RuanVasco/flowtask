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

namespace GlpiPlugin\Flowtask\Definition;

use DBmysql;
use GlpiPlugin\Flowtask\Enum\NodeType;
use GlpiPlugin\Flowtask\Enum\TransitionCondition;
use GlpiPlugin\Flowtask\Node;
use GlpiPlugin\Flowtask\Support\Fields;
use GlpiPlugin\Flowtask\Transition;

/**
 * Checks that the graph of a version can be published (spec 001, AC-1 and
 * definition management rules).
 */
final readonly class GraphValidator
{
    public function __construct(private DBmysql $db) {}

    /**
     * @return list<string> Translated problems, empty when the graph is valid
     */
    public function validateVersion(int $versions_id): array
    {
        $nodes = [];
        foreach ($this->db->request(['FROM' => Node::getTable(), 'WHERE' => [Node::$items_id => $versions_id]]) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $nodes[Fields::int($row, 'id')] = $row;
        }

        $transitions = [];
        foreach ($this->db->request(['FROM' => Transition::getTable(), 'WHERE' => [Transition::$items_id => $versions_id]]) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $transitions[] = $row;
        }

        return $this->validate($nodes, $transitions);
    }

    /**
     * @param array<int, array<mixed>> $nodes       Step rows, keyed by id
     * @param list<array<mixed>>       $transitions Transition rows
     *
     * @return list<string> Translated problems, empty when the graph is valid
     */
    public function validate(array $nodes, array $transitions): array
    {
        $errors = [];

        $starts = array_keys(array_filter(
            $nodes,
            static fn(array $node): bool => Fields::string($node, 'node_type') === NodeType::START->value,
        ));
        if (count($starts) !== 1) {
            $errors[] = __('The version must have exactly one start step.', 'flowtask');
        }

        $has_end = array_filter(
            $nodes,
            static fn(array $node): bool => Fields::string($node, 'node_type') === NodeType::END->value,
        ) !== [];
        if (!$has_end) {
            $errors[] = __('The version needs at least one end step.', 'flowtask');
        }

        /** @var array<int, list<string>> $outgoing conditions fired by each source step */
        $outgoing = [];
        /** @var array<int, list<int>> $edges */
        $edges = [];
        foreach ($transitions as $transition) {
            $source = Fields::int($transition, 'plugin_flowtask_nodes_id_source');
            $target = Fields::int($transition, 'plugin_flowtask_nodes_id_target');

            // Saved transitions were valid, but a step may have changed type or
            // gone since: check them again rather than trust them.
            $error = $this->getTransitionError($nodes, $source, $target, Fields::string($transition, 'trigger_condition'));
            if ($error !== null) {
                $errors[] = $error;

                continue;
            }

            $outgoing[$source][] = Fields::string($transition, 'trigger_condition');
            $edges[$source][]    = $target;
        }

        foreach ($nodes as $id => $node) {
            $name = Fields::string($node, 'name');

            foreach (StepConfiguration::getErrors($node) as $error) {
                $errors[] = sprintf(__('Step "%1$s": %2$s', 'flowtask'), $name, $error);
            }

            $type = NodeType::tryFrom(Fields::string($node, 'node_type'));
            if ($type === null) {
                continue;
            }

            foreach (TransitionCondition::forSource($type) as $condition) {
                if (!in_array($condition->value, $outgoing[$id] ?? [], true)) {
                    $errors[] = sprintf(
                        __('Step "%1$s" has no "%2$s" transition.', 'flowtask'),
                        $name,
                        $condition->label(),
                    );
                }
            }
        }

        if (count($starts) === 1) {
            $reached = $this->reachableFrom($starts[0], $edges);
            foreach ($nodes as $id => $node) {
                if (!isset($reached[$id])) {
                    $errors[] = sprintf(
                        __('Step "%s" cannot be reached from the start step.', 'flowtask'),
                        Fields::string($node, 'name'),
                    );
                }
            }
        }

        return $errors;
    }

    /**
     * @param array<int, array<mixed>> $nodes
     */
    private function getTransitionError(array $nodes, int $source, int $target, string $condition): ?string
    {
        if (!isset($nodes[$source], $nodes[$target])) {
            return __('A transition points at a step that is not in this version.', 'flowtask');
        }

        $source_name = Fields::string($nodes[$source], 'name');
        $source_type = NodeType::tryFrom(Fields::string($nodes[$source], 'node_type'));
        $fired       = TransitionCondition::tryFrom($condition);

        if (Fields::string($nodes[$target], 'node_type') === NodeType::START->value) {
            return sprintf(__('Step "%s" has a transition into the start step.', 'flowtask'), $source_name);
        }

        if ($source_type === null || $fired === null || !in_array($fired, TransitionCondition::forSource($source_type), true)) {
            return sprintf(
                __('Step "%1$s" cannot fire its "%2$s" transition.', 'flowtask'),
                $source_name,
                $fired?->label() ?? $condition,
            );
        }

        return null;
    }

    /**
     * @param array<int, list<int>> $edges
     *
     * @return array<int, true>
     */
    private function reachableFrom(int $start, array $edges): array
    {
        $reached = [$start => true];
        $queue   = [$start];

        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($edges[$current] ?? [] as $next) {
                if (!isset($reached[$next])) {
                    $reached[$next] = true;
                    $queue[]        = $next;
                }
            }
        }

        return $reached;
    }
}
