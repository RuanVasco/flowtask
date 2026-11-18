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

namespace GlpiPlugin\Flowtask\View;

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Flowtask\Enum\NodeType;
use GlpiPlugin\Flowtask\Enum\StepStatus;
use GlpiPlugin\Flowtask\Enum\TransitionCondition;

use function Safe\filemtime;

/**
 * Places the diagram component on a page (spec 002). The component loads
 * its graph from a JSON endpoint and gets every label from here, already
 * translated: the bundle has no strings of its own.
 */
final class DiagramView
{
    private const BUNDLE_PATH = '/public/lib/diagram/diagram.js';

    private const BUNDLE_URL = '/plugins/flowtask/lib/diagram/diagram.js';

    /** Heights in pixels: the editor needs room for the palette and to drop steps. */
    private const VIEWER_HEIGHT = 420;

    private const EDITOR_HEIGHT = 600;

    /**
     * @param int|null $editable_version Draft the user edits on the diagram,
     *                                   or null for the viewer
     */
    public static function display(string $graph_url, ?int $editable_version = null): void
    {
        $context = self::context($graph_url, true);
        if ($editable_version !== null) {
            $context['config']['mode'] = 'editor';
            $context['config']['urls'] = self::getEditURLs($editable_version);
            $context['height']         = self::EDITOR_HEIGHT;
        }

        TemplateRenderer::getInstance()->display('@flowtask/diagram.html.twig', $context);
    }

    /**
     * Variables of templates/diagram.html.twig.
     *
     * @param bool $build_hint Tell the user to build the bundle when it is
     *                         missing (administrators), or show nothing
     *
     * @return array{bundle_url: string|null, stylesheet_url: string|null, build_hint: bool, height: int, config: array<string, mixed>}
     */
    public static function context(string $graph_url, bool $build_hint = false): array
    {
        $bundle_url = self::getBundleURL();

        return [
            'bundle_url'     => $bundle_url,
            'stylesheet_url' => $bundle_url !== null ? str_replace('/diagram.js?', '/diagram.css?', $bundle_url) : null,
            'build_hint'     => $build_hint,
            'height'         => self::VIEWER_HEIGHT,
            'config'         => [
                'mode'      => 'viewer',
                'graph_url' => $graph_url,
                'strings'   => self::getStrings(),
                'icons'     => array_combine(
                    array_map(static fn(NodeType $type): string => $type->value, NodeType::cases()),
                    array_map(static fn(NodeType $type): string => $type->icon(), NodeType::cases()),
                ),
            ],
        ];
    }

    public static function getVersionGraphURL(int $versions_id): string
    {
        return self::getRootDoc() . '/plugins/flowtask/WorkflowVersion/' . $versions_id . '/Graph';
    }

    /**
     * Endpoints of DiagramEditController; `{id}` stands for the step or
     * transition to delete.
     *
     * @return array<string, string>
     */
    private static function getEditURLs(int $versions_id): array
    {
        $base = self::getRootDoc() . '/plugins/flowtask/';

        return [
            'add_node'          => $base . 'WorkflowVersion/' . $versions_id . '/Node',
            'positions'         => $base . 'WorkflowVersion/' . $versions_id . '/Positions',
            'add_transition'    => $base . 'WorkflowVersion/' . $versions_id . '/Transition',
            'delete_node'       => $base . 'Node/{id}/Delete',
            'delete_transition' => $base . 'Transition/{id}/Delete',
        ];
    }

    public static function getInstanceGraphURL(int $instances_id): string
    {
        return self::getRootDoc() . '/plugins/flowtask/Instance/' . $instances_id . '/Graph';
    }

    /**
     * URL of the built bundle, or null when it was not built (a checkout
     * without `npm install`). The file date busts the browser cache.
     */
    private static function getBundleURL(): ?string
    {
        $path = dirname(__DIR__, 2) . self::BUNDLE_PATH;
        if (!is_file($path)) {
            return null;
        }

        return self::getRootDoc() . self::BUNDLE_URL . '?v=' . filemtime($path);
    }

    /**
     * @return array<string, mixed>
     */
    private static function getStrings(): array
    {
        $types = [];
        foreach (NodeType::cases() as $type) {
            $types[$type->value] = $type->label();
        }

        $conditions = [];
        foreach (TransitionCondition::cases() as $condition) {
            $conditions[$condition->value] = $condition->label();
        }

        $states = [];
        foreach (StepStatus::cases() as $status) {
            $states[$status->value] = $status->label();
        }

        return [
            'types'      => $types,
            'conditions' => $conditions,
            'states'     => $states,
            'loading'    => __('Loading...'),
            'load_error' => __('The diagram could not be loaded.', 'flowtask'),
            'save_error' => __('The change could not be saved.', 'flowtask'),
            'palette'    => __('Drag a step onto the diagram', 'flowtask'),
            'delete'     => __('Delete'),
            'close'      => __('Close'),
        ];
    }

    private static function getRootDoc(): string
    {
        /** @var array{root_doc: string} $CFG_GLPI */
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'];
    }
}
