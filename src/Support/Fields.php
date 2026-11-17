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

namespace GlpiPlugin\Flowtask\Support;

/**
 * Typed reads from database rows and form inputs, which are untyped.
 */
final class Fields
{
    public static function int(mixed $row, string $key): int
    {
        $value = is_array($row) ? ($row[$key] ?? 0) : 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    public static function string(mixed $row, string $key): string
    {
        $value = is_array($row) ? ($row[$key] ?? '') : '';

        return is_scalar($value) ? (string) $value : '';
    }
}
