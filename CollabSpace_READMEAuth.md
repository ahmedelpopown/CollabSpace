# CollabSpace AI

**A modular, API-first backend foundation for a single-organization collaboration platform.**

CollabSpace AI brings the core parts of team collaboration into one backend: teams, projects, tasks, files, chat, meetings, notifications, reporting, audit trails, and AI access policies. The system is designed as a **modular monolith**, keeping each business domain independent while allowing related domains to collaborate through explicit relationships and authorization rules.

## Engineering Approach

- **Modular architecture:** Domain responsibilities are separated into focused HMVC modules, each owning its models, migrations, factories, seeders, and related application code.
- **Explicit access boundaries:** Team membership, assigning a team to a project, and project membership are separate concepts. Access is granted deliberately, not inherited implicitly.
- **Layered authorization:** Role-based permissions are combined with resource-level checks, so having a role alone does not automatically grant access to every project or resource.
- **AI data governance:** AI access follows a default-deny policy based on role, resource, action, and scope. Data queries are constrained to the scope the user is allowed to access.
- **Secure authentication:** Laravel Sanctum manages expiring and revocable API tokens. The identity module supports password authentication, password reset, and Google OAuth.
- **Traceability:** Sensitive operations and AI access decisions are designed to leave an audit trail.

## Domain Modules

`Identity` · `AccessControl` · `Teams` · `Projects` · `Tasks` · `Files` · `Chat` · `Meetings` · `Notifications` · `AI` · `Reports` · `Audit`

## Tech Stack

- PHP 8.2+ and Laravel 12
- MySQL 8 with InnoDB
- Laravel Sanctum for API authentication
- Spatie Laravel Permission for RBAC
- Laravel Socialite for Google OAuth
- `nwidart/laravel-modules` for module organization
- Pest 3 and PHPUnit 11 for testing

## Getting Started

1. Install the dependencies and create your environment file:

   ```bash
   composer install
   cp .env.example .env
   php artisan key:generate
   ```

2. Configure your database connection in `.env`. Add the Google OAuth credentials when Google sign-in is required. Never commit `.env`, OAuth secrets, or database credentials to Git.

3. Build the module autoloader, migrate the database, and seed the initial roles and AI access policies:

   ```bash
   composer dump-autoload
   composer check:module-autoload
   php artisan migrate --seed
   ```

4. Run the test suite:

   ```bash
   php artisan test
   ```

> Use a disposable development or test database when running destructive migration commands such as `migrate:fresh`.

## Current Scope

The repository currently focuses on the backend foundation: domain schema, Eloquent models, module structure, access-control rules, seed data, and Identity API endpoints. The remaining domain APIs and integration with an external AI provider are separate implementation steps.

---

*Built with an emphasis on clear domain boundaries, explicit authorization, and maintainable backend architecture.*
