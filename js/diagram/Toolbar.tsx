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

import type { Actions, FormAction, Strings, VersionInfo } from './types';

const BADGES: Record<VersionInfo['status'], string> = {
    draft: 'bg-orange-lt',
    published: 'bg-green-lt',
    archived: 'bg-secondary-lt',
};

/**
 * Posts a version action to its GLPI form, as the version card does; the
 * page reloads on the workflow.
 */
function submit(action: FormAction): void {
    if (action.confirm !== undefined && !window.confirm(action.confirm)) {
        return;
    }

    const form = document.createElement('form');
    form.method = 'post';
    form.action = action.url;
    for (const [name, value] of Object.entries(action.fields)) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = String(value);
        form.appendChild(input);
    }
    document.body.appendChild(form);
    form.submit();
}

interface ToolbarProps {
    version?: VersionInfo;
    actions?: Actions;
    strings: Strings;
    onPublish?: () => void;
    busy?: boolean;
}

/**
 * Version actions of spec 001 that apply, on the diagram (spec 002, where it
 * lives): "Edit" on a published version, "Publish" and "Discard draft" on a
 * draft.
 */
export function Toolbar({ version, actions, strings, onPublish, busy = false }: ToolbarProps) {
    if (version === undefined) {
        return null;
    }

    return (
        <div className="ft-toolbar">
            <span className={`badge ${BADGES[version.status]}`}>{version.status_label}</span>
            <span className="ft-toolbar__label">{version.label}</span>
            <div className="ft-toolbar__actions">
                {actions?.edit !== undefined && (
                    <button type="button" className="btn btn-sm btn-primary" onClick={() => submit(actions.edit as FormAction)}>
                        <i className="ti ti-pencil" aria-hidden="true"></i>
                        <span>{strings.edit}</span>
                    </button>
                )}
                {actions?.publish !== undefined && onPublish !== undefined && (
                    <button type="button" className="btn btn-sm btn-success" onClick={onPublish} disabled={busy}>
                        <i className="ti ti-rocket" aria-hidden="true"></i>
                        <span>{strings.publish}</span>
                    </button>
                )}
                {actions?.discard !== undefined && (
                    <button
                        type="button"
                        className="btn btn-sm btn-outline-danger"
                        onClick={() => submit(actions.discard as FormAction)}
                        disabled={busy}
                    >
                        <i className="ti ti-trash" aria-hidden="true"></i>
                        <span>{strings.discard}</span>
                    </button>
                )}
            </div>
        </div>
    );
}
