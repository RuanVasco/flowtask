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
import { Picker } from './Picker';
import type { EditorConfig, GraphNode, StepConfig } from './types';

/** Fields the panel saves (DiagramEditController::STEP_FIELDS). */
export interface StepFields {
    name: string;
    node_type: string;
    actor_type: string;
    users_id: number;
    groups_id: number;
    manager_level: number;
    missing_manager_behavior: string;
    tasktemplates_id: number;
    solve_ticket: number;
    ticket_status: number;
}

interface FormState extends StepFields {
    users_name: string;
    groups_name: string;
    tasktemplates_name: string;
}

const NO_CONFIG: StepConfig = {
    actor_type: '',
    users_id: 0,
    users_name: '',
    groups_id: 0,
    groups_name: '',
    manager_level: 1,
    missing_manager_behavior: '',
    tasktemplates_id: 0,
    tasktemplates_name: '',
    solve_ticket: false,
    ticket_status: 0,
};

function fromStep(step: GraphNode): FormState {
    const config = step.config ?? NO_CONFIG;

    return {
        name: step.name,
        node_type: step.type,
        actor_type: config.actor_type,
        users_id: config.users_id,
        users_name: config.users_name,
        groups_id: config.groups_id,
        groups_name: config.groups_name,
        manager_level: config.manager_level,
        missing_manager_behavior: config.missing_manager_behavior,
        tasktemplates_id: config.tasktemplates_id,
        tasktemplates_name: config.tasktemplates_name,
        solve_ticket: config.solve_ticket ? 1 : 0,
        ticket_status: config.ticket_status,
    };
}

function toFields(form: FormState): StepFields {
    const { users_name: _u, groups_name: _g, tasktemplates_name: _t, ...fields } = form;

    return fields;
}

/**
 * Fields changed by choosing an actor: the requester puts the ticket on the
 * given status when the step had none, so the common case needs no extra
 * click (spec 003, AC-6).
 */
export function actorChanges(
    actor_type: string,
    ticket_status: number,
    requester_status: number,
): Pick<StepFields, 'actor_type'> & Partial<Pick<StepFields, 'ticket_status'>> {
    return actor_type === 'requester' && ticket_status === 0
        ? { actor_type, ticket_status: requester_status }
        : { actor_type };
}

interface StepPanelProps {
    step: GraphNode;
    config: EditorConfig;
    /** Saves the step; false when the server refused, and the panel shows the saved values again. */
    onSave: (fields: StepFields) => Promise<boolean>;
}

/**
 * Configuration of the selected step (spec 002, AC-4). Only the fields that
 * apply to its type and actor are shown, as in the step form; each change
 * is saved at once (text fields when they lose focus).
 */
export function StepPanel({ step, config, onSave }: StepPanelProps) {
    const { strings, options, pickers } = config;
    const id = useId();
    const [form, setForm] = useState<FormState>(() => fromStep(step));
    /** Text fields typed in and not saved yet: a save answering meanwhile keeps them. */
    const [typing, setTyping] = useState<Partial<FormState>>({});

    useEffect(() => setForm({ ...fromStep(step), ...typing }), [step]);

    const save = (changes: Partial<FormState>): void => {
        const next = { ...form, ...changes };
        setForm(next);
        setTyping({});
        void onSave(toFields(next)).then((saved) => {
            if (!saved) {
                setForm(fromStep(step));
            }
        });
    };

    const saveText = (field: 'name' | 'manager_level'): void => {
        if (String(form[field]) !== String(fromStep(step)[field])) {
            save({});
        } else {
            setTyping({});
        }
    };

    if (step.type === 'start') {
        return <div className="ft-panel small text-secondary">{strings.start_hint}</div>;
    }

    const withActor = form.node_type === 'task' || form.node_type === 'approval';

    return (
        <div className="ft-panel" onKeyDown={(event) => event.stopPropagation()}>
            {step.problems !== undefined && step.problems.length > 0 && (
                <div className="alert alert-warning small p-2 mb-2 d-block">
                    <div className="fw-bold">{strings.problems}</div>
                    {step.problems.map((problem) => (
                        <div key={problem}>{problem}</div>
                    ))}
                </div>
            )}
            <div className="mb-2">
                <label className="form-label" htmlFor={`${id}-name`}>
                    {strings.name}
                </label>
                <input
                    id={`${id}-name`}
                    className="form-control form-control-sm"
                    value={form.name}
                    onChange={(event) => {
                        setForm({ ...form, name: event.target.value });
                        setTyping({ ...typing, name: event.target.value });
                    }}
                    onBlur={() => saveText('name')}
                    onKeyDown={(event) => event.key === 'Enter' && event.currentTarget.blur()}
                />
            </div>
            <div className="mb-2">
                <label className="form-label" htmlFor={`${id}-type`}>
                    {strings.type}
                </label>
                <select
                    id={`${id}-type`}
                    className="form-select form-select-sm"
                    value={form.node_type}
                    onChange={(event) => save({ node_type: event.target.value })}
                >
                    {Object.entries(options.step_types).map(([value, label]) => (
                        <option key={value} value={value}>
                            {label}
                        </option>
                    ))}
                </select>
            </div>
            {withActor && (
                <div className="mb-2">
                    <label className="form-label" htmlFor={`${id}-actor`}>
                        {strings.actor}
                    </label>
                    <select
                        id={`${id}-actor`}
                        className="form-select form-select-sm"
                        value={form.actor_type}
                        onChange={(event) =>
                            save(actorChanges(event.target.value, form.ticket_status, options.requester_ticket_status))
                        }
                    >
                        <option value="">{strings.none}</option>
                        {Object.entries(options.actor_types).map(([value, label]) => (
                            <option key={value} value={value}>
                                {label}
                            </option>
                        ))}
                    </select>
                </div>
            )}
            {withActor && form.actor_type === 'user' && (
                <Picker
                    label={strings.user}
                    source={pickers.user}
                    strings={strings}
                    value={{ id: form.users_id, text: form.users_name }}
                    onChange={(choice) => save({ users_id: choice.id, users_name: choice.text })}
                />
            )}
            {withActor && form.actor_type === 'group' && (
                <Picker
                    label={strings.group}
                    source={pickers.group}
                    strings={strings}
                    value={{ id: form.groups_id, text: form.groups_name }}
                    onChange={(choice) => save({ groups_id: choice.id, groups_name: choice.text })}
                />
            )}
            {withActor && form.actor_type === 'requester_manager' && (
                <>
                    <div className="mb-2">
                        <label className="form-label" htmlFor={`${id}-level`}>
                            {strings.manager_level}
                        </label>
                        <input
                            id={`${id}-level`}
                            type="number"
                            min={1}
                            className="form-control form-control-sm"
                            value={form.manager_level}
                            onChange={(event) => {
                                setForm({ ...form, manager_level: Number(event.target.value) });
                                setTyping({ ...typing, manager_level: Number(event.target.value) });
                            }}
                            onBlur={() => saveText('manager_level')}
                        />
                        <div className="form-text">{strings.manager_help}</div>
                    </div>
                    <div className="mb-2">
                        <label className="form-label" htmlFor={`${id}-behavior`}>
                            {strings.missing_manager}
                        </label>
                        <select
                            id={`${id}-behavior`}
                            className="form-select form-select-sm"
                            value={form.missing_manager_behavior}
                            onChange={(event) => save({ missing_manager_behavior: event.target.value })}
                        >
                            {Object.entries(options.behaviors).map(([value, label]) => (
                                <option key={value} value={value}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    </div>
                </>
            )}
            {form.node_type === 'task' && (
                <Picker
                    label={strings.task_template}
                    source={pickers.tasktemplate}
                    strings={strings}
                    value={{ id: form.tasktemplates_id, text: form.tasktemplates_name }}
                    onChange={(choice) => save({ tasktemplates_id: choice.id, tasktemplates_name: choice.text })}
                />
            )}
            {withActor && (
                <div className="mb-2">
                    <label className="form-label" htmlFor={`${id}-status`}>
                        {strings.ticket_status}
                    </label>
                    <select
                        id={`${id}-status`}
                        className="form-select form-select-sm"
                        value={form.ticket_status}
                        onChange={(event) => save({ ticket_status: Number(event.target.value) })}
                    >
                        {Object.entries(options.ticket_statuses).map(([value, label]) => (
                            <option key={value} value={value}>
                                {label}
                            </option>
                        ))}
                    </select>
                </div>
            )}
            {form.node_type === 'end' && (
                <div className="form-check mb-2">
                    <input
                        id={`${id}-solve`}
                        type="checkbox"
                        className="form-check-input"
                        checked={form.solve_ticket > 0}
                        onChange={(event) => save({ solve_ticket: event.target.checked ? 1 : 0 })}
                    />
                    <label className="form-check-label" htmlFor={`${id}-solve`}>
                        {strings.solve_ticket}
                    </label>
                </div>
            )}
        </div>
    );
}
