# tlon

> **License:** Copyright © 2026 Sifrious. All rights reserved. This is
> publicly viewable proprietary software, not open-source software. See
> [LICENSE.md](LICENSE.md).

Reveal schemas, queries, routes, relationships, dependencies, and dataflow.

Portable package in the sifrious portfolio.

## Role

**Should own:** Reveal schemas, queries, routes, relationships, dependencies, and dataflow.

## Status

Operational package/application seam with persistent source registration, relational-schema inspection, and analyzer-neutral code-symbol/reference contracts.

Run `php tests/run.php`, `bash tests/e2e.sh`, `bash tests/package-seam.sh`, `php tests/code.php`, and `php tests/analyzers.php` to verify the package contracts and application boundary. `demo-app` installs the root library through a Composer path repository and owns its browser route. The analyzer suite exercises real PHP and TypeScript source adapters and a durable JSON graph round trip.

Run a durable code inspection with `bin/tlon-code REPOSITORY_ID INSPECTION_ID STATE_JSON FILE...`.
