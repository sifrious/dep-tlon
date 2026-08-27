# STATE (v2) — applying schema changes

**Not built. Not part of v1.** This file exists so the v1 model can be checked against where it is going, and so the plan can be written later without re-deriving the shape. No requirement in `SPEC.md` names any of it, and the tar pit phases must ignore it.

## Why v1 does not need to change

A schema change is a transition between two inspections. v1 already stores observations per inspection and already derives the difference between two of them, so verifying that a change did what it said is the change report from R16 applied to the inspections either side of it. Had v1 stored current state and updated it in place, none of that would exist and this feature would need its own before-and-after machinery.

Two v1 facts become load-bearing here, which is worth knowing before either is traded away:

- **Observations are kept per inspection.** The before state must still exist after the change lands.
- **Identity is the name.** This is the one place it hurts, and the one place a change feature can fix it: v1 reads a rename as one table absent and another added, but a change set that *declares* a rename knows better and can carry notes across. See V2-12.

## Proposed relations

### ChangeSet

| field | type | notes |
|-------|------|-------|
| id | identifier | key |
| source_id | reference to Source | |
| name | text | what a person calls this change |
| intent | text | why, in the person's words |
| status | text | draft, rendered, approved, applied, failed, reverted |
| created_at | timestamp | |

### ChangeOperation

| field | type | notes |
|-------|------|-------|
| change_set_id | reference to ChangeSet | key part |
| position | integer | key part; order of application |
| kind | text | add table, drop table, add column, drop column, alter column, rename, add index, drop index, add reference, drop reference |
| table_name | text | |
| column_name | text | empty when the subject is the table |
| parameters | text | what the kind needs, such as the new type or the new name |

### RenderedChange

| field | type | notes |
|-------|------|-------|
| change_set_id | reference to ChangeSet | key part |
| engine | text | key part |
| statements | text | the generated statements, in order |
| fingerprint | text | derived from the operations, not from the clock |
| rendered_at | timestamp | |
| against_inspection_id | reference to Inspection | the state the statements assume |

### ChangeApplication

| field | type | notes |
|-------|------|-------|
| id | identifier | key |
| change_set_id | reference to ChangeSet | |
| engine | text | |
| fingerprint | text | which rendering was applied |
| approved_by | text | the named person who approved it |
| started_at | timestamp | |
| ended_at | timestamp | |
| outcome | text | applied, failed, partially applied |
| stopped_at_position | integer | which operation failed; empty when applied |
| failure | text | what the engine reported |
| before_inspection_id | reference to Inspection | |
| after_inspection_id | reference to Inspection | |

## Derived

| name | definition | needed by |
|---|---|---|
| verified | an application is verified when the change report between its before and after inspections matches what its operations declared | V2-05 |
| unexplained difference | anything in that change report the operations did not declare | V2-05 |
| stale rendering | a rendering is stale when its source has a completed inspection newer than the one it was rendered against | V2-10 |
| reversal | the operations that would return a change set's source to its before inspection | V2-09 |

## Constraints

- An application names a rendering that exists for its engine and fingerprint.
- An application names an approver. Nothing applies without one.
- An application's before inspection precedes its started_at; its after inspection follows its ended_at.
- A rendering's fingerprint is a function of its change set's operations and its engine, and of nothing else.
- Operation positions within a change set are unique and contiguous.
- A change set belongs to exactly one source, and every operation names a table in that source.

## The rule this feature must not break

v1's R32 says inspection reads and writes nothing. That stays true. Writing happens only through an approved application, over a connection that is separately configured for it, and never as a side effect of inspecting. The read path and the write path do not share a door.
