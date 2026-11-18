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

import { useEffect, useId, useState } from 'react';
import type { Picker as PickerSource, Strings } from './types';

export interface Choice {
    id: number;
    text: string;
}

const SEARCH_DELAY_MS = 250;
const PAGE_LIMIT = 20;

/**
 * Choices of a GLPI dropdown answer: a flat list, or groups (e.g. entities)
 * holding their choices. The empty choice (id 0) is left out.
 */
export function flattenChoices(results: unknown): Choice[] {
    const choices: Choice[] = [];
    const visit = (items: unknown): void => {
        if (!Array.isArray(items)) {
            return;
        }
        for (const item of items as Record<string, unknown>[]) {
            if (Array.isArray(item.children)) {
                visit(item.children);
            } else if (typeof item.text === 'string' && Number(item.id) > 0) {
                choices.push({ id: Number(item.id), text: item.text });
            }
        }
    };
    visit(results);

    return choices;
}

async function search(source: PickerSource, text: string): Promise<Choice[]> {
    const body = new URLSearchParams({ ...source.params, searchText: text, page: '1', page_limit: String(PAGE_LIMIT) });
    const response = await fetch(source.url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body,
    });
    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }
    const data = (await response.json()) as { results?: unknown };

    return flattenChoices(data.results);
}

interface PickerProps {
    label: string;
    source: PickerSource;
    value: Choice | null;
    strings: Strings;
    onChange: (choice: Choice) => void;
}

/**
 * Searches a user, group or task template through GLPI's dropdown endpoint,
 * so entity visibility and rights are the ones of GLPI forms (spec 002,
 * configure a step).
 */
export function Picker({ label, source, value, strings, onChange }: PickerProps) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const [text, setText] = useState('');
    const [choices, setChoices] = useState<Choice[] | null>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        if (!open) {
            return;
        }

        let cancelled = false;
        const timer = window.setTimeout(() => {
            search(source, text)
                .then((found) => {
                    if (!cancelled) {
                        setFailed(false);
                        setChoices(found);
                    }
                })
                .catch(() => {
                    if (!cancelled) {
                        setFailed(true);
                        setChoices([]);
                    }
                });
        }, SEARCH_DELAY_MS);

        return () => {
            cancelled = true;
            window.clearTimeout(timer);
        };
    }, [open, text, source]);

    return (
        <div className="mb-2 ft-picker">
            <label className="form-label" htmlFor={id}>
                {label}
            </label>
            {open ? (
                <>
                    <input
                        id={id}
                        className="form-control form-control-sm"
                        placeholder={strings.search}
                        value={text}
                        autoFocus
                        onChange={(event) => setText(event.target.value)}
                        onKeyDown={(event) => event.key === 'Escape' && setOpen(false)}
                        onBlur={() => window.setTimeout(() => setOpen(false), 200)}
                    />
                    <div className="ft-picker__list list-group">
                        {choices === null && <div className="list-group-item small text-secondary">{strings.loading}</div>}
                        {choices !== null && choices.length === 0 && (
                            <div className={`list-group-item small ${failed ? 'text-danger' : 'text-secondary'}`}>
                                {failed ? strings.load_error : strings.no_results}
                            </div>
                        )}
                        {choices?.map((choice) => (
                            <button
                                key={choice.id}
                                type="button"
                                className="list-group-item list-group-item-action small"
                                onMouseDown={(event) => event.preventDefault()}
                                onClick={() => {
                                    setOpen(false);
                                    onChange(choice);
                                }}
                            >
                                {choice.text}
                            </button>
                        ))}
                    </div>
                </>
            ) : (
                <button
                    id={id}
                    type="button"
                    className="form-select form-select-sm text-start"
                    onClick={() => {
                        setText('');
                        setChoices(null);
                        setOpen(true);
                    }}
                >
                    {value !== null && value.id > 0 ? value.text : strings.none}
                </button>
            )}
        </div>
    );
}
