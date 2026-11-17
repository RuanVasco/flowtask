# 001 - Workflow core (v0.1)

Status: Draft

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
| Trigger by form | Trigger by ticket category (v0.1); business rules action later |
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

## Out of scope (v0.1)

- Visual drag-and-drop editor (v0.2; definitions are edited through GLPI forms in v0.1).
- Parallel branches and joins.
- Timers, SLA per step, HTTP or webhook nodes, notifications beyond GLPI's native ones.
- Child tickets, transfer between workflows.
- Conditions on ticket fields or form answers.
- GLPI 11 support.

## Stages

1. Data model: tables, item classes, install/uninstall migrations, rights (AC-10).
2. Definition management: forms to create workflows, nodes, transitions, versions and category links (AC-1, AC-2, AC-3).
3. Engine: instance start, actor resolution, task and approval steps, transitions (AC-3 to AC-8).
4. Ticket tab with timeline, translations (AC-9, AC-11).

## Open questions

- Should a workflow also start from a business rule action, in addition to category?
- What happens if a technician deletes a task created by the workflow: mark the instance stuck, or recreate the task?
- Can a ticket run more than one workflow at the same time?
