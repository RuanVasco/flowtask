# 002 - Workflow diagram editor

Status: Accepted

## Problem

In v0.1 a workflow is built through one GLPI form per step and per transition. Building a graph that way is slow, the shape of the process is never visible, and it is hard to show to the people who own the process. The diagram must be the main way to build a workflow: everything needed to go from an empty draft to a published version is done on it, without opening another page.

## Scope

A diagram component (React Flow, bundled with Vite into `public/lib/`) shows the graph of a workflow version. It has two modes:

- **Editor**: on the workflow, when its current version is a draft and the user can update the workflow.
- **Viewer**: on the workflow when the current version is published or archived (or the user cannot update it), on a version page, and on the ticket "Workflow" tab next to the timeline, with the active step highlighted.

### Where it lives

- The workflow form gets a "Diagram" tab, shown first after the main form, which shows the current version (spec 001: the draft, or else the published one).
- The diagram has a toolbar with the version actions of spec 001 that apply: "Edit" (create a draft) on a published version, "Publish" and "Discard draft" on a draft. The user never needs the version card or the steps and transitions tabs to build and publish a workflow.
- The ticket "Workflow" tab shows the viewer for the version the instance runs on, above the timeline. The step the instance waits on is highlighted; steps already passed are marked as done or skipped.

### Editor actions

- **Add a step** from a palette: Task, Approval or End. The step is created where it is dropped, with a default name (its type label) the user can change. The `START` step is created with the draft (spec 001) and cannot be added or removed.
- **Move a step** by dragging; its position is saved in `position_x` / `position_y`.
- **Connect two steps** by dragging from a source handle to a target. The condition is set by the source type when only one fits (`ALWAYS` from Start, `COMPLETED` from Task). From an Approval the step has two handles, "Approved" and "Rejected", so the condition is chosen by the handle used. A connection the rules of spec 001 refuse (loop on a step, out of End, into Start, a condition the source already uses) is not created and the reason is shown.
- **Configure a step** in a side panel opened by selecting it: name, type, actor (user, group or requester manager), user or group, manager level, behavior when the manager is missing, task template, solve the ticket. Only the fields that apply to the type and actor are shown, as in the step form. User, group and task template pickers search through GLPI's dropdown endpoint, so entity visibility and rights are the same as in GLPI forms.
- **Delete** a selected step (and its transitions) or transition, with the Delete key or a button.
- **Publish** from the toolbar. When it fails, every step with a problem is highlighted on the diagram and the problems are listed (the same messages as spec 001).
- **Steps with missing configuration** (no actor, no task template, a purged user or group) show a warning marker before publishing, so problems are visible while building.

### Persistence and rules

- Every action is saved when it happens, through JSON endpoints that go through the same `Node`, `Transition` and `VersionPublisher` classes as the forms. The rules of spec 001 stay in one place: an action they refuse is undone on the diagram and its message is shown next to it.
- The endpoints check the same rights as the forms (`plugin_flowtask_workflow`, entity of the workflow), refuse any change to a version that is not a draft, and rely on GLPI's CSRF protection.
- A step can be saved before its configuration is complete (no actor or task template yet): it is added from the palette, then configured in the side panel. The completeness rule of spec 001 (a `TASK` or `APPROVAL` step needs an actor, a `TASK` step a task template) is checked when publishing, which already lists it, and on the diagram by the warning markers. Saving still normalizes the fields, refuses an unknown type, and refuses any change to the `START` step except its position.
- Endpoints (JSON, `POST`, under `/plugins/flowtask/`): `WorkflowVersion/{id}/Node` adds a step, `WorkflowVersion/{id}/Positions` saves step positions, `WorkflowVersion/{id}/Transition` adds a transition, `Node/{id}/Delete` and `Transition/{id}/Delete` remove one. A refused change answers with its messages, which the diagram shows.
- A version whose steps all sit at position (0, 0) (built with the forms, or before this spec) is laid out automatically (dagre, left to right) when it is opened. In the editor that layout is saved; in the viewer it is not.
- The GLPI forms of spec 001 keep working while the editor is being validated. Once the maintainer validates the editor, they are removed and the diagram becomes the only way to edit a version (stage 4).

### Look

- Nodes follow BPMN shapes loosely: Start and End as circles, Task as a rounded box, Approval as a diamond-tagged box with its two outputs. Each node shows its type icon (the Tabler icons already used by the timeline), its name and its actor.
- Edges show their condition label when the source has more than one output (Approval).
- Colors come from GLPI's theme variables, so the diagram follows light and dark themes.
- Strings are translated on the server with the `flowtask` domain and passed to the component; the bundle has no strings of its own.

### Build

- Sources live in `js/` (React + TypeScript), built by Vite into `public/lib/diagram/`. `package.json` pins React, React Flow (`@xyflow/react`) and dagre.
- The built bundle is not committed. CI and `tools/ci-local.sh` install the npm dependencies and run the build, and fail when it fails. The release archive includes the built bundle.
- `CONTRIBUTING.md` documents `npm install`, `npm run build` and `npm run dev` (watch) for the dev GLPI.

## Acceptance criteria

1. **AC-1** On a draft, a user with the update right on workflows can build a complete workflow on the diagram alone: add Task, Approval and End steps, configure each step, connect them, and publish, without opening any other page.
2. **AC-2** Moving a step saves its position; reopening the workflow shows the steps where they were left.
3. **AC-3** Connecting two steps creates a transition with the condition given by the source type or by the Approval handle used; a connection that spec 001 refuses is not created and its reason is shown.
4. **AC-4** Changing a step in the side panel saves it with the same validation as the step form; a refused change shows the message and the panel keeps the saved values.
5. **AC-5** Deleting a step removes it with its transitions; the Start step cannot be deleted.
6. **AC-6** Publishing from the diagram runs the checks of spec 001; on failure each step with a problem is highlighted and the problems are listed.
7. **AC-7** A published or archived version, or a user without the update right, gets the viewer: nothing can be moved, connected, configured or deleted, and the endpoints refuse such changes.
8. **AC-8** The ticket "Workflow" tab shows the diagram of the instance version with the active step highlighted and the passed steps marked.
9. **AC-9** A version with no saved positions is laid out automatically.
10. **AC-10** The diagram's strings are translatable and available in `en_GB` and `pt_BR`.
11. **AC-11** CI builds the bundle; a build failure fails CI.
12. **AC-12** After stage 4, steps and transitions can only be changed through the diagram; the old form URLs no longer change anything.

## Out of scope

- Undo and redo, copy and paste, multi-selection.
- Parallel branches, joins, and any node type not in spec 001.
- Editing a published version in place (spec 001: edit creates a draft).
- Real-time collaboration (two users editing the same draft see each other's changes only after reloading).

## Stages

1. Build and viewer: `package.json`, Vite, CI and `tools/ci-local.sh`, the graph JSON endpoint, the viewer on the workflow and on the ticket tab with the active step highlighted, auto layout (AC-7 viewer side, AC-8, AC-9, AC-11).
2. Editor structure: palette, move, connect, delete, with their endpoints (AC-2, AC-3, AC-5, AC-7 endpoint side).
3. Step configuration and publishing: side panel with pickers, warning markers, toolbar actions (edit, publish, discard), errors highlighted on the diagram, translations (AC-1, AC-4, AC-6, AC-10).
4. Remove the forms, once the maintainer has validated the editor: the step and transition forms (`front/node.form.php`, `front/transition.form.php`, `VersionItemForm`) and their add buttons go; the steps and transitions tabs become read-only lists or are dropped in favor of the diagram (AC-12).

## Open questions

- None yet.
