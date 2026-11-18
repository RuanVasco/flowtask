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

namespace GlpiPlugin\Flowtask;

use CommonDBChild;
use GlpiPlugin\Flowtask\Enum\StepStatus;
use GlpiPlugin\Flowtask\Support\Fields;

/**
 * A step reached by an instance, linked to the ticket task or validation it created.
 */
class InstanceStep extends CommonDBChild
{
    public static string $itemtype = Instance::class;

    public static string $items_id = 'plugin_flowtask_instances_id';

    public static function getTypeName($nb = 0)
    {
        return _n('Workflow step', 'Workflow steps', $nb, 'flowtask');
    }

    /**
     * The step the instance waits on. An instance has at most one, as v0.1
     * has no parallel branches.
     */
    public static function getActiveForInstance(int $instances_id): ?self
    {
        return self::findOne([self::$items_id => $instances_id, 'status' => StepStatus::ACTIVE->value]);
    }

    /**
     * The active step waiting on a ticket task or validation.
     */
    public static function getActiveForItem(string $itemtype, int $items_id): ?self
    {
        return self::findOne([
            'itemtype' => $itemtype,
            'items_id' => $items_id,
            'status'   => StepStatus::ACTIVE->value,
        ]);
    }

    public function getNode(): ?Node
    {
        $node = new Node();

        return $node->getFromDB(Fields::int($this->fields, 'plugin_flowtask_nodes_id')) ? $node : null;
    }

    /**
     * @param array<string, mixed> $criteria
     */
    private static function findOne(array $criteria): ?self
    {
        $step = new self();

        return $step->getFromDBByRequest(['WHERE' => $criteria, 'ORDER' => 'id DESC', 'LIMIT' => 1]) ? $step : null;
    }
}
