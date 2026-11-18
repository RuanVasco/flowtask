
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

import '@xyflow/react/dist/base.css';
import './diagram.css';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Diagram } from './Diagram';
import type { DiagramConfig } from './types';

/**
 * Renders the diagram in an element of templates/diagram.html.twig, which
 * holds the configuration in its `data-config` attribute and loads the
 * stylesheet built next to this module.
 */
export function mount(element: HTMLElement): void {
    if (element.dataset.mounted === '1') {
        return;
    }
    element.dataset.mounted = '1';

    const config = JSON.parse(element.dataset.config ?? '{}') as DiagramConfig;
    createRoot(element).render(
        <StrictMode>
            <Diagram config={config} />
        </StrictMode>,
    );
}
