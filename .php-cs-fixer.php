<?php

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

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$baseline_file = __DIR__ . '/../../PluginsPhpCsFixer.php';
if (!file_exists($baseline_file)) {
    throw new RuntimeException(
        sprintf(
            'Unable to find "%s". Running php-cs-fixer on a plugin requires a GLPI development checkout that ships PluginsPhpCsFixer.php.',
            $baseline_file,
        ),
    );
}

$baseline = require $baseline_file;

$finder = Finder::create()
    ->in(__DIR__)
    ->ignoreVCSIgnored(true)
    ->name('*.php');

/** @var Config $config */
$config = $baseline($finder);

return $config
    ->setCacheFile(__DIR__ . '/var/php-cs-fixer/.php-cs-fixer.cache')
;
