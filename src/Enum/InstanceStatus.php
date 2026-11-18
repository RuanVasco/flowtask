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

namespace GlpiPlugin\Flowtask\Enum;

enum InstanceStatus: string
{
    case RUNNING = 'running';
    case COMPLETED = 'completed';
    case STUCK = 'stuck';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::RUNNING   => _x('workflow status', 'Running', 'flowtask'),
            self::COMPLETED => _x('workflow status', 'Completed', 'flowtask'),
            self::STUCK     => _x('workflow status', 'Stuck', 'flowtask'),
            self::CANCELLED => _x('workflow status', 'Cancelled', 'flowtask'),
        };
    }

    /**
     * Statuses of an instance that has not ended yet.
     *
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::RUNNING, self::STUCK];
    }
}
