# STATE — Tlon v1

## Essential relations

### Source

| field | type | notes |
|-------|------|-------|
| id | identifier | key |
| name | text | what a person calls it |
| engine | text | one of sqlite, mysql, postgresql |
| connection | text | how to reach it, including the secret |
| registered_at | timestamp | when the person added it |

Key: `id`. Source: R1, R3, R30.

### Inspection

| field | type | notes |
|-------|------|-------|
| id | identifier | key |
| source_id | reference to Source | |
| started_at | timestamp | |
| ended_at | timestamp | |
| outcome | text | completed or failed |
| stopped_at | text | which step failed; empty when completed |
| failure | text | what the engine reported; empty when completed |

Key: `id`. Source: R5, R16, R17.

### ObservedTable

| field | type | notes |
|-------|------|-------|
| inspection_id | reference to Inspection | key part |
| name | text | key part |
| kind | text | table or view |
| description | text | what the database itself says about it |
| row_estimate | integer | the engine's own approximation |
| size_bytes | integer | as the engine reports it |
| measured_at | timestamp | when the engine measured the two figures above |

Key: `inspection_id, name`. Source: R5, R10, R11.

### ObservedColumn

| field | type | notes |
|-------|------|-------|
| inspection_id | reference to Inspection | key part |
| table_name | text | key part |
| name | text | key part |
| position | integer | declared order within the table |
| declared_type | text | verbatim, as the engine states it |
| accepts_nothing | boolean | whether the column admits an absent value |
| default_expression | text | empty string and no default are different things |
| has_default | boolean | which of those two it is |
| description | text | what the database itself says about it |

Key: `inspection_id, table_name, name`. Source: R6, R11.

### ObservedPrimaryKeyColumn

| field | type | notes |
|-------|------|-------|
| inspection_id | reference to Inspection | key part |
| table_name | text | key part |
| position | integer | key part; order within the key |
| column_name | text | |

Key: `inspection_id, table_name, position`. Source: R7.

### ObservedReference

| field | type | notes |
|-------|------|-------|
| id | identifier | key |
| inspection_id | reference to Inspection | |
| name | text | what the engine calls it, where it names it |
| from_table | text | |
| to_table | text | |
| on_update | text | what the engine declares |
| on_delete | text | what the engine declares |

Key: `id`. Source: R8.

### ObservedReferenceColumn

| field | type | notes |
|-------|------|-------|
| reference_id | reference to ObservedReference | key part |
| position | integer | key part; order within the reference |
| from_column | text | |
| to_column | text | |

Key: `reference_id, position`. Source: R8.

### ObservedIndex

| field | type | notes |
|-------|------|-------|
| id | identifier | key |
| inspection_id | reference to Inspection | |
| table_name | text | |
| name | text | |
| requires_uniqueness | boolean | |

Key: `id`. Source: R9.

### ObservedIndexColumn

| field | type | notes |
|-------|------|-------|
| index_id | reference to ObservedIndex | key part |
| position | integer | key part; order within the index |
| column_name | text | |

Key: `index_id, position`. Source: R9.

### UnavailableDatum

| field | type | notes |
|-------|------|-------|
| inspection_id | reference to Inspection | key part |
| table_name | text | key part; empty when the subject is the source |
| column_name | text | key part; empty when the subject is a table |
| datum | text | which fact the engine could not supply |

Key: `inspection_id, table_name, column_name, datum`. Source: R12.

### Note

| field | type | notes |
|-------|------|-------|
| id | identifier | key |
| source_id | reference to Source | |
| table_name | text | |
| column_name | text | empty when the note is about the table |
| body | text | free text |
| written_at | timestamp | |

Key: `id`. Source: R18, R19, R20.

## Derived data (computed, never stored)

| name | definition (plain sentence) | needed by |
|------|------------------------------|-----------|
| current inspection | the most recent inspection of a source whose outcome is completed | R13, R14, R21 |
| current schema | the observed tables, columns, keys, references and indexes belonging to a source's current inspection | R21, R22, R24, R26 |
| present | a table or column is present when it appears in its source's current inspection | R13, R15 |
| absent | a table or column is absent when it appears in some earlier inspection of its source but not in the current one | R14, R20 |
| last seen | the latest inspection of a source in which a given table or column was observed | R21 |
| column count | the number of observed columns whose table name matches a given table within one inspection | R21 |
| change report | the difference between the observations of a source's current inspection and those of the completed inspection immediately before it, expressed as added, changed and newly absent | R16 |
| referencing tables | the observed references in the current inspection whose target table is the one being asked about | R23 |
| search matches | the observed tables and columns in every source's current inspection whose name contains the given text, each carrying its source | R24, R25 |
| note subject absent | a note's subject is absent when the table or column it names is absent | R20 |
| removal summary | the count of inspections, observations and notes belonging to a source | R4 |
| reachable | whether the engine answers a connection attempt now; a function of the outside world and of a Source's connection, never of stored data | R2 |
| export | the current schema of every source, together with its notes, rendered in a portable form | R26 |
| stopping point | the step named by a failed inspection, together with what it reported | R17 |

## Constraints

- C1: No two sources share a name.
- C2: A source's engine is sqlite, mysql, or postgresql.
- C3: An inspection's ended_at is not before its started_at.
- C4: A failed inspection names the step that stopped it. A completed inspection names none.
- C5: An observed table's kind is table or view.
- C6: Every observed column belongs to a table observed in the same inspection.
- C7: Column positions within one observed table are unique, and start at the first position with no gaps.
- C8: A column whose has_default is false has an empty default_expression.
- C9: Every primary key column names a column observed on the same table in the same inspection.
- C10: Primary key positions within one table are unique, and start at the first position with no gaps.
- C11: Every observed reference names a from_table and a to_table observed in the same inspection.
- C12: A reference has at least one reference column, and its positions are unique and contiguous.
- C13: Every reference column names a from_column observed on the reference's from_table, and a to_column observed on its to_table, in the same inspection.
- C14: Every observed index names a table observed in the same inspection, and has at least one index column.
- C15: Every index column names a column observed on the index's table in the same inspection.
- C16: An unavailable datum names either a source alone, a table observed in the same inspection, or a column observed in the same inspection.
- C17: A note names a table. A note that also names a column names one observed at least once in that source.
- C18: Every inspection, observation and note belongs to exactly one source, directly or through its inspection.

## Requirements that introduce no state

Seven requirements name no relation because none of them is about data.

| Requirement | Why it stores nothing |
|---|---|
| R27 | Where display and routing live is a structural rule about code, not a fact to remember. |
| R28 | The parent contract is a shape in code. Which kind a source is lives in `Source.engine`. |
| R29 | Composition over inheritance is a structural rule. Steps are code, and what they produce is already in the observation relations. |
| R31 | Surviving a restart is a property of whatever holds these relations, not another relation. |
| R32 | Reading without writing is a property of the inspection code. A violation is a bug, not a record. |
| R33 | Working offline is an environmental property. The demo source is ordinary Source and Inspection rows. |
| R34 | Inspecting without holding everything is how the code runs, not a thing to store. |

## Deliberately absent from v1

Applying schema changes is planned for a later version. No relation for it exists here, and phase 3 must not build one. Its shape is captured in `STATE-v2-schema-changes.md`, kept separate so this file stays the exact contract the core implements.

Nothing in v1 needs to change to make it possible later. The observation model is the right substrate for it: a change is a transition between two inspections, and verifying one is the change report that R16 already computes.

## Why observations rather than current state

Two decisions in the model are worth defending, because both look like extra work.

**Nothing records what a schema currently is.** Each inspection records what it saw, and the current schema is the newest completed inspection's observations. The alternative, one row per table updated in place, cannot answer R16 at all: a change report needs the previous inspection to still exist. Once two inspections must be kept, keeping them all costs nothing conceptually and buys the whole history. If the volume ever hurts, pruning old inspections is a performance concern and belongs in an accidental layer that can be deleted without changing behaviour.

**Nothing records whether a table is present or absent.** Presence is the answer to "does it appear in the newest completed inspection", which is a question the stored data already answers. A stored flag would be a second source of truth that a failed inspection could desynchronise, which is exactly what R17 warns about. Deriving it makes R17 nearly free: a failed inspection contributes its observations without being the current inspection, so nothing it failed to reach can be mistaken for absent.

Identity follows from SPEC assumption three. A table is identified by its name within its source, so notes attach to names and survive re-inspection without any identity relation to maintain. A rename therefore reads as one table absent and another added, which is the documented behaviour rather than a limitation.
