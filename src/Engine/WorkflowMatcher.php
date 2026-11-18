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

namespace GlpiPlugin\Flowtask\Engine;

use GlpiPlugin\Flowtask\Support\Fields;
use GlpiPlugin\Flowtask\Workflow;
use GlpiPlugin\Flowtask\Workflow_ITILCategory;
use GlpiPlugin\Flowtask\WorkflowVersion;
use Ticket;

/**
 * Finds the published version a ticket starts on (spec 001, engine rules, AC-3).
 */
final class WorkflowMatcher
{
    public function findVersion(Ticket $ticket): ?WorkflowVersion
    {
        if (Fields::int($ticket->fields, 'is_deleted') === 1 || $ticket->isSolved(true)) {
            return null;
        }

        $category_id = Fields::int($ticket->fields, 'itilcategories_id');
        $link        = new Workflow_ITILCategory();
        $workflow    = new Workflow();
        if (
            $category_id <= 0
            || !$link->getFromDBByCrit(['itilcategories_id' => $category_id])
            || !$workflow->getFromDB(Fields::int($link->fields, 'plugin_flowtask_workflows_id'))
            || Fields::int($workflow->fields, 'is_active') !== 1
            || !$this->isVisibleIn($workflow, Fields::int($ticket->fields, 'entities_id'))
        ) {
            return null;
        }

        return $workflow->getPublishedVersion();
    }

    private function isVisibleIn(Workflow $workflow, int $entities_id): bool
    {
        $workflow_entity = $workflow->getEntityID();
        if ($workflow_entity === $entities_id) {
            return true;
        }

        return $workflow->isRecursive()
            && in_array($entities_id, array_map(intval(...), getSonsOf('glpi_entities', $workflow_entity)), true);
    }
}
