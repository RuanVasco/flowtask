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

import type { ColorMode } from '@xyflow/react';
import { FlowEdge } from './FlowEdge';
import { StepNode } from './StepNode';

/** Follows the GLPI theme of the page (light or dark). */
export function colorMode(): ColorMode {
    return document.documentElement.dataset.glpiThemeDark === '1' ? 'dark' : 'light';
}

/** Props shared by the viewer and the editor. */
export const canvasProps = {
    nodeTypes: { step: StepNode },
    edgeTypes: { flow: FlowEdge },
    fitView: true,
    fitViewOptions: { padding: 0.15, maxZoom: 1.25 },
    minZoom: 0.2,
};
