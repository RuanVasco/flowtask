# 001 - Workflow core (v0.1)

Status: Accepted

## Problem

GLPI has no native way to run a multi-step process inside a ticket where each step belongs to a different actor and the next step starts automatically. Teams do it by hand (creating tasks, reassigning groups), which is slow and inconsistent.

## Scope

A workflow definition is a directed graph of steps (nodes) and transitions (edges). When a ticket matches a workflow, an instance starts and walks the graph: each step materializes as native GLPI objects (ticket task or validation) assigned to an actor, and finishing it fires the matching outgoing transition.

### Concept mapping (Formulei to GLPI)

Formulei is used only as a design reference; the code is rewritten.

| Formulei concept | Flowtask / GLPI |
|---|---|
| `FormWorkflow` + versioning | `PluginFlowtaskWorkflow` + `PluginFlowtaskWorkflowVersion` (immutable once published) |
| `WorkflowNode` | `PluginFlowtaskNode` (belongs to a version) |
| `WorkflowTransition` + condition | `PluginFlowtaskTransition` with a condition |
| `WorkflowInstance` | `PluginFlowtaskInstance` linked 1:1 to a `Ticket` |
| `WorkflowInstanceStep` / active node | `PluginFlowtaskInstanceStep` linked to the created `TicketTask` or `TicketValidation` |
| Trigger by form | Trigger by ticket category only |
| Department | `Group` |
| User | `User` |
| Manager (N levels) | Requester's user supervisor (`users.users_id_supervisor`), N levels up |
| Role | `Profile` within the ticket entity |
| Node `FORM_FILL` / `DATA_COLLECTION` | `TASK`: a `TicketTask` from a `TaskTemplate` |
| Node `APPROVAL` | `APPROVAL`: a `TicketValidation` |
| Node `END` | `END`: optionally solves the ticket |
| Condition `COMPLETED` | Task state set to done |
| Conditions `APPROVED` / `REJECTED` | Validation accepted / refused |

### v0.1 node types

- `START`: implicit entry point.
- `TASK`: creates a `TicketTask` (content from a task template), assigned to the actor; completes when the task is marked done.
- `APPROVAL`: creates a `TicketValidation` for the actor; branches on approved or refused.
- `END`: closes the instance and optionally adds a solution to the ticket.

### Actor types

`USER`, `GROUP`, `REQUESTER_MANAGER` (level N, behavior when missing: block or skip), `PROFILE`.

### Definition management rules

- Workflows are managed from the ticket category they serve: a "Workflow" tab on the category shows the linked workflow, or offers to create one (already linked to the category) or to link an existing one. **Setup > Workflows** lists every workflow, as one workflow can serve several categories. Everything is guarded by the `plugin_flowtask_workflow` right.
- Versions stay in the background. The workflow form shows its current version (the draft, or else the published one) with the actions that apply: "Edit" creates a draft, "Publish" publishes it, "Discard draft" drops it. Its steps and transitions tabs show that current version. A versions tab keeps the history.
- A new workflow starts with a draft. A workflow has at most one draft version at a time. A draft copies the graph (steps and transitions) of the latest published version, or starts with a lone `START` step when there is none. Version numbers increase by one.
- Steps and transitions can only be added, edited or removed while their version is a draft. The `START` step is created with the draft and cannot be added, edited or removed by hand.
- A step of type `TASK` or `APPROVAL` needs an actor; a `TASK` step also needs a task template. Fields that do not apply to the chosen type or actor are reset.
- A transition joins two steps of the same version, cannot loop on a single step, cannot leave an `END` step and cannot enter the `START` step. Its condition must fit the source step: `ALWAYS` from `START`, `COMPLETED` from `TASK`, `APPROVED` or `REJECTED` from `APPROVAL`.
- Publishing a draft checks that every step has its required outgoing transitions (`START`: `ALWAYS`; `TASK`: `COMPLETED`; `APPROVAL`: `APPROVED` and `REJECTED`), that there is at least one `END` step and that every step is reachable from `START`. On failure nothing changes and every problem is listed. On success the previous published version becomes archived.
- A ticket category belongs to at most one workflow; linking a category that is already linked is refused with a message naming the other workflow.
- Only draft versions can be purged; published and archived versions are kept as the history of the workflow.
- Purging a user, group, profile or task template resets it on every step that uses it, published versions included; the step is checked again before it runs (stage 3).

## Acceptance criteria

1. **AC-1** An administrator with the plugin right can create a workflow, add `TASK`, `APPROVAL` and `END` nodes, connect them with transitions, and publish a version.
2. **AC-2** A published version cannot be edited; editing creates a new draft version.
3. **AC-3** A workflow is linked to one or more ticket categories; creating a ticket in one of them starts an instance on the latest published version.
4. **AC-4** Entering a `TASK` node creates a `TicketTask` assigned to the resolved user or group.
5. **AC-5** Marking that task as done advances the instance through the matching transition.
6. **AC-6** Entering an `APPROVAL` node creates a `TicketValidation`; accepting or refusing follows the `APPROVED` or `REJECTED` transition.
7. **AC-7** `REQUESTER_MANAGER` resolves the supervisor N levels up; if missing, the node is blocked (instance flagged stuck) or skipped, per node config.
8. **AC-8** Running instances keep using the version they started with after a new version is published.
9. **AC-9** The ticket shows a "Workflow" tab with the timeline: steps done, current step, actor, and dates.
10. **AC-10** Uninstalling the plugin drops all `glpi_plugin_flowtask_*` tables without touching tickets, tasks or validations already created.
11. **AC-11** All UI strings are translatable and available in `en_GB` and `pt_BR`.
12. **AC-12** A ticket runs at most one workflow instance. A ticket whose category is linked to a workflow starts exactly one instance; changing the category of a ticket that already has an instance does not start another one.
13. **AC-13** While an instance is active, deleting or purging a `TicketTask` or `TicketValidation` created by it is refused with a translatable message.
14. **AC-14** If a step's task or validation disappears anyway (e.g. removed directly in the database), the instance is flagged stuck with the reason recorded, and nothing is recreated automatically.
15. **AC-15** A workflow that has instances (running or finished) cannot be purged, and none of its versions can be purged; the refusal shows a translatable message. Such a workflow can only be deactivated.

## Out of scope (v0.1)

- Visual drag-and-drop editor (v0.2; definitions are edited through GLPI forms in v0.1).
- Parallel branches and joins.
- Timers, SLA per step, HTTP or webhook nodes, notifications beyond GLPI's native ones.
- Child tickets, transfer between workflows.
- Conditions on ticket fields or form answers.
- Starting a workflow from a business rule action (category is the only trigger).
- More than one workflow per ticket (use child tickets in a later version).
- GLPI 11 support.

## Stages

1. Data model: tables, item classes, install/uninstall migrations, rights (AC-10, AC-12 at the schema level).
2. Definition management: forms to create workflows, nodes, transitions, versions and category links, and the purge guard (AC-1, AC-2, AC-3, AC-15).
3. Engine: instance start, actor resolution, task and approval steps, transitions, deletion guard and stuck detection (AC-3 to AC-8, AC-12 to AC-14).
4. Ticket tab with timeline, translations (AC-9, AC-11).

## Decisions

- **Trigger:** only by ticket category in v0.1; business rule actions are out of scope.
- **Deleting workflow-created items:** blocked while the instance is active (AC-13); if they disappear anyway, the instance is flagged stuck (AC-14).
- **Workflows per ticket:** at most one (AC-12).
- **Purging a workflow with instances:** refused, so instances never point to a missing version; deactivate it instead (AC-15).
