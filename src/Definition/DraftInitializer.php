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
use GlpiPlugin\Flowtask\Enum\VersionStatus;
use GlpiPlugin\Flowtask\Node;
use GlpiPlugin\Flowtask\Support\Fields;
use GlpiPlugin\Flowtask\Transition;
use GlpiPlugin\Flowtask\WorkflowVersion;
use Session;

/**
 * Fills a new draft version: a copy of the latest published graph, or a lone
 * START step when the workflow was never published (spec 001, AC-2).
 *
 * Rows are copied as they are, even incomplete ones (e.g. an actor that was
 * purged since): the draft is checked again when it is published.
 */
final readonly class DraftInitializer
{
    public function __construct(private DBmysql $db) {}

    public function initialize(WorkflowVersion $draft): void
    {
        $source_id = $this->findLatestPublished(Fields::int($draft->fields, WorkflowVersion::$items_id));

        if ($source_id === null) {
            $this->insert(Node::getTable(), [
                Node::$items_id => $draft->getID(),
                'name'          => NodeType::START->label(),
                'node_type'     => NodeType::START->value,
            ]);

            return;
        }

        /** @var array<int, int> $node_ids source step id => copied step id */
        $node_ids = [];
        foreach ($this->db->request(['FROM' => Node::getTable(), 'WHERE' => [Node::$items_id => $source_id], 'ORDER' => 'id']) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $node_ids[Fields::int($row, 'id')] = $this->insert(Node::getTable(), $this->copyOf($row, [
                Node::$items_id => $draft->getID(),
            ]));
        }

        foreach ($this->db->request(['FROM' => Transition::getTable(), 'WHERE' => [Transition::$items_id => $source_id], 'ORDER' => 'id']) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $this->insert(Transition::getTable(), $this->copyOf($row, [
                Transition::$items_id             => $draft->getID(),
                'plugin_flowtask_nodes_id_source' => $node_ids[Fields::int($row, 'plugin_flowtask_nodes_id_source')] ?? 0,
                'plugin_flowtask_nodes_id_target' => $node_ids[Fields::int($row, 'plugin_flowtask_nodes_id_target')] ?? 0,
            ]));
        }
    }

    private function findLatestPublished(int $workflows_id): ?int
    {
        $row = $this->db->request([
            'SELECT' => 'id',
            'FROM'   => WorkflowVersion::getTable(),
            'WHERE'  => [
                WorkflowVersion::$items_id => $workflows_id,
                'status'                   => VersionStatus::PUBLISHED->value,
            ],
            'ORDER'  => 'version_number DESC',
            'LIMIT'  => 1,
        ])->current();

        return is_array($row) ? Fields::int($row, 'id') : null;
    }

    /**
     * @param array<mixed>         $row
     * @param array<string, mixed> $overrides
     *
     * @return array<mixed>
     */
    private function copyOf(array $row, array $overrides): array
    {
        unset($row['id'], $row['date_creation'], $row['date_mod']);

        return array_merge($row, $overrides);
    }

    /**
     * @param array<mixed> $values
     */
    private function insert(string $table, array $values): int
    {
        $now = Session::getCurrentTime();
        $this->db->insert($table, $values + ['date_creation' => $now, 'date_mod' => $now]);

        $id = $this->db->insertId();

        return is_numeric($id) ? (int) $id : 0;
    }
}
