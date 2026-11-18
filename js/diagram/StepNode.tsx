
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

import { Handle, Position, type NodeProps } from '@xyflow/react';
import type { StepFlowNode } from './mapping';

/**
 * A step drawn loosely after BPMN: Start and End as circles, Task as a
 * rounded box, Approval as a box tagged with a diamond and one output per
 * answer (spec 002, look).
 */
export function StepNode({ data }: NodeProps<StepFlowNode>) {
    const { step, strings, markers = false, failed = false } = data;
    const classes = ['ft-node', `ft-node--${step.type}`];
    if (step.state !== null) {
        classes.push(`ft-node--${step.state}`);
    }
    if (failed) {
        classes.push('ft-node--failed');
    }
    const state = step.state !== null ? <span className="ft-node__state">{strings.states[step.state]}</span> : null;
    // Missing configuration is visible while building (spec 002, warning markers).
    const marker =
        markers && step.problems !== undefined && step.problems.length > 0 ? (
            <span className="ft-node__marker" title={[strings.problems, ...step.problems].join('\n')}>
                <i className="ti ti-alert-triangle" aria-hidden="true"></i>
            </span>
        ) : null;

    if (step.type === 'start' || step.type === 'end') {
        return (
            <div className={[...classes, 'ft-node--circle'].join(' ')} title={strings.types[step.type]}>
                {step.type === 'end' && <Handle type="target" position={Position.Left} />}
                <div className="ft-node__circle">
                    <i className={step.icon} aria-hidden="true"></i>
                </div>
                <div className="ft-node__label">{step.name}</div>
                {state}
                {marker}
                {step.type === 'start' && <Handle type="source" position={Position.Right} />}
            </div>
        );
    }

    return (
        <div className={[...classes, 'ft-node--box'].join(' ')}>
            <Handle type="target" position={Position.Left} />
            <div className="ft-node__type">
                {step.type === 'approval' && <span className="ft-node__diamond" aria-hidden="true"></span>}
                <i className={step.icon} aria-hidden="true"></i>
                <span>{strings.types[step.type]}</span>
                {state}
                {marker}
            </div>
            <div className="ft-node__name">{step.name}</div>
            {step.actor !== '' && <div className="ft-node__actor">{step.actor}</div>}
            {step.type === 'approval' ? (
                <>
                    <Handle type="source" id="approved" position={Position.Right} className="ft-handle--approved" style={{ top: '30%' }} title={strings.conditions.approved} />
                    <Handle type="source" id="rejected" position={Position.Right} className="ft-handle--rejected" style={{ top: '70%' }} title={strings.conditions.rejected} />
                </>
            ) : (
                <Handle type="source" position={Position.Right} />
            )}
        </div>
    );
}
