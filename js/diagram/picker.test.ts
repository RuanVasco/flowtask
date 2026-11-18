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

import { describe, expect, it } from 'vitest';
import { flattenChoices } from './Picker';

describe('choices of a GLPI dropdown answer', () => {
    it('reads flat lists and lists grouped by entity, without the empty choice', () => {
        expect(
            flattenChoices([
                { id: 0, text: '-----' },
                { id: 4, text: 'Tech' },
                { text: 'Root entity', children: [{ id: 7, text: 'Support' }] },
            ]),
        ).toEqual([
            { id: 4, text: 'Tech' },
            { id: 7, text: 'Support' },
        ]);
    });

    it('reads a missing answer as no choice', () => {
        expect(flattenChoices(undefined)).toEqual([]);
    });
});
