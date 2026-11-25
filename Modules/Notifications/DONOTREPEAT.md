<!-- DON'T REPEAT YOURSELF -->

# DON'T REPEAT YOURSELF

This module follows one rule: **one responsibility, one authoritative implementation**. Reuse existing Actions, Query Services, Policies, and Resources before adding another implementation.

## Module rule

Centralize notification dispatch and payload construction. Do not create a separate notification query in each feature. Paginated notification lists should eager-load only required actor/resource metadata and avoid relation queries inside Resources.

## Project-wide query rules

- Controllers validate/coordinate HTTP input and return the response; they do not build Eloquent queries.
- Query Services/Query Objects own reusable filters, eager loading, sorting, pagination, and aggregate projections.
- Load relationships before serialization. Resources must not trigger database queries.
- Prefer `with`, `withCount`, `withSum`, `withAvg`, grouped aggregates, and bounded pagination over per-record queries or PHP loops.
- Do not cache mutable, permission-scoped collections without a complete cache key and invalidation path. Redis is used for the Spatie Roles/Permissions cache; do not add a second permission cache.
- Keep transactions and cross-module side effects in Actions, not in Controllers or Resources.
