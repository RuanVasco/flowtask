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

use CommonDBTM;
use GlpiPlugin\Flowtask\Enum\NodeType;
use GlpiPlugin\Flowtask\Node;
use GlpiPlugin\Flowtask\Support\Fields;
use Planning;
use TaskTemplate;
use Ticket;
use TicketTask;
use TicketValidation;

/**
 * Creates the ticket task or validation of a step (spec 001, AC-4 and AC-6).
 */
final class StepItemFactory
{
    /**
     * @return CommonDBTM|string The created item, or a translated reason
     */
    public function create(Node $node, Ticket $ticket, ActorResolution $actor): CommonDBTM|string
    {
        return match ($node->getNodeType()) {
            NodeType::TASK     => $this->createTask($node, $ticket, $actor),
            NodeType::APPROVAL => $this->createValidation($node, $ticket, $actor),
            default            => __('This step type does not create any item.', 'flowtask'),
        };
    }

    private function createTask(Node $node, Ticket $ticket, ActorResolution $actor): CommonDBTM|string
    {
        $template_id = Fields::int($node->fields, 'tasktemplates_id');
        if ($template_id <= 0 || !(new TaskTemplate())->getFromDB($template_id)) {
            return __('The task template of the step is missing.', 'flowtask');
        }

        $task = new TicketTask();
        $id   = $task->add([
            'tickets_id'        => $ticket->getID(),
            '_tasktemplates_id' => $template_id,
            // The step decides who does the task and when it is done, not the template.
            'state'             => Planning::TODO,
            'users_id_tech'     => $actor->getUserId(),
            'groups_id_tech'    => $actor->getGroupId(),
        ]);

        return $id !== false ? $task : __('The ticket task could not be created.', 'flowtask');
    }

    private function createValidation(Node $node, Ticket $ticket, ActorResolution $actor): CommonDBTM|string
    {
        $validation = new TicketValidation();
        $id         = $validation->add([
            'tickets_id'         => $ticket->getID(),
            'itemtype_target'    => $actor->itemtype,
            'items_id_target'    => $actor->items_id,
            'comment_submission' => htmlescape(Fields::string($node->fields, 'name')),
        ]);

        return $id !== false ? $validation : __('The approval could not be created.', 'flowtask');
    }
}
