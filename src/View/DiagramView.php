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

use Dropdown;
use Entity;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Flowtask\Enum\ActorType;
use GlpiPlugin\Flowtask\Enum\MissingManagerBehavior;
use GlpiPlugin\Flowtask\Enum\NodeType;
use GlpiPlugin\Flowtask\Enum\StepStatus;
use GlpiPlugin\Flowtask\Enum\TransitionCondition;
use GlpiPlugin\Flowtask\Support\Fields;
use GlpiPlugin\Flowtask\Workflow;
use GlpiPlugin\Flowtask\WorkflowVersion;
use Group;
use Session;
use TaskTemplate;
use User;

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
     * Viewer of a version, e.g. on the version page.
     */
    public static function display(string $graph_url): void
    {
        TemplateRenderer::getInstance()->display('@flowtask/diagram.html.twig', self::context($graph_url, true));
    }

    /**
     * Diagram tab of a workflow: the editor on its draft, or the viewer, with
     * the version actions the user may take (spec 002, where it lives).
     */
    public static function displayForWorkflow(Workflow $workflow, WorkflowVersion $version): void
    {
        $context = self::context(self::getVersionGraphURL($version->getID()), true);
        $config  = &$context['config'];
        $config['version'] = [
            'label'  => sprintf(__('Version %d', 'flowtask'), Fields::int($version->fields, 'version_number')),
            'status' => $version->getStatus()->value,
            'status_label' => $version->getStatus()->label(),
        ];

        $canedit = $workflow->can($workflow->getID(), UPDATE);
        $actions = [];
        if ($canedit && $version->isDraft()) {
            $config['mode']     = 'editor';
            $config['urls']     = self::getEditURLs($version->getID());
            $config['pickers']  = self::getPickers($workflow);
            $config['options']  = self::getOptions();
            $context['height']  = self::EDITOR_HEIGHT;
            $actions['publish'] = ['url' => WorkflowVersion::getPublishURL($version->getID())];
            $actions['discard'] = [
                'url'     => WorkflowVersion::getFormURL(),
                'fields'  => ['id' => $version->getID(), 'purge' => 1],
                'confirm' => __('Discard this draft and its changes?', 'flowtask'),
            ];
        } elseif ($canedit) {
            $actions['edit'] = [
                'url'    => WorkflowVersion::getFormURL(),
                'fields' => [WorkflowVersion::$items_id => $workflow->getID(), 'add' => 1],
            ];
        }

        $config['actions'] = $actions;
        unset($config);

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
            'update_node'       => $base . 'Node/{id}',
            'positions'         => $base . 'WorkflowVersion/' . $versions_id . '/Positions',
            'add_transition'    => $base . 'WorkflowVersion/' . $versions_id . '/Transition',
            'delete_node'       => $base . 'Node/{id}/Delete',
            'delete_transition' => $base . 'Transition/{id}/Delete',
        ];
    }

    /**
     * GLPI's dropdown endpoints, with the IDOR tokens they check, so the
     * pickers see what GLPI forms see: same rights, entities of the workflow.
     *
     * @return array<string, array{url: string, params: array<string, string>}>
     */
    private static function getPickers(Workflow $workflow): array
    {
        $entity   = Fields::int($workflow->fields, 'entities_id');
        $entities = Fields::int($workflow->fields, 'is_recursive') > 0
            ? array_values(array_map(intval(...), getSonsOf(Entity::getTable(), $entity)))
            : [$entity];
        $restrict = json_encode($entities, JSON_THROW_ON_ERROR);
        $values   = self::getRootDoc() . '/ajax/getDropdownValue.php';

        return [
            'user' => [
                'url'    => self::getRootDoc() . '/ajax/getDropdownUsers.php',
                'params' => [
                    'right'           => 'all',
                    'entity_restrict' => $restrict,
                    '_idor_token'     => Session::getNewIDORToken(User::class, ['right' => 'all']),
                ],
            ],
            'group' => [
                'url'    => $values,
                'params' => [
                    'itemtype'        => Group::class,
                    'entity_restrict' => $restrict,
                    '_idor_token'     => Session::getNewIDORToken(Group::class),
                ],
            ],
            'tasktemplate' => [
                'url'    => $values,
                'params' => [
                    'itemtype'        => TaskTemplate::class,
                    'entity_restrict' => $restrict,
                    '_idor_token'     => Session::getNewIDORToken(TaskTemplate::class),
                ],
            ],
        ];
    }

    /**
     * Choices of the side panel, labelled.
     *
     * @return array<string, array<string, string>>
     */
    private static function getOptions(): array
    {
        $options = ['step_types' => [], 'actor_types' => [], 'behaviors' => []];
        foreach ([NodeType::TASK, NodeType::APPROVAL, NodeType::END] as $type) {
            $options['step_types'][$type->value] = $type->label();
        }

        foreach (ActorType::cases() as $actor) {
            $options['actor_types'][$actor->value] = $actor->label();
        }

        foreach (MissingManagerBehavior::cases() as $behavior) {
            $options['behaviors'][$behavior->value] = $behavior->label();
        }

        return $options;
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
            // Toolbar (spec 002, where it lives).
            'edit'           => __('Edit', 'flowtask'),
            'publish'        => __('Publish', 'flowtask'),
            'discard'        => __('Discard draft', 'flowtask'),
            'publish_failed' => __('The version cannot be published:', 'flowtask'),
            // Side panel (spec 002, configure a step).
            'panel_hint'     => __('Select a step to configure it.', 'flowtask'),
            'start_hint'     => __('The start step has nothing to configure.', 'flowtask'),
            'name'           => __('Name'),
            'type'           => _n('Type', 'Types', 1),
            'actor'          => __('Actor', 'flowtask'),
            'user'           => User::getTypeName(1),
            'group'          => Group::getTypeName(1),
            'manager_level'  => __('Manager level', 'flowtask'),
            'manager_help'   => __('1 is the requester supervisor, 2 the supervisor of that supervisor, and so on.', 'flowtask'),
            'missing_manager' => __('When the manager is missing', 'flowtask'),
            'task_template'  => TaskTemplate::getTypeName(1),
            'solve_ticket'   => __('Solve the ticket', 'flowtask'),
            'none'           => Dropdown::EMPTY_VALUE,
            'search'         => __('Search'),
            'no_results'     => __('No results found'),
            'problems'       => __('This step cannot be published yet:', 'flowtask'),
        ];
    }

    private static function getRootDoc(): string
    {
        /** @var array{root_doc: string} $CFG_GLPI */
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'];
    }
}
