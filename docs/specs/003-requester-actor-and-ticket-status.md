# 003 - Requester as actor and ticket status per step

Status: Accepted

## Problem

Many processes need the person who opened the ticket to act in the middle of the workflow: send a missing document, confirm the data, approve a proposal. Spec 001 only assigns steps to a fixed user, a group or the requester's manager, so the requester cannot be the actor of a step.

While a step waits, the ticket status says nothing about whose turn it is. When the requester has to act, the ticket stays "Processing" and its SLA keeps running, although the service desk is waiting for the requester. GLPI has the "Pending" status for that, but the workflow never sets it.

## Scope

### Requester actor

- A new actor type `REQUESTER` for `TASK` and `APPROVAL` steps, labelled "Requester". It needs no other field.
- It resolves to the first requester user of the ticket, the same user `REQUESTER_MANAGER` starts from (spec 001). A ticket with no requester user (only a group or an email address as requester) flags the instance stuck with the reason "The ticket has no requester user."; retrying after a requester user was added resumes the step. The missing manager behavior (block or skip) does not apply to this actor.
- An `APPROVAL` step creates a `TicketValidation` targeted at the requester user, as for a `USER` actor. GLPI already lets the target of a validation answer it, from the standard and the simplified interface, as long as their profile has the "Validate request" or "Validate incident" right; the default Self-Service profile has neither, so the administrator grants them (the plugin does not change profiles).
- A requester user that is deleted or inactive flags the instance stuck with the reason "The requester of the ticket is deleted or inactive.", as for a `USER` actor.
- A `TASK` step creates the `TicketTask` from its template, assigned to the requester user, and always public (`is_private = 0`, whatever the template says), so the requester sees it in the ticket timeline.
- The requester answers a task step the way requesters already talk to the service desk: while a `TASK` step assigned to the requester is active, a public followup added by that requester completes it. The engine marks the task done (`state = DONE`, which fires the `COMPLETED` transition through the existing task hook) and adds nothing else. Attachments sent with the followup stay on the followup, as in any GLPI ticket.
- Marking the task done by other means keeps working (a technician, or the requester from the timeline when GLPI shows them the toggle); whichever comes first ends the step, and a later followup changes nothing.
- Only a followup on the ticket of the active step counts, only from the requester user the task is assigned to, and only a public one. Followups by anyone else, private followups, and followups while the active step is not a requester task change nothing.
- A followup by the requester on a "Pending" ticket already makes GLPI reopen it (`CommonITILObject::needReopen`); the status rules below then apply to the next step as usual.
- The diagram editor lists "Requester" among the actors; its nodes show "Requester" as their actor.

### Ticket status per step

- `TASK` and `APPROVAL` steps get a "Ticket status" setting: "Do not change" (default), "Processing (assigned)", "Processing (planned)" or "Pending" (`Ticket::ASSIGNED`, `Ticket::PLANNED`, `Ticket::WAITING`). New, solved and closed are not offered: a ticket is solved by an `END` step (spec 001).
- Stored in a new `ticket_status` column of `glpi_plugin_flowtask_nodes` (int, `0` = do not change). `StepConfiguration::normalize` resets it to `0` on `START` and `END` steps and on any value outside the list above.
- In the side panel, choosing the "Requester" actor sets the ticket status to "Pending" when it was "Do not change", so the common case needs no extra click. The user can change it afterwards; the server stores what is saved.
- When the engine enters a step and creates its task or approval, it sets the configured status on the ticket, after the item is created (GLPI may change the status itself when a task is added, and the step setting wins). A skipped step or a stuck instance does not change the status.
- A "Pending" step only holds the ticket while it waits. When the instance leaves a step set to "Pending" and enters a step that does not change the status, or an `END` step that does not solve the ticket (or fails to), the ticket goes back to "Processing (assigned)" if it is still "Pending". Skipped steps in between do not count; a step that gets stuck releases the ticket once it is retried; cancelling the instance on a "Pending" step releases it too. A ticket put on "Pending" by hand, outside the workflow, is not touched by steps set to "Do not change".
- GLPI itself takes a ticket off "Pending" when a task or followup is added to it or a task is changed (`PendingReason_Item`); the engine does not fight that, it only sets the status of the step it enters afterwards. The one exception is the task of the active step being marked done: GLPI handles the parent status of a task after the update hooks have run, so it would reopen a ticket the next step has just put on "Pending"; for that update the engine drops the pending toggle and asks GLPI not to reopen (`_no_reopen`), and the step rules above decide the status.
- The status change is made by the engine like the solution of an `END` step: it does not depend on the status lifecycle allowed to the profile of the user whose action moved the instance (for example the requester answering an approval). GLPI logs it in the ticket history and sends its usual notifications.

### Persistence

- `ticket_status` is part of the step fields saved by the diagram endpoints (spec 002) and copied with the graph when a draft is created (spec 001).
- Version 0.1.0 has not been released, so the column is added to the table definition. An update step adds it with `Migration::addField` when it is missing, so installs made before this spec keep working.
- Strings are translated with the `flowtask` domain, in `en_GB` and `pt_BR`.

## Acceptance criteria

1. **AC-1** A `TASK` or `APPROVAL` step can be configured with the `REQUESTER` actor; it is complete (publishable) without any other actor field.
2. **AC-2** Entering a `REQUESTER` step assigns its task, or targets its approval, to the first requester user of the ticket; a `REQUESTER` task is public even when its template is private.
3. **AC-3** A ticket with no requester user flags the instance stuck with its reason; after a requester user is added, retry resumes the step.
4. **AC-4** While a `REQUESTER` task step is active, a public followup by that requester marks the task done and advances the instance on `COMPLETED`; a followup by anyone else, a private followup, or a followup while the active step is not a requester task changes nothing.
5. **AC-5** A step can be saved with a ticket status from the allowed list; any other value, and any value on a `START` or `END` step, is stored as "Do not change".
6. **AC-6** Choosing the "Requester" actor in the side panel sets the ticket status to "Pending" when it was "Do not change".
7. **AC-7** Entering a step with a ticket status sets it on the ticket once the task or approval exists; a skipped step and a stuck instance leave the status unchanged.
8. **AC-8** Leaving a "Pending" step for a step that does not change the status, or for an `END` step that does not solve the ticket, sets a ticket that is still "Pending" back to "Processing (assigned)".
9. **AC-9** The status change works when the action that moves the instance comes from a user whose profile cannot change the ticket status (e.g. the requester answering an approval).
10. **AC-10** The new actor and the ticket status setting are translatable and available in `en_GB` and `pt_BR`.

## Out of scope

- Other requesters than the first requester user (all requesters, a requester group, observers).
- Completing task steps of other actors by followup.
- A "waiting for the requester" pending reason (`PendingReason`) and its automatic followups and resolution.
- Setting the ticket status on `START` or `END` steps, or setting new, solved or closed.
- Showing the status changes in the workflow timeline (the ticket history already has them).
- A form-filling activity for the requester (Formulei task 305, its own spec).

## Stages

1. Requester actor: `ActorType::REQUESTER`, resolution in `ActorResolver`, public task in `StepItemFactory`, completion by followup (`ITILFollowup` add hook forwarded to the engine), editor actor option and node label (AC-1 to AC-4).
2. Ticket status per step: column and update step, normalization, side panel field with the requester default, engine apply and restore, translations for both stages (AC-5 to AC-10).

Each stage ends with a code review and is exercised in the dev GLPI with a self-service requester.

## Open questions

- None yet.
