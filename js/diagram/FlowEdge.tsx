
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

import { BaseEdge, EdgeLabelRenderer, getSmoothStepPath, type EdgeProps } from '@xyflow/react';

/** Clearance below the steps for an edge that goes back to an earlier step. */
const LOOP_DROP = 64;
const LOOP_OFFSET = 24;

/**
 * Path of an edge whose target sits left of its source (a loop back, e.g.
 * "Rejected" to an earlier task): it leaves to the right, runs below both
 * steps and enters the target from the left, instead of crossing them.
 */
export function loopBackPath(sx: number, sy: number, tx: number, ty: number): [string, number, number] {
    const bottom = Math.max(sy, ty) + LOOP_DROP;
    const right = sx + LOOP_OFFSET;
    const left = tx - LOOP_OFFSET;
    const path = `M ${sx} ${sy} H ${right} V ${bottom} H ${left} V ${ty} H ${tx}`;

    return [path, (right + left) / 2, bottom];
}

export function FlowEdge(props: EdgeProps) {
    const { sourceX, sourceY, targetX, targetY, sourcePosition, targetPosition, label, markerEnd, style, data } = props;
    const condition = typeof data?.condition === 'string' ? data.condition : '';

    const [path, labelX, labelY] =
        targetX < sourceX
            ? loopBackPath(sourceX, sourceY, targetX, targetY)
            : getSmoothStepPath({ sourceX, sourceY, targetX, targetY, sourcePosition, targetPosition, borderRadius: 8 });

    return (
        <>
            <BaseEdge id={props.id} path={path} markerEnd={markerEnd} style={style} />
            {label !== undefined && (
                <EdgeLabelRenderer>
                    <div
                        className={`ft-edge-label ft-edge-label--${condition} nodrag nopan`}
                        style={{ transform: `translate(-50%, -50%) translate(${labelX}px, ${labelY}px)` }}
                    >
                        {label}
                    </div>
                </EdgeLabelRenderer>
            )}
        </>
    );
}
