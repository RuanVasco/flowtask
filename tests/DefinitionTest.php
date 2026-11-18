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

namespace GlpiPlugin\Flowtask\Tests;

use DBmysql;
use Glpi\Tests\DbTestCase;
use GlpiPlugin\Flowtask\Enum\ActorType;
use GlpiPlugin\Flowtask\Enum\NodeType;
use GlpiPlugin\Flowtask\Enum\TransitionCondition;
use GlpiPlugin\Flowtask\Enum\VersionStatus;
use GlpiPlugin\Flowtask\Instance;
use GlpiPlugin\Flowtask\Node;
use GlpiPlugin\Flowtask\Transition;
use GlpiPlugin\Flowtask\Workflow;
use GlpiPlugin\Flowtask\Workflow_ITILCategory;
use GlpiPlugin\Flowtask\WorkflowVersion;
use ITILCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use Session;
use TaskTemplate;
use Ticket;
use User;

/**
 * Definition management, spec 001 stage 2 (AC-1, AC-2, AC-3, AC-15).
 */
final class DefinitionTest extends DbTestCase
{
    use WorkflowFixtures;

    public function testNewDraftStartsWithALoneStartStep(): void
    {
        $this->login();

        $draft = $this->createDraft();

        $this->assertSame(VersionStatus::DRAFT->value, $draft->fields['status']);
        $this->assertSame(1, (int) $draft->fields['version_number']);
        $this->assertSame(1, countElementsInTable(Node::getTable(), ['plugin_flowtask_workflowversions_id' => $draft->getID()]));
        $this->getStartNode($draft);
    }

    public function testNewWorkflowStartsWithADraft(): void
    {
        $this->login();

        $workflow = $this->createWorkflow();

        $draft = $workflow->getDraft();
        $this->assertNotNull($draft);
        $this->assertSame($draft->getID(), $workflow->getCurrentVersion()?->getID());
        $this->assertNull($workflow->getPublishedVersion());
    }

    public function testCurrentVersionIsThePublishedOneWhenThereIsNoDraft(): void
    {
        $this->login();

        $workflow = $this->createWorkflow();
        $version  = $this->createPublishableDraft($workflow);
        $this->assertSame([], $this->publish($version));

        $this->assertNull($workflow->getDraft());
        $this->assertSame($version->getID(), $workflow->getCurrentVersion()?->getID());
        $this->assertSame($version->getID(), WorkflowVersion::forTab($workflow)?->getID());
    }

    public function testWorkflowCreatedFromACategoryIsLinkedToIt(): void
    {
        $this->login();

        $category = $this->createItem(ITILCategory::class, [
            'name'        => __FUNCTION__,
            'entities_id' => $this->getTestRootEntity(true),
        ]);
        $workflow = $this->createItem(Workflow::class, [
            'name'               => __FUNCTION__,
            'entities_id'        => $this->getTestRootEntity(true),
            '_itilcategories_id' => $category->getID(),
        ], ['_itilcategories_id']);

        $this->assertSame(1, countElementsInTable(Workflow_ITILCategory::getTable(), [
            'plugin_flowtask_workflows_id' => $workflow->getID(),
            'itilcategories_id'            => $category->getID(),
        ]));
    }

    public function testCategoryTabOffersToCreateOrShowsTheWorkflow(): void
    {
        $this->login();

        $category = $this->createItem(ITILCategory::class, ['name' => __FUNCTION__]);
        $tab      = new Workflow_ITILCategory();
        $this->assertNotSame('', $tab->getTabNameForItem($category));

        ob_start();
        Workflow_ITILCategory::displayTabContentForItem($category);
        $this->assertStringContainsString('Create a workflow for this category', (string) ob_get_clean());

        $workflow = $this->createWorkflow();
        $this->createItem(Workflow_ITILCategory::class, [
            'plugin_flowtask_workflows_id' => $workflow->getID(),
            'itilcategories_id'            => $category->getID(),
        ]);

        ob_start();
        Workflow_ITILCategory::displayTabContentForItem($category);
        $output = (string) ob_get_clean();
        $this->assertStringContainsString(htmlescape($workflow->fields['name']), $output);
        $this->assertStringContainsString('Open workflow', $output);
    }

    public function testVersionActionsGoBackToTheWorkflow(): void
    {
        $this->assertSame('back', WorkflowVersion::getPostFormAction('add', true));
        $this->assertSame('back', WorkflowVersion::getPostFormAction('purge', true));
    }

    public function testWorkflowHasAtMostOneDraft(): void
    {
        $this->login();

        $workflow = $this->createWorkflow();
        $this->createDraft($workflow);

        $this->assertFalse((new WorkflowVersion())->add(['plugin_flowtask_workflows_id' => $workflow->getID()]));
        $this->assertErrorMessages(['This workflow already has a draft version.']);
    }

    public function testPublishingAValidDraft(): void
    {
        $this->login();

        $draft = $this->createPublishableDraft();

        $this->assertSame([], $this->publish($draft));

        $version = $this->reloadItem($draft);
        $this->assertSame(VersionStatus::PUBLISHED->value, $version->fields['status']);
        $this->assertSame(Session::getLoginUserID(), (int) $version->fields['users_id_publisher']);
        $this->assertNotEmpty($version->fields['date_publication']);
    }

    public function testPublishingArchivesThePreviousVersion(): void
    {
        $this->login();

        $workflow = $this->createWorkflow();
        $first    = $this->createPublishableDraft($workflow);
        $this->assertSame([], $this->publish($first));
        $second = $this->createDraft($workflow);
        $this->assertSame([], $this->publish($second));

        $this->assertSame(VersionStatus::ARCHIVED->value, $this->reloadItem($first)->fields['status']);
        $this->assertSame(VersionStatus::PUBLISHED->value, $this->reloadItem($second)->fields['status']);
        $this->assertSame(2, (int) $second->fields['version_number']);
    }

    public function testPublishingListsEveryProblemAndChangesNothing(): void
    {
        $this->login();

        $draft = $this->createDraft();
        $task  = $this->createNode($draft, NodeType::TASK, ['name' => 'Review']);
        $this->createNode($draft, NodeType::APPROVAL, ['name' => 'Sign off']);
        $this->createTransition($this->getStartNode($draft), $task, TransitionCondition::ALWAYS);

        $errors = $this->publish($draft);

        $this->assertContains('The version needs at least one end step.', $errors);
        $this->assertContains('Step "Review" has no "Completed" transition.', $errors);
        $this->assertContains('Step "Sign off" has no "Approved" transition.', $errors);
        $this->assertContains('Step "Sign off" has no "Rejected" transition.', $errors);
        $this->assertContains('Step "Sign off" cannot be reached from the start step.', $errors);
        $this->assertSame(VersionStatus::DRAFT->value, $this->reloadItem($draft)->fields['status']);
    }

    public function testPublishingRechecksStepConfiguration(): void
    {
        $this->login();

        $draft = $this->createPublishableDraft();
        $task  = new Node();
        $this->assertTrue($task->getFromDBByCrit([
            'plugin_flowtask_workflowversions_id' => $draft->getID(),
            'node_type'                           => NodeType::TASK->value,
        ]));
        // The actor disappears after the step was saved, e.g. the user was purged.
        /** @var DBmysql $DB */
        global $DB;
        $DB->update(Node::getTable(), ['users_id' => 0], ['id' => $task->getID()]);

        $this->assertSame(
            [sprintf('Step "%s": The step needs an actor.', $task->fields['name'])],
            $this->publish($draft),
        );
    }

    public function testPublishedVersionIsReadOnly(): void
    {
        $this->login();

        $version = $this->createPublishableDraft();
        $this->assertSame([], $this->publish($version));
        $version = $this->reloadItem($version);
        $start   = $this->getStartNode($version);

        $this->assertFalse($version->canUpdateItem());
        $this->assertFalse($version->canPurgeItem());
        $this->assertFalse($version->update(['id' => $version->getID(), 'comment' => 'changed']));
        $this->assertFalse((new Node())->add([
            'plugin_flowtask_workflowversions_id' => $version->getID(),
            'name'                                => 'Late step',
            'node_type'                           => NodeType::END->value,
        ]));
        $this->assertErrorMessages([
            'A published version cannot be modified. Create a draft instead.',
            'Steps and transitions can only be changed in a draft version.',
        ]);
        $this->assertFalse($start->canUpdateItem());
    }

    public function testDraftUpdateCannotChangeLifecycleFields(): void
    {
        $this->login();

        $draft = $this->createDraft();

        $this->assertTrue($draft->update([
            'id'             => $draft->getID(),
            'comment'        => 'notes',
            'status'         => VersionStatus::PUBLISHED->value,
            'version_number' => 42,
        ]));

        $draft = $this->reloadItem($draft);
        $this->assertSame('notes', $draft->fields['comment']);
        $this->assertSame(VersionStatus::DRAFT->value, $draft->fields['status']);
        $this->assertSame(1, (int) $draft->fields['version_number']);
    }

    public function testNewDraftCopiesTheLatestPublishedGraph(): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $this->login();

        $workflow  = $this->createWorkflow();
        $published = $this->createPublishableDraft($workflow);
        $this->assertSame([], $this->publish($published));

        $draft = $this->createDraft($workflow);

        $this->assertSame(3, countElementsInTable(Node::getTable(), ['plugin_flowtask_workflowversions_id' => $draft->getID()]));
        $this->assertSame([], $this->publish($draft), 'The copy is a valid graph on its own');

        $draft_nodes = array_column(
            iterator_to_array($DB->request(['SELECT' => 'id', 'FROM' => Node::getTable(), 'WHERE' => ['plugin_flowtask_workflowversions_id' => $draft->getID()]])),
            'id',
        );
        foreach ($DB->request(['FROM' => Transition::getTable(), 'WHERE' => ['plugin_flowtask_workflowversions_id' => $draft->getID()]]) as $row) {
            $this->assertContains($row['plugin_flowtask_nodes_id_source'], $draft_nodes);
            $this->assertContains($row['plugin_flowtask_nodes_id_target'], $draft_nodes);
        }
    }

    public function testStartStepCannotBeAddedEditedOrRemoved(): void
    {
        $this->login();

        $draft = $this->createDraft();
        $start = $this->getStartNode($draft);

        $this->assertFalse((new Node())->add([
            'plugin_flowtask_workflowversions_id' => $draft->getID(),
            'name'                                => 'Second start',
            'node_type'                           => NodeType::START->value,
        ]));
        $this->assertFalse($start->update(['id' => $start->getID(), 'name' => 'Renamed']));
        $this->assertTrue($start->update(['id' => $start->getID(), 'position_x' => 40, 'position_y' => 80]), 'Moved on the diagram');
        $this->assertFalse($start->canUpdateItem());
        $this->assertFalse($start->canPurgeItem());
        $this->assertErrorMessages([
            'The start step is created with the draft and cannot be added.',
            'The start step cannot be edited.',
        ]);
    }

    public function testIncompleteStepIsSavedAndConfigurationNormalized(): void
    {
        $this->login();

        $draft = $this->createDraft();

        // Spec 002: completeness is checked on publish, the diagram adds steps first.
        $this->assertNotFalse((new Node())->add([
            'plugin_flowtask_workflowversions_id' => $draft->getID(),
            'name'                                => 'Incomplete',
            'node_type'                           => NodeType::TASK->value,
        ]));
        $this->assertFalse((new Node())->add([
            'plugin_flowtask_workflowversions_id' => $draft->getID(),
            'name'                                => 'Unknown',
            'node_type'                           => 'unknown',
        ]));
        $this->assertErrorMessages(['The step type is invalid.']);

        $approval = $this->createNode($draft, NodeType::APPROVAL, [
            'actor_type'       => ActorType::REQUESTER_MANAGER->value,
            'manager_level'    => 2,
            'users_id'         => 0,
        ]);
        $this->assertTrue($approval->update([
            'id'               => $approval->getID(),
            'users_id'         => Session::getLoginUserID(),
            'tasktemplates_id' => 5,
            'solve_ticket'     => 1,
        ]));

        $approval = $this->reloadItem($approval);
        $this->assertSame(0, (int) $approval->fields['users_id'], 'Not used by a manager actor');
        $this->assertSame(0, (int) $approval->fields['tasktemplates_id'], 'Not used by an approval step');
        $this->assertSame(0, (int) $approval->fields['solve_ticket'], 'Only used by an end step');
        $this->assertSame(2, (int) $approval->fields['manager_level']);
    }

    public function testPurgingAStepRemovesItsTransitions(): void
    {
        $this->login();

        $draft = $this->createPublishableDraft();
        $task  = new Node();
        $this->assertTrue($task->getFromDBByCrit([
            'plugin_flowtask_workflowversions_id' => $draft->getID(),
            'node_type'                           => NodeType::TASK->value,
        ]));

        $this->assertTrue($task->delete(['id' => $task->getID()], true));

        $this->assertSame(0, countElementsInTable(Transition::getTable(), ['plugin_flowtask_workflowversions_id' => $draft->getID()]));
    }

    /**
     * @return iterable<string, array{NodeType, NodeType, TransitionCondition, string}>
     */
    public static function invalidTransitionProvider(): iterable
    {
        yield 'condition not fired by a task' => [NodeType::TASK, NodeType::END, TransitionCondition::APPROVED, 'A step of type "Task" cannot fire the condition "Approved".'];
        yield 'condition not fired by an approval' => [NodeType::APPROVAL, NodeType::END, TransitionCondition::COMPLETED, 'A step of type "Approval" cannot fire the condition "Completed".'];
        yield 'leaving an end step' => [NodeType::END, NodeType::TASK, TransitionCondition::COMPLETED, 'A transition cannot leave an end step.'];
        yield 'entering the start step' => [NodeType::TASK, NodeType::START, TransitionCondition::COMPLETED, 'A transition cannot enter the start step.'];
    }

    #[DataProvider('invalidTransitionProvider')]
    public function testInvalidTransitionIsRefused(NodeType $source_type, NodeType $target_type, TransitionCondition $condition, string $message): void
    {
        $this->login();

        $draft  = $this->createDraft();
        $source = $this->createNode($draft, $source_type);
        $target = $target_type === NodeType::START ? $this->getStartNode($draft) : $this->createNode($draft, $target_type);

        $this->assertFalse((new Transition())->add([
            'plugin_flowtask_workflowversions_id' => $draft->getID(),
            'plugin_flowtask_nodes_id_source'     => $source->getID(),
            'plugin_flowtask_nodes_id_target'     => $target->getID(),
            'trigger_condition'                   => $condition->value,
        ]));
        $this->assertErrorMessages([$message]);
    }

    public function testTransitionCannotLoopOrDuplicateOrLeaveItsVersion(): void
    {
        $this->login();

        $draft = $this->createDraft();
        $task  = $this->createNode($draft, NodeType::TASK, ['name' => 'Review']);
        $end   = $this->createNode($draft, NodeType::END);
        $this->createTransition($task, $end, TransitionCondition::COMPLETED);
        $other = $this->createNode($this->createDraft(), NodeType::END);

        $transition = new Transition();
        $base       = [
            'plugin_flowtask_workflowversions_id' => $draft->getID(),
            'plugin_flowtask_nodes_id_source'     => $task->getID(),
            'trigger_condition'                   => TransitionCondition::COMPLETED->value,
        ];
        $this->assertFalse($transition->add($base + ['plugin_flowtask_nodes_id_target' => $task->getID()]));
        $this->assertFalse($transition->add($base + ['plugin_flowtask_nodes_id_target' => $end->getID()]));
        $this->assertFalse($transition->add($base + ['plugin_flowtask_nodes_id_target' => $other->getID()]));
        $this->assertErrorMessages([
            'A transition cannot loop on a single step.',
            'Step "Review" already has a "Completed" transition.',
            'Step "Review" already has a "Completed" transition.',
            'A transition must join two steps of its version.',
        ]);
    }

    public function testCategoryBelongsToASingleWorkflow(): void
    {
        $this->login();

        $category = $this->createItem(ITILCategory::class, ['name' => __FUNCTION__]);
        $first    = $this->createWorkflow();
        $this->createItem(Workflow_ITILCategory::class, [
            'plugin_flowtask_workflows_id' => $first->getID(),
            'itilcategories_id'            => $category->getID(),
        ]);

        $this->assertFalse((new Workflow_ITILCategory())->add([
            'plugin_flowtask_workflows_id' => $this->createWorkflow()->getID(),
            'itilcategories_id'            => $category->getID(),
        ]));
        $this->assertErrorMessages([
            sprintf('This category is already linked to the workflow "%s".', $first->fields['name']),
        ]);
    }

    public function testWorkflowWithInstancesCannotBePurged(): void
    {
        $this->login();

        $workflow = $this->createWorkflow();
        $version  = $this->createPublishableDraft($workflow);
        $this->assertSame([], $this->publish($version));
        $ticket = $this->createItem(Ticket::class, [
            'name'        => __FUNCTION__,
            'content'     => __FUNCTION__,
            'entities_id' => $this->getTestRootEntity(true),
        ]);
        $this->createItem(Instance::class, [
            'tickets_id'                          => $ticket->getID(),
            'plugin_flowtask_workflowversions_id' => $version->getID(),
        ]);

        $this->assertFalse($workflow->canPurgeItem());
        $this->assertFalse($workflow->delete(['id' => $workflow->getID()], true));
        $this->assertFalse($this->reloadItem($version)->delete(['id' => $version->getID()], true));
        $this->assertErrorMessages([
            'This workflow has instances and cannot be purged. Deactivate it instead.',
            'This version has instances and cannot be purged.',
        ]);
        $this->assertSame(1, countElementsInTable(WorkflowVersion::getTable(), ['id' => $version->getID()]));

        $this->assertTrue($workflow->update(['id' => $workflow->getID(), 'is_active' => 0]));
    }

    public function testWorkflowWithoutInstancesIsPurgedWithItsPublishedVersions(): void
    {
        $this->login();

        $workflow = $this->createWorkflow();
        $version  = $this->createPublishableDraft($workflow);
        $this->assertSame([], $this->publish($version));

        $this->assertTrue($workflow->canPurgeItem());
        $this->assertTrue($workflow->delete(['id' => $workflow->getID()], true));
        $this->assertSame(0, countElementsInTable(WorkflowVersion::getTable(), ['plugin_flowtask_workflows_id' => $workflow->getID()]));
        $this->assertSame(0, countElementsInTable(Node::getTable(), ['plugin_flowtask_workflowversions_id' => $version->getID()]));
    }

    public function testDraftCanBeDiscarded(): void
    {
        $this->login();

        $draft = $this->createPublishableDraft();

        $this->assertTrue($draft->canPurgeItem());
        $this->assertTrue($draft->delete(['id' => $draft->getID()], true));
        $this->assertSame(0, countElementsInTable(Node::getTable(), ['plugin_flowtask_workflowversions_id' => $draft->getID()]));
        $this->assertSame(0, countElementsInTable(Transition::getTable(), ['plugin_flowtask_workflowversions_id' => $draft->getID()]));
    }

    public function testPurgingAnActorResetsPublishedSteps(): void
    {
        $this->login();

        $user  = $this->createItem(User::class, ['name' => __FUNCTION__]);
        $draft = $this->createDraft();
        $task  = $this->createNode($draft, NodeType::TASK, ['users_id' => $user->getID()]);
        $end   = $this->createNode($draft, NodeType::END);
        $this->createTransition($this->getStartNode($draft), $task, TransitionCondition::ALWAYS);
        $this->createTransition($task, $end, TransitionCondition::COMPLETED);
        $this->assertSame([], $this->publish($draft));

        $this->assertTrue($user->delete(['id' => $user->getID()], true));

        $this->assertSame(0, (int) $this->reloadItem($task)->fields['users_id']);
    }

    public function testChangingAStepTypeDropsTransitionsItCanNoLongerFire(): void
    {
        $this->login();

        $draft    = $this->createDraft();
        $approval = $this->createNode($draft, NodeType::APPROVAL);
        $end      = $this->createNode($draft, NodeType::END);
        $this->createTransition($approval, $end, TransitionCondition::APPROVED);
        $this->createTransition($approval, $end, TransitionCondition::REJECTED);

        $this->assertTrue($approval->update([
            'id'               => $approval->getID(),
            'node_type'        => NodeType::TASK->value,
            'tasktemplates_id' => $this->createItem(TaskTemplate::class, ['name' => __FUNCTION__, 'content' => 'x'])->getID(),
        ]));

        $this->assertSame(0, countElementsInTable(Transition::getTable(), ['plugin_flowtask_nodes_id_source' => $approval->getID()]));
        $this->hasSessionMessages(WARNING, ['Transitions this step can no longer fire were removed.']);
    }

    public function testPublishingRechecksSavedTransitions(): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $this->login();

        $draft = $this->createPublishableDraft();
        $end   = new Node();
        $this->assertTrue($end->getFromDBByCrit([
            'plugin_flowtask_workflowversions_id' => $draft->getID(),
            'node_type'                           => NodeType::END->value,
        ]));
        // Rows that bypassed the item classes: an edge out of an END step and
        // an edge to a step that does not exist.
        $DB->insert(Transition::getTable(), [
            'plugin_flowtask_workflowversions_id' => $draft->getID(),
            'plugin_flowtask_nodes_id_source'     => $end->getID(),
            'plugin_flowtask_nodes_id_target'     => $this->getStartNode($draft)->getID() + 100000,
            'trigger_condition'                   => TransitionCondition::COMPLETED->value,
        ]);

        $this->assertSame(
            ['A transition points at a step that is not in this version.'],
            $this->publish($draft),
        );
    }

    public function testItemsCannotMoveToAnotherParent(): void
    {
        $this->login();

        $draft = $this->createDraft();
        $other = $this->createDraft();
        $end   = $this->createNode($draft, NodeType::END);

        $this->assertTrue($end->update(['id' => $end->getID(), 'plugin_flowtask_workflowversions_id' => $other->getID()]));
        $this->assertSame($draft->getID(), (int) $this->reloadItem($end)->fields['plugin_flowtask_workflowversions_id']);

        $workflow_id = (int) $draft->fields['plugin_flowtask_workflows_id'];
        $this->assertTrue($draft->update(['id' => $draft->getID(), 'plugin_flowtask_workflows_id' => $other->fields['plugin_flowtask_workflows_id']]));
        $this->assertSame($workflow_id, (int) $this->reloadItem($draft)->fields['plugin_flowtask_workflows_id']);
    }

    public function testWorkflowIsInTheSetupMenu(): void
    {
        $this->login();

        $menu = Workflow::getMenuContent();

        $this->assertIsArray($menu);
        $this->assertSame('/plugins/flowtask/front/workflow.php', $menu['page']);
    }
}
