# SPEC — Tlon v1

## Purpose

A person who owns relational databases they did not design uses Tlon to record what those databases physically contain, keep their own notes attached to it across re-inspections, and see what changed.

## Scope of this version

v1 reads relational database schemas. Nothing else. The semantic layer, the evidence chain, and the compiler are deliberately absent and are specified separately when v1 is done.

## Provenance rule

Nothing specific to a prior employer enters this project. No client database, schema, table, or column names; no business domain vocabulary; no production figures; no vendor systems that were only in use there. Generic engineering knowledge carries over. Anything that identifies a former workplace or its data does not. Where an existing implementation contains such a name, the concept is rebuilt from the requirement rather than copied from the code.

## Requirements

### Connecting

| ID | E/A | Requirement |
|----|-----|-------------|
| R1 | E | When a person registers a source by naming its engine and how to reach it, the system lists it among the known sources. |
| R2 | E | When a person tests a registered source, the system reports whether it can be reached and, if not, what failed. |
| R3 | E | When a person views a registered source, the system shows how to identify it but never reveals the secret used to reach it. |
| R4 | E | When a person removes a source, the system removes what it recorded about that source and reports what it removed. |

### Reading a schema

| ID | E/A | Requirement |
|----|-----|-------------|
| R5 | E | When a person inspects a source, the system records every table and view it exposes, keeps which of the two each one is, and reports how many it found. |
| R6 | E | When a person inspects a source, the system records each table's columns in their declared order, with their declared type, whether they accept nothing, and their default. |
| R7 | E | When a table declares a primary key, the system records which columns form it and in what order. |
| R8 | E | When a table declares a reference to another table, the system records both sides, the columns involved, and what happens on update and delete. |
| R9 | E | When a table declares an index, the system records its columns and whether it requires uniqueness. |
| R10 | E | When a source reports a table's size or row estimate, the system records it and reports when it was measured. |
| R11 | E | When a source or a person has described a table or column in the database itself, the system records that description. |
| R12 | E | When a source cannot supply one of the above, the system records that it was unavailable rather than recording it as empty. |

### Re-inspecting

| ID | E/A | Requirement |
|----|-----|-------------|
| R13 | E | When a source is inspected again, every table and column that still exists keeps the identity it had before. |
| R14 | E | When a table or column is no longer in the source, the system marks it absent, keeps its record, and reports it as absent. |
| R15 | E | When a table or column that was absent reappears, the system restores it to present and keeps everything attached to it. |
| R16 | E | When a source is inspected again, the system reports what was added, what changed, and what became absent since the previous inspection. |
| R17 | E | When an inspection fails partway, the system keeps what it read, reports where it stopped, and leaves the previous record intact for everything it did not reach. |

### Keeping notes

| ID | E/A | Requirement |
|----|-----|-------------|
| R18 | E | When a person writes a note about a table or a column, the system keeps it. |
| R19 | E | When the source is inspected again, every note a person wrote is still attached to the same table or column. |
| R20 | E | When a person's note refers to something now absent, the system keeps the note and shows that its subject is absent. |

### Looking at what was recorded

| ID | E/A | Requirement |
|----|-----|-------------|
| R21 | E | When a person asks what a source contains, the system reports its tables with their column counts and when each was last seen. |
| R22 | E | When a person asks about one table, the system reports its columns, keys, indexes, descriptions, notes, and what it references. |
| R23 | E | When a person asks what refers to a table, the system reports every table that references it. |
| R24 | E | When a person searches by name, the system reports matching tables and columns across every source and says which source each came from. |
| R25 | E | When two sources contain a table of the same name, the system keeps and reports them separately. |
| R26 | E | When a person exports the catalog, the system writes it in a form that can be read without running Tlon. |

### Structure

| ID | E/A | Requirement |
|----|-----|-------------|
| R27 | A | When a person uses Tlon, screens and addresses come from an application, and everything else comes from a package that application depends on. User-demanded: "the display and routing logic need to be in an app and the rest should be in a package". |
| R28 | A | When a new kind of source is added, the system exposes it through the same parent contract as every existing kind. User-demanded: "each type of thing (ex CatalogSource, SchemaSource, etc) has a parent Source with SourceInterface etc". |
| R29 | A | When a kind of source needs behavior beyond the shared contract, the system supplies that behavior as separate collaborating parts rather than by specializing the parent. User-demanded: "we want to prioritize composition when things get complex especially in downstream ingestion". |
| R30 | A | When a person registers a source, the engines available are SQLite, MySQL, and PostgreSQL. |

### Environment

| ID | E/A | Requirement |
|----|-----|-------------|
| R31 | E | When the system is restarted, everything previously recorded is still present. |
| R32 | E | When a person inspects a source, the system reads from it and writes nothing to it. |
| R33 | A | When a fresh copy is prepared, it can be populated and browsed with no network access. |
| R34 | A | When a source holds more tables or columns than fit in memory at once, the system inspects it without holding them all. |

## Out of scope

- **The semantic layer.** Authored entities, dimensions, metrics, relationships, and vocabulary. v2.
- **The evidence chain.** Requests, normalized questions, required concepts, verified queries, evaluation cases. v2.
- **The compiler.** Definition objects, validators, renderers, artifacts, content fingerprints. v2.
- **Non-relational sources.** Warehouses, object stores, CRMs, ticket systems, spreadsheets, files.
- **Query history.** What a database actually ran, who ran it, how often, and what it touched.
- **Comparing two sources.** Drift between environments is an obvious next feature and is deliberately not in v1.
- **Writing to a source.** No migrations, no repairs, no suggested changes applied.
- **Inferring relationships.** v1 records references the database declares. It does not guess them from naming or usage.
- **Accounts and permissions.** No sign-in, no users, no roles.
- **Scheduled inspection.** A person triggers it.

## Assumptions

Interpretations chosen where the request did not say. Each is a veto point.

- **The application is a web application and the package is a library it installs.** R27 says display and routing in one and the rest in the other; this reads that as a conventional application-plus-library split in one language, with the package holding sources, inspection, the catalog, notes, and export.
- **The package is usable without the application.** Otherwise the split records nothing and prevents nothing.
- **Identity is the name.** A table is the same table across inspections when its name matches within its source. Renames therefore read as one table absent and another added, and reattaching notes after a rename is a person's decision, not an inference.
- **Absent is not deleted.** R14 marks rather than removes, so that notes survive. Deliberate deletion by a person is not specified here.
- **One person at a time.** No concurrent editing, no conflict resolution.
- **Notes are free text.** No structure, no types, no vocabulary. Structure arrives with the semantic layer in v2.
- **Three engines is the boundary test.** Three exists rather than one so that the shared contract is proven by more than a single case, and so dialect assumptions surface in v1 rather than v2.
- **SQLite is both an engine and the default place records are kept.** The two uses are unrelated and must not be conflated in the design.
- **Nothing is ported.** Where a prior implementation solved one of these requirements, the requirement is implemented afresh. Prior code is a reference for what problems exist, not a source of code.

## Vocabulary

- **source**: one registered relational database that Tlon reads.
- **engine**: which relational system a source is, which determines how its schema is read.
- **reach**: to successfully connect to a source.
- **inspect**: to read a source's schema and record what it contains.
- **inspection**: one act of inspecting, and what it found.
- **table**: one observed table in a source.
- **view**: one observed view in a source, recorded alongside tables and distinguished from them.
- **column**: one observed column belonging to a table, in its declared position.
- **type**: the column's declared type, as the source declares it.
- **primary key**: the ordered columns a table declares as identifying its rows.
- **reference**: a declared relationship from columns in one table to columns in another.
- **index**: a declared index on a table's columns, with or without a uniqueness requirement.
- **row estimate**: the source's own approximation of how many rows a table holds.
- **description**: text describing a table or column that came from the source itself.
- **note**: text about a table or column written by a person inside Tlon.
- **present**: seen in the most recent inspection.
- **absent**: not seen in the most recent inspection, and kept anyway.
- **change report**: what one inspection found added, altered, or newly absent compared with the one before it.
- **catalog**: everything Tlon has recorded about every source.
- **export**: a written form of the catalog readable without Tlon.
