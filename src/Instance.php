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
use Ticket;

/**
 * A running workflow on a ticket. At most one per ticket (spec 001, AC-12).
 */
class Instance extends CommonDBChild
{
    public static string $itemtype = Ticket::class;

    public static string $items_id = 'tickets_id';

    public static function getTypeName($nb = 0)
    {
        return _n('Workflow instance', 'Workflow instances', $nb, 'flowtask');
    }

    public function cleanDBonPurge()
    {
        $this->deleteChildrenAndRelationsFromDb([InstanceStep::class]);
    }

    /**
     * `item_purge` hook on tickets: the instance cannot outlive its ticket.
     */
    public static function purgeForTicket(Ticket $ticket): void
    {
        (new self())->deleteByCriteria(['tickets_id' => $ticket->getID()], true);
    }
}
