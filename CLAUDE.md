# EAJ AI Dev Studio

Product spec: `EAJ_AI_Dev_Studio_Master_Plan.md` (large — search it by section number, e.g. "§48.6"). Decisions that deviate from it: `docs/decisions.md`.

## Stack

Laravel 12 + Inertia 2 + React 19 + TypeScript + Tailwind 4 (shadcn primitives). Local: Windows, XAMPP PHP 8.2, MariaDB 10.4 (`eaj_dev_studio`), database queue/cache. Tests run on in-memory SQLite.

## Commands

- `composer dev` — server, queue workers (default, `realtime`, `agents` on the `database-agents` connection), scheduler, Reverb, Vite
- `php artisan test` — PHP tests (PHPUnit-style classes, run by Pest)
- `php vendor/bin/pint` — PHP formatter
- `npx tsc --noEmit`, `npx eslint resources/js`, `npx prettier --write resources/`, `npm run build`
- `php artisan eaj:super-admin {email}` — grant Super Admin (console only)
- `cd runner && npm test` / `npm run typecheck` — EAJ Local Runner tests (see `runner/README.md`)

## Rules that are easy to break

- **No fake activity (§2.1).** UI state comes from persisted `outbox_events`; write them only via `App\Reliability\EventRecorder`, inside the same transaction as the state change.
- **Workflow state changes only through `App\Workflow\WorkflowEngine`** (row locks + `version` check). Never update `workflows`/`workflow_steps` status directly.
- **Agent runs only through `App\Agents\AgentRunService`** (start/report/recordTokens/fail/complete/pause/resume/cancel). Agent states follow §32 (`App\Enums\AgentStatus`); never set `agents.status` directly — see ADR-011.
- **Task changes only through `App\Tasks\TaskService`** (state machine §31, versions, events, audit). Agent-work states are `actor: 'system'` only — see ADR-009.
- **Tenant scoping (§75).** Start queries from `app(CurrentOrganization::class)->get()`; `{project}` route binding is already org-scoped. Cross-tenant access must 404. Add a cross-tenant test for every new tenant-owned resource.
- **Authorization checks permissions, not roles** (`App\Enums\Permission`, mapped in `OrganizationRole::permissions()`).
- **Colours only from `resources/css/tokens.css`** — use token utilities (`bg-navy-800`, `text-brand-text`, `text-success`…), never hex values in components.
- **Motion only for real work (§7.6).** Loop animations get `data-motion="loop"` so reduced-motion and hidden-tab rules apply.
- **Realtime is a hint, the outbox is truth (§48.6).** Reverb signals clients to replay persisted rows from `/api/events`; never advance UI state from an unpersisted socket payload. Local Windows uses the database queue; production Linux uses Redis + Horizon.
- **Status is never colour alone** — icon + label (`StatusPill`).
- **`NOT NULL` timestamp columns need `->useCurrent()` or `->nullable()`** — otherwise MariaDB 10.4 silently adds `ON UPDATE CURRENT_TIMESTAMP` (ADR-012).
- Only add sidebar items for sections that exist (`resources/js/lib/navigation.ts`).
- **Only the Local Runner touches project folders and Git** (§89.2). The server sends instructions through `App\Runner\RunnerGateway` (persisted, signed, leased); never shell out to a project from PHP. New runner capabilities need a handler in `runner/src/runner.js` **and** a local safety rule.
- **Secrets (§16):** runner results pass through `App\Security\SecretRedactor` before storage; do the same for anything later sent to an AI provider.
- **AI calls only through `App\AI\ProviderExecutor`** (via `SpecialistRunner` / `StructuredOutputService`): it enforces Stop AI, circuit breaker, retry/fallback, idempotent `ai_provider_calls` and token budgets. Agent output is schema-validated and claims without tool evidence are stripped (ADR-013). New AI work = a workflow step handler, not a raw job.
- Step names and workflow types contain dots — look them up with `config('eaj.step_handlers')[$name]`, never `config("eaj.step_handlers.{$name}")`.
- After `npm run build` fails or is interrupted, delete `public/build` and rebuild — a half-written build once left zero-byte chunks that broke page loads.
