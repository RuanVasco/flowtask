
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

import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

/**
 * Builds the workflow diagram (spec 002) into public/lib/diagram/, served by GLPI
 * at /plugins/flowtask/lib/diagram/. File names are fixed: the page adds a cache
 * key to the URL.
 */
export default defineConfig(({ mode }) => ({
    plugins: [react()],
    // public/ is the plugin's web root, not a folder of assets to copy.
    publicDir: false,
    define: {
        'process.env.NODE_ENV': JSON.stringify(mode === 'development' ? 'development' : 'production'),
    },
    build: {
        outDir: 'public/lib/diagram',
        emptyOutDir: true,
        sourcemap: mode === 'development',
        lib: {
            entry: 'js/diagram/main.tsx',
            formats: ['es'],
            fileName: () => 'diagram.js',
            cssFileName: 'diagram',
        },
    },
}));
