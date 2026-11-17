# Specs

Every feature or non-trivial fix starts here, before any code.

## Conventions

- File name: `NNN-short-name.md`, with a three-digit sequential prefix (`001-`, `002-`, ...).
- Status at the top: `Draft`, `Accepted`, `Implemented` or `Superseded by NNN`.
- Sections: Problem, Scope, Acceptance criteria (numbered, testable), Out of scope, Stages, Open questions.
- Implementation commits and tests reference the acceptance criteria they cover (e.g. `AC-3`).
- A spec that is `Implemented` is only changed to fix mistakes; new behavior gets a new spec.
