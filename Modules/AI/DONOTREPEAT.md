<!-- DON'T REPEAT YOURSELF -->

# DON'T REPEAT YOURSELF

This module follows one rule: **one responsibility, one authoritative implementation**. Reuse existing Actions, Query Services, Policies, and Resources before adding another implementation.

## Module rule

Keep resource-scope rules in AIResourceAccess. Do not rebuild project, task, meeting, file, or team visibility predicates in prompts, controllers, or individual tools. Reuse the same scoped query before retrieval and before returning results. Cache policy definitions only through the configured Redis permission/policy cache after invalidation is defined; never cache user-specific AI results without user, project, permission-version, and policy-version scope.

## Project-wide query rules

- Controllers validate/coordinate HTTP input and return the response; they do not build Eloquent queries.
- Query Services/Query Objects own reusable filters, eager loading, sorting, pagination, and aggregate projections.
- Load relationships before serialization. Resources must not trigger database queries.
- Prefer `with`, `withCount`, `withSum`, `withAvg`, grouped aggregates, and bounded pagination over per-record queries or PHP loops.
- Do not cache mutable, permission-scoped collections without a complete cache key and invalidation path. Redis is used for the Spatie Roles/Permissions cache; do not add a second permission cache.
- Keep transactions and cross-module side effects in Actions, not in Controllers or Resources.
