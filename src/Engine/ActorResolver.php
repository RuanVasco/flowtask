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

use CommonITILActor;
use DBmysql;
use GlpiPlugin\Flowtask\Enum\ActorType;
use GlpiPlugin\Flowtask\Node;
use GlpiPlugin\Flowtask\Support\Fields;
use Group;
use Ticket;
use Ticket_User;
use User;

/**
 * Turns the actor of a step into the user or group the ticket item is
 * assigned to (spec 001, engine rules, AC-7).
 */
final readonly class ActorResolver
{
    public function __construct(private DBmysql $db) {}

    public function resolve(Node $node, Ticket $ticket): ActorResolution
    {
        return match (ActorType::tryFrom(Fields::string($node->fields, 'actor_type'))) {
            ActorType::USER              => $this->resolveUser(Fields::int($node->fields, 'users_id')),
            ActorType::GROUP             => $this->resolveGroup(Fields::int($node->fields, 'groups_id')),
            ActorType::REQUESTER         => $this->resolveRequester($ticket),
            ActorType::REQUESTER_MANAGER => $this->resolveManager($ticket, max(1, Fields::int($node->fields, 'manager_level'))),
            null                         => ActorResolution::failed(__('The step has no actor.', 'flowtask')),
        };
    }

    private function resolveUser(int $users_id): ActorResolution
    {
        return $this->loadActiveUser($users_id) instanceof User
            ? ActorResolution::user($users_id)
            : ActorResolution::failed(__('The user of the step is missing, deleted or inactive.', 'flowtask'));
    }

    private function resolveGroup(int $groups_id): ActorResolution
    {
        return $groups_id > 0 && (new Group())->getFromDB($groups_id)
            ? ActorResolution::group($groups_id)
            : ActorResolution::failed(__('The group of the step is missing.', 'flowtask'));
    }

    /**
     * The first requester user of the ticket (spec 003). The missing manager
     * behavior does not apply: without a requester user the step blocks.
     */
    private function resolveRequester(Ticket $ticket): ActorResolution
    {
        $users_id = $this->getFirstRequester($ticket->getID());
        if ($users_id === 0) {
            return ActorResolution::failed(__('The ticket has no requester user.', 'flowtask'));
        }

        return $this->loadActiveUser($users_id) instanceof User
            ? ActorResolution::user($users_id)
            : ActorResolution::failed(__('The requester of the ticket is deleted or inactive.', 'flowtask'));
    }

    private function resolveManager(Ticket $ticket, int $level): ActorResolution
    {
        $users_id = $this->getFirstRequester($ticket->getID());
        if ($users_id === 0) {
            return ActorResolution::managerMissing(__('The ticket has no requester user.', 'flowtask'));
        }

        $user = new User();
        if (!$user->getFromDB($users_id)) {
            return ActorResolution::managerMissing(__('The ticket has no requester user.', 'flowtask'));
        }

        for ($i = 1; $i <= $level; $i++) {
            $user = $this->loadActiveUser(Fields::int($user->fields, 'users_id_supervisor'));
            if (!$user instanceof User) {
                return ActorResolution::managerMissing(sprintf(
                    __('The requester has no active manager at level %d.', 'flowtask'),
                    $i,
                ));
            }
        }

        return ActorResolution::user($user->getID());
    }

    private function getFirstRequester(int $tickets_id): int
    {
        $rows = $this->db->request([
            'SELECT' => 'users_id',
            'FROM'   => Ticket_User::getTable(),
            'WHERE'  => [
                'tickets_id' => $tickets_id,
                'type'       => CommonITILActor::REQUESTER,
                'users_id'   => ['>', 0],
            ],
            'ORDER'  => 'id',
            'LIMIT'  => 1,
        ]);
        foreach ($rows as $row) {
            return Fields::int($row, 'users_id');
        }

        return 0;
    }

    private function loadActiveUser(int $users_id): ?User
    {
        $user = new User();

        return $users_id > 0
            && $user->getFromDB($users_id)
            && Fields::int($user->fields, 'is_active') === 1
            && Fields::int($user->fields, 'is_deleted') === 0
            ? $user
            : null;
    }
}
