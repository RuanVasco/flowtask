
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

/**
 * Calls to the editor endpoints (src/Controller/DiagramEditController.php).
 * GLPI checks POST requests against CSRF from the browser headers of a
 * same-origin request, so no token is sent.
 */

/** A change the server refused, with its messages (already translated). */
export class RefusedError extends Error {
    constructor(public readonly messages: string[]) {
        super(messages.join(' '));
    }
}

export interface Answer {
    warnings?: string[];
}

export async function post<T extends Answer>(url: string, body: unknown = {}): Promise<T> {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(body),
    });
    const data: unknown = await response.json().catch(() => null);

    if (!response.ok) {
        const errors = (data as { errors?: unknown } | null)?.errors;
        throw new RefusedError(Array.isArray(errors) ? errors.filter((e): e is string => typeof e === 'string') : []);
    }

    return data as T;
}

export function withId(url: string, id: string | number): string {
    return url.replace('{id}', encodeURIComponent(String(id)));
}
