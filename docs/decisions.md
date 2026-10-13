# Architecture decisions

Decisions taken while implementing `EAJ_AI_Dev_Studio_Master_Plan.md`. Plan rule 106.21: any change that weakens a reliability guarantee (G1–G7) needs an entry here first.

## ADR-001 — Generic `workflows` instead of `task_workflows` (2026-10-11)

The plan names `task_workflows`. The engine uses a `workflows` table with a polymorphic `subject` (task, deployment, …) so Phase 3 tasks and Phase 11 deployments share one durable engine (`App\Workflow\WorkflowEngine`). Guarantees unchanged.

## ADR-002 — Gapless per-stream sequences via `event_streams` (2026-10-11)

Outbox sequences (§48.6) are allocated from a locked counter row per stream (`project.{id}`, `organization.{id}`, `system`) inside the same transaction as the state change. A rolled-back change consumes no sequence number, so clients can treat a gap as "missed events" and replay from the catch-up API.

## ADR-003 — Realtime transport: HTTP catch-up first, Reverb in Phase 5 (2026-10-11)

Until Reverb is installed (Phase 5) the UI polls `GET /api/events?after={id}` every 4 s while visible. This is the plan's own degraded path (§48.10 "UI polls catch-up API"), so G6 holds: everything shown is a persisted event. The publisher already broadcasts `OutboxEventBroadcast` on private `organization.{id}` / `project.{id}` channels; Phase 5 only switches the broadcast driver and adds the socket client.

## ADR-004 — Local development on Windows: database queue, no Horizon (2026-10-11)

Horizon and `pail` require `pcntl`, which PHP on Windows does not provide. Locally: `QUEUE_CONNECTION=database`, cache on the database, MariaDB 10.4 (XAMPP). Production (Linux VPS, §90) uses Redis + Horizon as planned. Workflow retries live in the engine, not the queue (`$tries = 1`), so behaviour is identical on both drivers.

## ADR-005 — Emergency stop scope and permissions (2026-10-11)

Two scopes: organization (Owner / Project Manager / Developer may stop; only Owner may resume) and global (Super Admin only). A stop pauses every PENDING/RUNNING workflow in scope and remembers which; resume restores exactly those, unless another stop still covers them, in which case they are handed to that stop. Super Admin is granted only from the console (`php artisan eaj:super-admin email`).

## ADR-006 — Stand-in vector monogram (2026-10-11)

`eaj-primary.png` was not available, so the EA monogram is a geometric stand-in drawn in the logo's style. Replace the paths listed in `resources/brand/README.md` when the official vector redraw exists.

## ADR-007 — EAJ Local Runner: dependency-free Node, HTTPS pull, signed commands (2026-10-13)

The runner (`runner/`) is plain Node.js (JSDoc-typed, strict `tsc` check) with no npm dependencies: Node is present on every supported stack (Laravel with Vite/Mix, React, Next.js), PHP is not. Until Reverb (Phase 5) it **polls** `/api/runner/v1/commands/claim` every 2 s and heartbeats every 15 s. Commands are persisted first, HMAC-signed at claim time with a per-runner secret (stored encrypted), and leased; an expired lease re-offers the command (max 3 attempts), and the runner answers re-deliveries from its own outcome record (G4). Safety rules (allowed folders, Git allow-list, secret redaction) are enforced on the PC and repeated server-side (`SecretRedactor`), so neither side alone can leak secrets or widen access.

## ADR-008 — Safety stash without touching the working tree (2026-10-13)

Plan §13 asks that uncommitted changes in the original folder are "stashed into a named safety stash" before a worktree is created. `git stash push` would remove the developer's in-progress edits from their editor, so the runner uses `git stash create` + `git stash store -m "eaj-safety: <task>"`: the snapshot is recorded in the stash list while the working tree stays exactly as it was. Worktrees start from the last commit.

## ADR-009 — Agent-work task states are system-only (2026-10-13)

Plan §31 lists the task states; §2.6 G6 says a status must never claim work that is not happening. People may triage (Backlog ↔ Ready), block with a reason (and unblock back to the previous state — or to Ready when the task was blocked out of an agent-work state, since nothing would be running; amended in Phase 6), cancel/reopen, request changes after a review, and approve a release-ready task (`tasks.approve`). Planning, In Development, Testing, Senior Review, Security Review and Release Ready can only be entered by the workflow (`actor: system`, Phase 4). Board moves carry the status the user saw; a stale board gets a conflict instead of overwriting (`App\Tasks\TaskStateMachine`, `TaskService`).

## ADR-010 — Deterministic change-impact analysis before AI (2026-10-13)

Plan §67 asks for impact analysis before work begins; §2.2 says deterministic tools come before AI. Until the Orchestrator exists (Phase 6), `App\Tasks\ImpactAnalyzer` matches the request's terms against the code index: a file's best symbol match counts in full and further matches add a diminishing share (so catch-all files such as `routes/web.php` do not dominate), files matching more of the request's terms rank higher, and only files scoring ≥ 25% of the best match are kept. Areas, recommended agents (§7.5 roles), complexity and a token estimate are derived from that, each with its evidence. Cost is shown only once model pricing exists. Phase 6 uses the result as the context builder's starting point and lets the architect refine it.

## ADR-011 — Agent Engine without a provider: runs need a model and a stop-free organization (2026-10-13)

Phase 4 persists the AI team (12 roles from §10, one agent per role per organization, versioned skills §37–38), provider-neutral model routing (tiers §45; model names only in `ai_models` data, never in code), token budgets by run/task/agent/project/organization and day/month (§47), and append-only run evidence (`agent_runs`, sequence-numbered `agent_events`, exactly-once `token_usage`). `App\Agents\AgentRunService` is the only writer. Guarantees: a run is refused while operations are stopped (§57) or when no model is routed for it (a "working" agent with nothing to execute it would break G6); budgets are hard stops enforced in code (G5); a failed attempt retries on the route's fallback model until `retry_limit`, then the run and task are blocked with a reason (G7); runs with no heartbeat for the fail window count as failed attempts (§48.5). Agent states follow §32 exactly; executors report activity (Reading, Testing, Fixing…) through `report()`, which emits `agent.status.changed` events that drive the War Room animation in Phase 5. The emergency stop pauses active runs and Resume restores only those it paused. Provider adapters that actually call models arrive in Phase 6.

## ADR-012 — Reverb fast path with durable catch-up; Redis/Horizon production topology (2026-10-13)

Phase 5 adds Reverb as a low-latency notification path, but a socket payload never becomes client state directly. It triggers a replay of persisted `outbox_events` after the client's durable cursor; periodic reconciliation and visibility/online recovery use the same endpoint. Private organization and project channels repeat HTTP membership and permission checks. This keeps G6 and preserves order across disconnects, duplicate delivery, and the race between a socket signal and an in-flight catch-up request.

The supported Windows development topology remains the database queue/cache because Horizon requires the Unix-only `pcntl` and `posix` extensions. The repository includes Predis, Reverb, and Horizon configuration; production Linux uses Redis for queue/cache, enables Reverb scaling, and supervises both Horizon and Reverb. Agent heartbeat recovery is scheduler-owned in both environments: 90 seconds is visibly stalled and 5 minutes consumes a retry attempt, eventually blocking the task instead of silently disappearing (G7).

Outbox publishing runs on its own `realtime` queue (a single-process Horizon supervisor in production; a dedicated `queue:work --queue=realtime` in `composer dev`) so live updates never wait behind long agent or AI jobs on `default`. A publisher that finds the ordering lock taken waits for it rather than skipping, because the holder may have queried before the newer event committed; the 10-second sweep is only a backstop. Measured locally on the database queue (10 trials, real Reverb), an agent state change starts showing on the War Room card after a median 0.5 s and at most 1.4 s; the tail is queue polling (`--sleep=1`), which Redis removes. On Windows `REVERB_HOST` must be `127.0.0.1`, not `localhost`: the IPv6-first attempt added ~200 ms to every server-side broadcast. The War Room folds `agent.status.changed` events into its cards as soon as catch-up returns them, then reconciles with a debounced Inertia reload.

MariaDB 10.4 defaults `explicit_defaults_for_timestamp` to OFF, which silently gave the first `NOT NULL` timestamp of four tables `ON UPDATE CURRENT_TIMESTAMP` and rewrote `outbox_events.occurred_at` on every publish. Migration `2026_10_13_000500` gives those columns an explicit default, and the MySQL/MariaDB connections pin the session time zone to `+00:00` to match Laravel's UTC. New `NOT NULL` timestamp columns need `->useCurrent()` or `->nullable()` so MariaDB does not add the clause again.

The realtime catch-up routes (`/api/events…`) run with `App\Http\Middleware\ReadOnlySession`: they authenticate from the session but never write it back. The database session driver has no locking, so a poll that loaded the session before a form post finished used to save its stale copy over the post's flash message ("AI planning started…" never appeared).

## ADR-013 — AI integration: orchestrated planning on the workflow engine (2026-10-13)

Phase 6 (§2.3, §42, §48.7, §81, §82). `App\AI\Orchestrator` hands a **Ready** task to the AI team: `SpecialistRouter` activates only the roles the impact analysis recommends, in dependency order, and records why any were skipped (no model routed, disabled, or a reviewer — SR/SE wait for a diff in Phase 8). Each specialist is one durable workflow step (`agent.specialist`, handled by `RunSpecialistStep` → `SpecialistRunner`, the `AgentRunnerInterface` implementation), so retries, Stop AI, stall recovery and checkpoints come from the existing engine. A step's run is keyed `workflow-step:{id}:run:{n}`: re-entry resumes it and replays completed provider calls instead of re-billing; an owner resuming a blocked plan gets a fresh run, the failed one kept as evidence.

Provider calls go only through `ProviderExecutor`: per-provider circuit breaker (5 consecutive or >50% failures in 2 min → open 60 s), one retry for timeouts/429/5xx honouring `retry-after`, then the route's fallback model (recorded in the audit log and as an agent event), every attempt persisted in `ai_provider_calls` with an idempotency key. Before every call it re-checks Stop AI and that the run is still running. Long calls heartbeat through cURL's progress callback so they are not mistaken for stalls. Output is validated against the prompt version's JSON schema with one repair request; a second failure fails the step and nothing is applied. Context comes from `ContextBuilder` (task, impact, index symbols, instructions, the run's recorded skill versions) and passes through `SecretRedactor`; the run records its prompt version, and `PromptCatalog::activate()` rolls back.

Phase 6 gives agents no repository, write or test tools, so `SpecialistRunner` deterministically removes any claim of files read/modified or tests run and says so in the result's warnings (§48.8, G6). Planning therefore ends without implementation: the task goes Planning → **Ready** (a new system-only edge) with the plan attached, or Blocked when a specialist needs clarification. A `WorkflowListener` keeps the task truthful when the workflow is blocked (task Blocked with the reason), cancelled (back to Ready) or resumed (back to Planning). ADR-009 is amended so a task blocked out of an agent-work state is released to Ready, never by hand straight back into Planning/In Progress with nothing running.

Workflow steps run on their own queue connection (`database-agents` locally, `redis-agents` in production, queue `agents`) whose `retry_after` (50 min) exceeds `max_step_seconds` (45 min); on the default 90 s connection a long AI call would be redelivered while still running. `/health` reports configured providers and open circuits by name only.
