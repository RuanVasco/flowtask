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

use DBmysql;
use GlpiPlugin\Flowtask\Instance;
use ITILFollowup;
use Ticket;
use TicketTask;
use TicketValidation;

/**
 * GLPI hooks on tickets and their items, forwarded to the engine.
 */
final class TicketEvents
{
    public static function onTicketAdd(Ticket $ticket): void
    {
        self::engine()->start($ticket);
    }

    /**
     * A ticket changed to a linked category starts its workflow (AC-12). Any
     * update checks that the running instance still has its item (AC-14).
     */
    public static function onTicketUpdate(Ticket $ticket): void
    {
        $engine = self::engine();
        if (in_array('itilcategories_id', $ticket->updates, true)) {
            $engine->start($ticket);
        }

        $engine->checkTicket($ticket);
    }

    public static function onTicketPurge(Ticket $ticket): void
    {
        Instance::purgeForTicket($ticket);
        DeletionGuard::afterTicketPurge($ticket);
    }

    public static function onTaskPrepareUpdate(TicketTask $task): void
    {
        self::engine()->prepareTaskUpdate($task);
    }

    public static function onTaskUpdate(TicketTask $task): void
    {
        self::engine()->handleTaskUpdate($task);
    }

    public static function onValidationUpdate(TicketValidation $validation): void
    {
        self::engine()->handleValidationUpdate($validation);
    }

    public static function onFollowupAdd(ITILFollowup $followup): void
    {
        self::engine()->handleFollowupAdd($followup);
    }

    private static function engine(): WorkflowEngine
    {
        /** @var DBmysql $DB */
        global $DB;

        return WorkflowEngine::fromDb($DB);
    }
}
