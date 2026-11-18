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
use Glpi\DBAL\QuerySubQuery;
use GlpiPlugin\Flowtask\Enum\InstanceStatus;
use GlpiPlugin\Flowtask\Instance;
use GlpiPlugin\Flowtask\InstanceStep;
use GlpiPlugin\Flowtask\Support\Fields;
use Session;
use Ticket;

/**
 * Refuses to purge the ticket tasks and validations an active instance
 * created (spec 001, AC-13). Purging the ticket itself still purges them.
 */
final class DeletionGuard
{
    /** @var array<int, true> Tickets being purged, whose items may go */
    private static array $purging_tickets = [];

    /**
     * `pre_item_purge` hook on tickets. Nothing is marked when an earlier hook
     * already cancelled the purge, and Ticket::pre_deleteItem() never cancels it.
     */
    public static function beforeTicketPurge(Ticket $ticket): void
    {
        if (is_array($ticket->input)) {
            self::$purging_tickets[$ticket->getID()] = true;
        }
    }

    /**
     * `item_purge` hook on tickets.
     */
    public static function afterTicketPurge(Ticket $ticket): void
    {
        unset(self::$purging_tickets[$ticket->getID()]);
    }

    /**
     * `pre_item_purge` hook on ticket tasks and validations: clearing the
     * input cancels the purge.
     */
    public static function beforeItemPurge(CommonDBTM $item): void
    {
        if (
            isset(self::$purging_tickets[Fields::int($item->fields, 'tickets_id')])
            || !self::isCreatedByActiveInstance($item::class, $item->getID())
        ) {
            return;
        }

        $item->input = false;
        Session::addMessageAfterRedirect(
            __s('This item was created by a running workflow and cannot be deleted.', 'flowtask'),
            false,
            ERROR,
        );
    }

    private static function isCreatedByActiveInstance(string $itemtype, int $items_id): bool
    {
        return countElementsInTable(InstanceStep::getTable(), [
            'itemtype'             => $itemtype,
            'items_id'             => $items_id,
            InstanceStep::$items_id => new QuerySubQuery([
                'SELECT' => 'id',
                'FROM'   => Instance::getTable(),
                'WHERE'  => [
                    'status' => array_map(
                        static fn(InstanceStatus $status): string => $status->value,
                        InstanceStatus::active(),
                    ),
                ],
            ]),
        ]) > 0;
    }
}
