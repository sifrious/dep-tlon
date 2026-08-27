# ADR-001: Tlon owns the canonical glossary registry

**Status:** Accepted  
**Date:** 2026-08-27  
**Tickets:** TLG-001–TLG-010  
**Reversibility:** B  
**Structured-memory relation:** feeds

## Context

Project vocabulary currently lives in separate Obsidian glossary notes with inconsistent shapes and no executable naming contract. Tlon already reserves its later semantic layer for authored entities, relationships, and vocabulary, while Menard prepares replaceable retrieval projections. The system needs one authority for accepted names and definitions without allowing inferred terminology or a search index to become canonical. Tlon v1 remains limited to relational-schema inspection, so this capability must not enter the v1 implementation path.

## Options

### A: Tlon registry with Menard retrieval projections

Tlon owns accepted project-scoped definitions, aliases, architectural names, lifecycle state, and provenance. Menard indexes exported glossary entries for retrieval. Kilgore may propose entries but cannot accept them. This preserves the existing project boundaries and keeps the canonical representation independent of retrieval machinery.

### B: Menard owns the glossary

Menard already has a planned `Term` projection and supplies context packs. Giving it canonical definitions would combine authored semantic authority with replaceable retrieval data and would make changing an index capable of changing project language.

## Decision

Choose option A. Tlon will own a version-controlled Glossary Registry in its semantic layer. Menard's `Term` remains a derived retrieval projection. Funes preserves supporting evidence, Aleph discovers glossary sources, and Kilgore may produce evidence-bearing proposals. Existing Obsidian notes remain authoritative until each scope is reconciled explicitly; afterward they become human-readable projections refreshed from the accepted registry.

The capability name is **Glossary Registry**. Product prose uses **Tlon**; commands and package identifiers use **`tlon`**. The implementation begins as a module in the Tlon package rather than a separately distributed package.

## Consequences

- Easier: one accepted name per scope, explicit aliases and renames, deterministic agent context, reproducible Obsidian notes, and replaceable retrieval indexes.
- Harder: importing existing notes requires conflict resolution, cross-project terms require explicit scope, and failed note projection must be reported as stale state rather than ignored.
- Tlon v1 receives no glossary code or requirements.
- **Revisit trigger:** reconsider the module boundary only after a second application needs to author glossary entries without depending on Tlon, or when independent release cadence becomes operationally necessary.

