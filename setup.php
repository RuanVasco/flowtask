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

use Glpi\Plugin\Hooks;
use GlpiPlugin\Flowtask\Engine\DeletionGuard;
use GlpiPlugin\Flowtask\Engine\TicketEvents;
use GlpiPlugin\Flowtask\Instance;
use GlpiPlugin\Flowtask\ProfileRights;
use GlpiPlugin\Flowtask\Workflow;
use GlpiPlugin\Flowtask\Workflow_ITILCategory;

/** @phpstan-ignore theCodingMachineSafe.function (safe to assume this isn't already defined) */
define('PLUGIN_FLOWTASK_VERSION', '0.1.0');

// Minimal GLPI version, inclusive
/** @phpstan-ignore theCodingMachineSafe.function (safe to assume this isn't already defined) */
define("PLUGIN_FLOWTASK_MIN_GLPI_VERSION", "12.0.0");

// Maximum GLPI version, exclusive
/** @phpstan-ignore theCodingMachineSafe.function (safe to assume this isn't already defined) */
define("PLUGIN_FLOWTASK_MAX_GLPI_VERSION", "12.0.99");

/**
 * Init hooks of the plugin.
 * REQUIRED
 */
function plugin_init_flowtask(): void
{
    if (!Plugin::isPluginActive('flowtask')) {
        return;
    }

    /** @var array<string, array<string, mixed>> $PLUGIN_HOOKS */
    global $PLUGIN_HOOKS;

    Plugin::registerClass(ProfileRights::class, ['addtabon' => [Profile::class]]);
    Plugin::registerClass(Workflow_ITILCategory::class, ['addtabon' => [ITILCategory::class]]);
    Plugin::registerClass(Instance::class, ['addtabon' => [Ticket::class]]);

    // getMenuContent() hides the entry from profiles without the right.
    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['flowtask'] = ['config' => Workflow::class];

    $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['flowtask'] = [
        Ticket::class       => TicketEvents::onTicketPurge(...),
        ITILCategory::class => Workflow_ITILCategory::purgeForCategory(...),
    ];

    // Workflow engine (spec 001, engine rules).
    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['flowtask'] = [
        Ticket::class       => TicketEvents::onTicketAdd(...),
        ITILFollowup::class => TicketEvents::onFollowupAdd(...),
    ];
    $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['flowtask'] = [
        Ticket::class           => TicketEvents::onTicketUpdate(...),
        TicketTask::class       => TicketEvents::onTaskUpdate(...),
        TicketValidation::class => TicketEvents::onValidationUpdate(...),
    ];
    $PLUGIN_HOOKS[Hooks::POST_PREPAREUPDATE]['flowtask'] = [
        TicketTask::class => TicketEvents::onTaskPrepareUpdate(...),
    ];
    $PLUGIN_HOOKS[Hooks::PRE_ITEM_PURGE]['flowtask'] = [
        Ticket::class           => DeletionGuard::beforeTicketPurge(...),
        TicketTask::class       => DeletionGuard::beforeItemPurge(...),
        TicketValidation::class => DeletionGuard::beforeItemPurge(...),
    ];
}

/**
 * Get the name and the version of the plugin
 * REQUIRED
 *
 * @return array{
 *      name: string,
 *      version: string,
 *      author: string,
 *      license: string,
 *      homepage: string,
 *      requirements: array{
 *          glpi: array{
 *              min: string,
 *              max: string,
 *          }
 *      }
 * }
 */
function plugin_version_flowtask(): array
{
    return [
        'name'           => 'Flowtask',
        'version'        => PLUGIN_FLOWTASK_VERSION,
        'author'         => 'Ruan Vasconcelos',
        'license'        => 'GPLv3+',
        'homepage'       => 'https://github.com/RuanVasco/flowtask',
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_FLOWTASK_MIN_GLPI_VERSION,
                'max' => PLUGIN_FLOWTASK_MAX_GLPI_VERSION,
            ],
        ],
    ];
}

/**
 * Check pre-requisites before install
 * OPTIONAL
 */
function plugin_flowtask_check_prerequisites(): bool
{
    return true;
}

/**
 * Check configuration process
 * OPTIONAL
 *
 * @param bool $verbose Whether to display message on failure. Defaults to false.
 */
function plugin_flowtask_check_config(bool $verbose = false): bool
{
    return true;
}
