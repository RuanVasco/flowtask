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

use GlpiPlugin\Flowtask\Enum\ActorType;
use GlpiPlugin\Flowtask\Enum\MissingManagerBehavior;
use GlpiPlugin\Flowtask\Enum\NodeType;
use GlpiPlugin\Flowtask\Support\Fields;
use Ticket;

/**
 * Rules on the configuration fields of a step (spec 001, definition
 * management rules): which fields apply to each type and actor, and which
 * ones are required.
 */
final class StepConfiguration
{
    /** Ticket statuses a step can set (spec 003); 0 leaves the status alone. */
    public const TICKET_STATUSES = [Ticket::ASSIGNED, Ticket::PLANNED, Ticket::WAITING];

    /**
     * Configuration fields of the step, with the ones that do not apply to
     * its type or actor reset to their defaults.
     *
     * @param array<mixed> $fields Step fields (stored values merged with the input)
     *
     * @return array<string, mixed>
     */
    public static function normalize(array $fields): array
    {
        $type  = NodeType::tryFrom(Fields::string($fields, 'node_type'));
        $actor = self::needsActor($type) ? ActorType::tryFrom(Fields::string($fields, 'actor_type')) : null;

        $normalized = [
            'actor_type'               => $actor?->value,
            'users_id'                 => 0,
            'groups_id'                => 0,
            'manager_level'            => 1,
            'missing_manager_behavior' => MissingManagerBehavior::BLOCK->value,
            'tasktemplates_id'         => $type === NodeType::TASK ? Fields::int($fields, 'tasktemplates_id') : 0,
            'solve_ticket'             => $type === NodeType::END && Fields::int($fields, 'solve_ticket') > 0 ? 1 : 0,
            'ticket_status'            => self::needsActor($type) ? self::ticketStatus($fields) : 0,
        ];

        $actor_field = $actor?->field();
        if ($actor_field !== null) {
            $normalized[$actor_field] = Fields::int($fields, $actor_field);
        }

        if ($actor === ActorType::REQUESTER_MANAGER) {
            $behavior = MissingManagerBehavior::tryFrom(Fields::string($fields, 'missing_manager_behavior'))
                ?? MissingManagerBehavior::BLOCK;

            $normalized['manager_level']            = max(1, Fields::int($fields, 'manager_level'));
            $normalized['missing_manager_behavior'] = $behavior->value;
        }

        return $normalized;
    }

    /**
     * @param array<mixed> $fields Step fields (stored values merged with the input)
     *
     * @return list<string> Translated problems, empty when the step is complete
     */
    public static function getErrors(array $fields): array
    {
        $type_error = self::getTypeError($fields);
        if ($type_error !== null) {
            return [$type_error];
        }

        $type = NodeType::from(Fields::string($fields, 'node_type'));

        $errors = [];

        if (self::needsActor($type)) {
            $actor       = ActorType::tryFrom(Fields::string($fields, 'actor_type'));
            $actor_field = $actor?->field();
            if ($actor === null || ($actor_field !== null && Fields::int($fields, $actor_field) <= 0)) {
                $errors[] = __('The step needs an actor.', 'flowtask');
            }
        }

        if ($type === NodeType::TASK && Fields::int($fields, 'tasktemplates_id') <= 0) {
            $errors[] = __('A task step needs a task template.', 'flowtask');
        }

        return $errors;
    }

    /**
     * The only problem that keeps a step from being saved (spec 002: the
     * others are checked when publishing).
     *
     * @param array<mixed> $fields
     */
    public static function getTypeError(array $fields): ?string
    {
        return NodeType::tryFrom(Fields::string($fields, 'node_type')) === null
            ? __('The step type is invalid.', 'flowtask')
            : null;
    }

    /**
     * @param array<mixed> $fields
     */
    private static function ticketStatus(array $fields): int
    {
        $status = Fields::int($fields, 'ticket_status');

        return in_array($status, self::TICKET_STATUSES, true) ? $status : 0;
    }

    private static function needsActor(?NodeType $type): bool
    {
        return $type === NodeType::TASK || $type === NodeType::APPROVAL;
    }
}
