<!-- DON'T REPEAT YOURSELF -->

# DON'T REPEAT YOURSELF

This module follows one rule: **one responsibility, one authoritative implementation**. Reuse existing Actions, Query Services, Policies, and Resources before adding another implementation.

## Module rule

Use AccessTokenService/IssueAccessTokenAction for token issuance and revocation, RecordAuthenticationLogAction for authentication logs, and shared User Resources for user summaries. Do not repeat password hashing, Google profile mapping, role lookup, or token expiry logic across controllers.

## Project-wide query rules

- Controllers validate/coordinate HTTP input and return the response; they do not build Eloquent queries.
- Query Services/Query Objects own reusable filters, eager loading, sorting, pagination, and aggregate projections.
- Load relationships before serialization. Resources must not trigger database queries.
- Prefer `with`, `withCount`, `withSum`, `withAvg`, grouped aggregates, and bounded pagination over per-record queries or PHP loops.
- Do not cache mutable, permission-scoped collections without a complete cache key and invalidation path. Redis is used for the Spatie Roles/Permissions cache; do not add a second permission cache.
- Keep transactions and cross-module side effects in Actions, not in Controllers or Resources.
