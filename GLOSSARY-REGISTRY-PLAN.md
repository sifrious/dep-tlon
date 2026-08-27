# Tlon Glossary Registry implementation plan

**Status:** CASHED OUT → ADR-001, TLG-001–TLG-010, Obsidian plan and glossary seed — 2026-08-27  
**Execution position:** Tlon semantic layer; excluded from Tlon v1  
**Sizing assumption:** one focused session is approximately two hours; split a ticket again before implementation if its first observable outcome will not fit.  
**Named deliverable:** a canonical, project-scoped glossary and architecture-naming registry with deterministic Obsidian projections.

## Outcome

Tlon provides one executable contract for accepted vocabulary and architectural names. It can import current project glossaries, identify conflicts, expose deterministic context to agents, validate names used by registered modules and documents, and refresh managed Obsidian notes whenever accepted registry content changes.

## Ownership boundary

| Project | Responsibility |
|---|---|
| Tlon | Accepted glossary entries, project scopes, architecture names, aliases, lifecycle, provenance, validation, and Obsidian projection |
| Aleph | Discover and ingest glossary source material |
| Funes | Preserve source evidence and change history |
| Kilgore | Propose inferred terms, definitions, and relationships with evidence |
| Menard | Build replaceable retrieval projections and context packs from accepted exports |
| Obsidian vault | Human-readable project projections; initially import sources, then generated views after reconciliation |

An inferred term never becomes accepted automatically. A retrieval projection never becomes authoritative. A failed Obsidian refresh never passes silently.

## Naming contract

| Kind | Canonical name | Rule |
|---|---|---|
| Product | Tlon | Capitalized in prose |
| CLI and package identifier | `tlon` | Lowercase in commands, paths, and Composer identifiers |
| Capability | Glossary Registry | Do not alternate with vocabulary service, terminology store, or central glossary |
| PHP module | `Tlon\Glossary` | Lives inside the Tlon package until the ADR revisit trigger fires |
| Scope record | `GlossaryScope` | Identifies the project or domain in which a definition applies |
| Definition record | `GlossaryEntry` | Accepted or proposed meaning within one scope |
| Written form | `TermLabel` | Preferred label or alias belonging to an entry |
| Architecture identity | `ArchitectureName` | Canonical human and machine names for a project, module, package, command, model, event, or capability |
| Evidence link | `ProvenanceReference` | Stable reference supporting an entry or naming decision |
| Obsidian output | `ObsidianGlossaryProjection` | Replaceable rendered view, never the post-migration authority |
| Retrieval output | Menard `Term` | Derived projection; do not reuse this name for Tlon's canonical record |

Stable identity is not the visible term text. A term is resolved through its scope and stable entry identifier, so `Kilgore:decision` and `Titan:decision` may differ without collision.

## Planned command surface

```text
tlon glossary import
tlon glossary lint
tlon glossary list
tlon glossary show
tlon glossary context
tlon glossary define
tlon glossary rename
tlon glossary alias
tlon glossary deprecate
tlon glossary notes refresh
tlon glossary notes check
```

Mutation commands must preview the semantic and projection diff, record provenance, update the registry, and refresh affected Obsidian projections. If projection fails, the command exits unsuccessfully and records which scopes are stale.

## Definition of done for every ticket

These criteria apply in addition to each ticket's specific acceptance criteria.

1. Any new project, module, capability, record, command, event, or package name introduced by the ticket is either registered as an `ArchitectureName` or explicitly documented as outside the registry's supported surfaces.
2. Names in code, tests, commands, plan documents, and affected Obsidian notes use the canonical spelling and casing.
3. Before TLG-007, affected Obsidian planning and glossary notes are synchronized manually. From TLG-007 onward, `tlon glossary notes check` reports no stale affected projection.
4. No generated process overwrites human-authored text outside a managed projection boundary.
5. The ticket ends with observable evidence named in its acceptance criteria and leaves no undocumented naming decision.

## Tickets

### TLG-001 — Establish the glossary and naming boundary

**Depends on:** ADR-001  
**First action:** add the naming-contract fixture containing Tlon, Kilgore, Menard, Glossary Registry, and one deliberately invalid spelling.  
**Outcome:** the repository contains the normative ownership, lifecycle, naming, and authority rules that every later ticket implements.

**Deliverables**

- Glossary Registry boundary document linked from the Tlon plan index.
- Normative names and prohibited synonyms from this plan represented as a machine-readable fixture.
- Explicit transition rule for each Obsidian scope: `import source → reconciled → generated projection`.

**Acceptance criteria**

1. The boundary names exactly one authority for accepted definitions and separately names discovery, evidence, inference, retrieval, and projection responsibilities.
2. `Glossary Registry`, `GlossaryScope`, `GlossaryEntry`, `TermLabel`, `ArchitectureName`, `ProvenanceReference`, and `ObsidianGlossaryProjection` each have one normative definition.
3. The fixture rejects `tlos`, `TLoN`, and `central glossary service` where the canonical Tlon names are required.
4. Tlon v1's specification and state remain unchanged.
5. The Tlon Obsidian plan and glossary contain the same boundary and canonical spellings.

**Fence:** no database schema, parser, CLI implementation, or migration of existing glossaries.

### TLG-002 — Model the essential registry state

**Depends on:** TLG-001  
**First action:** write one state example where `decision` has different accepted definitions in Kilgore and Titan.  
**Outcome:** a persistence-neutral model represents scoped definitions, preferred labels, aliases, architectural names, provenance, lifecycle, and supersession.

**Deliverables**

- Essential-state document and executable model tests.
- Serialization format for the version-controlled canonical registry.
- Fixtures for ambiguity, alias collision, rename, deprecation, supersession, and evidence-bearing proposal.

**Acceptance criteria**

1. Two scopes can use the same label with different definitions without sharing identity.
2. Within one scope, exactly one preferred `TermLabel` exists for an accepted `GlossaryEntry`.
3. An alias collision within one scope fails validation and identifies both entries.
4. Proposed or inferred entries cannot be exported as accepted agent context.
5. Rename and supersession preserve the old label and provenance without rewriting history.
6. Serialization is deterministic: identical state produces byte-identical output.
7. Model names and the matching Obsidian state summary satisfy the global definition of done.

**Fence:** no Eloquent dependency, search index, embeddings, or Obsidian writer.

### TLG-003 — Discover and import existing glossary sources

**Depends on:** TLG-002  
**First action:** enumerate every known project glossary file and classify its current shape without changing it.  
**Outcome:** a read-only importer converts existing glossary notes into proposed registry changes with explicit conflicts.

**Deliverables**

- Configured glossary-source discovery.
- Markdown reader adapters for the shapes presently used by Tlon, Kilgore, Funes, and the standard project glossaries.
- Dry-run import report with source path, fingerprint, proposed entries, unresolved text, and conflicts.

**Acceptance criteria**

1. Discovery reports every configured glossary source exactly once and gives it a stable scope identifier.
2. Import never edits the source note.
3. Every proposed entry retains its source path and a reproducible content fingerprint.
4. Ambiguous headings, duplicate labels, and missing definitions appear as conflicts rather than accepted entries.
5. Re-importing unchanged sources produces an empty semantic diff.
6. A fixture containing human prose outside glossary entries survives import unmodified.
7. The Obsidian glossary-source inventory is refreshed and uses registered project names.

**Fence:** no automatic conflict resolution, no accepted migration, and no inferred definitions.

### TLG-004 — Expose deterministic registry queries and agent context

**Depends on:** TLG-002  
**First action:** write the expected output for `tlon glossary show decision --scope=kilgore`.  
**Outcome:** callers can list, resolve, inspect, and export accepted vocabulary without knowing the storage format.

**Deliverables**

- Persistence-neutral query API.
- `list`, `show`, and `context` CLI commands.
- Deterministic JSON and Markdown export formats.

**Acceptance criteria**

1. `show` reports preferred label, definition, scope, aliases, status, provenance, and supersession.
2. An unscoped ambiguous label fails with the candidate scopes instead of choosing one.
3. `context --scope=<scope>` includes accepted entries only and follows a stable order.
4. Repeated context export from unchanged state is byte-identical.
5. The API contains no Menard, Obsidian, database, or CLI-specific types.
6. Help text and generated examples use the registered command and record names.
7. The Tlon Obsidian plan records the supported query surface.

**Fence:** no mutation commands, semantic search, or model-generated summaries.

### TLG-005 — Lint names across registered modular architecture surfaces

**Depends on:** TLG-002, TLG-004  
**First action:** register the Tlon product, CLI, PHP module, Composer package, and Obsidian project names as one architecture subject.  
**Outcome:** Tlon detects inconsistent names and unregistered aliases across explicitly configured module manifests, documentation, command definitions, and projection files.

**Deliverables**

- `ArchitectureName` validation service.
- `tlon glossary lint` with machine-readable and human-readable reports.
- Configured surface adapters rather than unrestricted heuristic filesystem scanning.

**Acceptance criteria**

1. One architecture subject may declare distinct canonical display, machine, package, namespace, path, and command forms.
2. The linter catches a wrong form on a registered surface and reports the expected form and owning subject.
3. Approved aliases pass only on the surface types for which they are registered.
4. An unregistered architectural name is reported separately from a misspelling of a known name.
5. Lint output identifies file or manifest location and is stable enough for CI comparison.
6. The fixture catches `tlos` while accepting `Tlon`, `tlon`, and `Tlon\Glossary` in their registered contexts.
7. The affected Obsidian architecture and glossary notes pass the same naming check.

**Fence:** no automatic rewriting and no scanning of unconfigured repositories.

### TLG-006 — Add explicit glossary mutation workflows

**Depends on:** TLG-002, TLG-004, TLG-005  
**First action:** specify the preview for renaming one preferred label while retaining the old label as an alias.  
**Outcome:** authorized human actions can define, rename, alias, deprecate, and supersede entries through reviewable diffs.

**Deliverables**

- `define`, `rename`, `alias`, and `deprecate` commands.
- Semantic diff and provenance requirements for every mutation.
- Optimistic conflict detection against the registry fingerprint.

**Acceptance criteria**

1. Every mutation shows the affected scope, semantic change, architecture-name impact, and anticipated Obsidian projection changes before writing.
2. A mutation requires an authority and provenance reference; inferred proposals require explicit acceptance before mutation.
3. Concurrent mutation against a stale fingerprint fails without partial changes.
4. Rename preserves old labels and updates registered references or reports each unresolved reference.
5. Deprecation does not delete history and names a replacement when one exists.
6. A failed validation leaves the canonical registry byte-identical.
7. Until TLG-007 is complete, the command reports that note projection remains pending and the affected Obsidian notes are updated manually under the global definition of done.

**Fence:** no automatic AI acceptance, bulk migration, or arbitrary find-and-replace.

### TLG-007 — Render safe Obsidian glossary projections

**Depends on:** TLG-004, TLG-006  
**First action:** create a fixture note containing human-authored text before and after an empty managed glossary region.  
**Outcome:** accepted registry state renders deterministic, human-readable Obsidian notes without overwriting human prose.

**Deliverables**

- `ObsidianGlossaryProjection` renderer.
- Managed-region or generated-file contract.
- `tlon glossary notes refresh --dry-run` and write modes.

**Acceptance criteria**

1. The renderer updates only the declared managed region or a wholly generated file.
2. Human-authored content outside the managed boundary remains byte-identical.
3. Output contains the scope, accepted definitions, aliases, lifecycle state, provenance links, source registry fingerprint, and generation contract.
4. An unchanged registry produces no filesystem write and no timestamp-only diff.
5. A changed entry refreshes only affected scope projections.
6. Broken links, missing vault roots, or unsafe paths fail before any file changes.
7. Rendering twice produces byte-identical Obsidian content.
8. Tlon, Kilgore, and Menard fixture notes demonstrate canonical cross-project names after refresh.

**Fence:** no Obsidian plugin, graph database, or rewriting of unrelated notes.

### TLG-008 — Enforce refresh and stale-projection detection

**Depends on:** TLG-006, TLG-007  
**First action:** make a registry fixture change without refreshing its note and prove `notes check` fails.  
**Outcome:** glossary changes cannot silently diverge from Obsidian projections or registered naming surfaces.

**Deliverables**

- `tlon glossary notes check`.
- Post-mutation refresh orchestration.
- CI command covering registry validation, naming lint, and projection freshness.
- Explicit dirty-state report for projection failures.

**Acceptance criteria**

1. Every successful mutation refreshes affected Obsidian projections before reporting success.
2. If projection fails after registry persistence, the command exits unsuccessfully, records affected stale scopes, and prints the exact recovery command.
3. `notes check` fails when a registry fingerprint and projection fingerprint differ.
4. `notes check` passes without writing files when all projections are current.
5. CI can run against a fixture vault without access to the user's real vault.
6. Naming lint and projection freshness share the same canonical registry revision.
7. The operating instructions and Obsidian plan document recovery from stale projection state using the canonical command names.

**Fence:** no background daemon, filesystem watcher, scheduled job, or remote synchronization.

### TLG-009 — Reconcile the Tlon, Kilgore, and Menard pilot scopes

**Depends on:** TLG-003, TLG-006, TLG-008  
**First action:** run dry-run import for the three pilot glossaries and save the conflict report.  
**Outcome:** three architecturally different projects move from hand-maintained glossary sources to accepted registry scopes and generated Obsidian projections.

**Deliverables**

- Reviewed imports for Tlon, Kilgore, and Menard.
- Recorded decisions for conflicts, aliases, and cross-project terms.
- Generated projections replacing only explicitly migrated glossary regions.

**Acceptance criteria**

1. Every imported entry is accepted, rejected, or left proposed explicitly; no conflict disappears through normalization.
2. `Term` is documented as Menard's retrieval projection and is not used for Tlon's canonical `GlossaryEntry`.
3. Shared labels resolve correctly by scope, including at least one intentionally different definition.
4. The three Obsidian glossary projections match the accepted registry and retain human-authored material.
5. `tlon glossary lint` and `tlon glossary notes check` pass for all three scopes.
6. Agent context exports for all three scopes are deterministic and contain only accepted definitions.
7. The migration record names the registry revision and original source fingerprints.

**Fence:** no migration of other projects and no deletion of original notes or Git history.

### TLG-010 — Roll out all project scopes and prove the modular naming workflow

**Depends on:** TLG-009  
**First action:** inventory every remaining glossary scope and divide conflict-free and conflict-bearing scopes into an execution checklist.  
**Outcome:** every configured project glossary participates in the registry, naming validation, deterministic agent context, and Obsidian refresh workflow.

**Deliverables**

- Reconciled registry coverage for every configured project scope.
- Coverage and unresolved-proposal report.
- End-to-end rename demonstration across a project, module/package name, agent context export, and Obsidian projection.
- Operator documentation for adding a project, changing a name, reviewing an inferred proposal, refreshing notes, and recovering stale projections.

**Acceptance criteria**

1. Every discovered project glossary has a registered scope and explicit migration state.
2. Every accepted architectural project and module name has display and machine forms plus an owning scope.
3. A tested rename updates the canonical registry, registered module surfaces, deterministic context, and affected Obsidian notes, while preserving the former name as historical provenance or an alias.
4. `tlon glossary lint` reports no unexplained naming violations on all configured supported surfaces.
5. `tlon glossary notes check` reports no stale projections across all migrated scopes.
6. Unresolved proposals remain visible and excluded from accepted exports.
7. A clean checkout with a fixture vault can reproduce validation and projections without network access.
8. The Tlon plan index is stamped with completion evidence and links the final operating documentation.

**Fence:** no semantic search, embeddings, automatic term inference, Obsidian plugin, background watcher, or extraction into a separate Composer package.

## Sequence

```text
TLG-001
   ↓
TLG-002
   ├──→ TLG-003 ───────────────┐
   └──→ TLG-004 → TLG-005 → TLG-006
                                  ↓
                              TLG-007
                                  ↓
                              TLG-008
                                  ↓
                        TLG-003 + TLG-009
                                  ↓
                              TLG-010
```

## Deferred capabilities and revisit triggers

- **Automatic term inference:** revisit when Kilgore can emit evidence-bearing proposals against real Funes records.
- **Semantic glossary search:** revisit when exact and alias lookup fails a measured retrieval case; Menard owns the projection.
- **Filesystem watcher or scheduled refresh:** revisit when manual or mutation-triggered refresh causes at least three recorded stale-note incidents.
- **Separate `tlon/glossary` package:** revisit under ADR-001's second-consumer or independent-release trigger.
- **Obsidian plugin:** revisit only if command-generated projections cannot support a demonstrated editing or review workflow.
- **Graph database:** revisit only after relational/file projections fail a measured traversal requirement.

## Cash-out

- **Tickets:** TLG-001–TLG-010.
- **Decision:** ADR-001 accepted.
- **Vault:** Tlon Glossary Registry plan and glossary seed.
- **Residue:** automatic inference, semantic retrieval, watchers, plugins, and package extraction are explicitly deferred rather than disguised as current work.
- **Exchange rate:** one implementation plan → ten session-sized tickets, one boundary ADR, and two synchronized Obsidian artifacts.

