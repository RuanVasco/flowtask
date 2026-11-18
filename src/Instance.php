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
use CronTask;
use DBmysql;
use GlpiPlugin\Flowtask\Engine\WorkflowEngine;
use GlpiPlugin\Flowtask\Enum\InstanceStatus;
use GlpiPlugin\Flowtask\Support\Fields;
use Ticket;

/**
 * A running workflow on a ticket. At most one per ticket (spec 001, AC-12).
 */
class Instance extends CommonDBChild
{
    public static string $itemtype = Ticket::class;

    public static string $items_id = 'tickets_id';

    public const CRON_CHECK = 'checkInstances';

    public static function getTypeName($nb = 0)
    {
        return _n('Workflow instance', 'Workflow instances', $nb, 'flowtask');
    }

    public static function getForTicket(int $tickets_id): ?self
    {
        $instance = new self();

        return $instance->getFromDBByCrit([self::$items_id => $tickets_id]) ? $instance : null;
    }

    public function getStatus(): ?InstanceStatus
    {
        return InstanceStatus::tryFrom(Fields::string($this->fields, 'status'));
    }

    /**
     * Running or stuck: the instance has not ended yet (AC-13).
     */
    public function isActive(): bool
    {
        return in_array($this->getStatus(), InstanceStatus::active(), true);
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

    /**
     * @return array{description?: string}
     */
    public static function cronInfo(string $name): array
    {
        return $name === self::CRON_CHECK
            ? ['description' => __('Flag workflow instances whose task or approval was removed', 'flowtask')]
            : [];
    }

    /**
     * Automatic action flagging running instances that lost the item of their
     * active step (spec 001, AC-14).
     */
    public static function cronCheckInstances(CronTask $task): int
    {
        /** @var DBmysql $DB */
        global $DB;

        $engine  = WorkflowEngine::fromDb($DB);
        $flagged = 0;
        $rows    = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['status' => InstanceStatus::RUNNING->value],
        ]);
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $instance = new self();
            $instance->getFromResultSet($row);
            if ($engine->detectLostItem($instance)) {
                $flagged++;
            }
        }

        $task->addVolume($flagged);

        return $flagged > 0 ? 1 : 0;
    }
}
