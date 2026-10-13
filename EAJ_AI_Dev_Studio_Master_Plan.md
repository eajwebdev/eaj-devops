# EAJ AI Dev Studio
## Master Architecture & Implementation Plan

**Company:** EAJ Web Development Services  
**Document Type:** Product Architecture / Engineering Master Plan  
**Version:** 2.0 — Brand, Motion & Reliability revision (October 2026)  
**Owner:** Edwin Abril Jr., Lead Developer  
**Primary Goal:** Build a production-ready AI software-development control center that can connect to local or remote software projects, assign work to specialized AI agents, execute development tasks safely, run deterministic validation, conduct senior-level reviews, and present all work through a modern real-time UI.

---

# 1. Product Vision

EAJ AI Dev Studio is an AI-powered software engineering operating system for EAJ Web Development Services.

It is not a chatbot with multiple AI names.

It is a visual development platform where a user can connect an existing project or create a new one, submit a development request, and watch a coordinated AI team:

1. Analyze requirements.
2. Inspect the project.
3. Understand the existing architecture.
4. Create tasks.
5. Assign specialists.
6. Modify code in isolated workspaces.
7. Run deterministic tests and build tools.
8. Perform QA.
9. Perform senior code review.
10. Perform security review.
11. Fix issues.
12. Re-run validation.
13. Present the final result for approval.
14. Merge or prepare the change for release.

The owner must always be able to see:

- Who is working.
- What they are working on.
- Current task status.
- Current file activity.
- Changes made.
- Test results.
- Review results.
- Security issues.
- Token usage.
- AI cost.
- Blockers.
- Tasks waiting for approval.
- Project health.
- Release readiness.

---

# 2. Core Principles

The entire platform must follow these principles.

## 2.1 Real Work, Not Fake Activity

Every production-mode status displayed in the UI must represent a real persisted event.

Examples:

- Reading `BookingService.php`
- Updating `ReservationController.php`
- Running Pest tests
- Running PHPUnit
- Running ESLint
- Running TypeScript checks
- Running Laravel Pint
- Running PHPStan
- Running Playwright
- Build passed
- QA failed
- Senior reviewer requested changes

Do not simulate activity unless the system is explicitly in DEMO mode.

---

## 2.2 Automated Verification Before AI Review

Use deterministic tools before using expensive AI reviewers.

Example:

```text
Developer
   ↓
Formatter
   ↓
Static Analysis
   ↓
Unit Tests
   ↓
Integration Tests
   ↓
Frontend Build
   ↓
E2E / Playwright
   ↓
AI QA
   ↓
Senior Code Review
   ↓
Security Review
```

AI should not be used to check what normal engineering tools can verify reliably.

---

## 2.3 Event-Driven Agent Architecture

Do not run all agents continuously.

Use:

```text
Orchestrator
    ↓
Relevant Specialist
    ↓
Quality Gate
    ↓
Relevant Reviewer
    ↓
Next Step
```

Only activate agents that are required for the current task.

---

## 2.4 Token Efficiency

Never send an entire codebase to a model unless absolutely necessary.

Agents should normally receive:

- Task requirements.
- Acceptance criteria.
- Relevant coding standards.
- Relevant architecture notes.
- Relevant files.
- Relevant interfaces/types.
- Current Git diff.
- Related test files.
- Current failures.
- Prior review comments.

Project knowledge should be retrieved selectively.

---

## 2.5 Safe Local Development

The default behavior must never allow an AI agent to directly modify the user's main local project folder without a controlled workspace.

Preferred model:

```text
Original Project
    ↓
Git Branch
    ↓
Git Worktree / Isolated Workspace
    ↓
AI Modification
    ↓
Tests
    ↓
Review
    ↓
Human Approval
    ↓
Merge
```

---

## 2.6 Reliability by Design — "Never Lose Work, Never Ship Unverified Code"

No software can honestly promise 100% uptime or that an AI will never make a mistake. What EAJ AI Dev Studio **can** guarantee by design is that failures are contained, visible and recoverable. These are the platform's non-negotiable guarantees:

| # | Guarantee | How it is enforced |
|---|-----------|--------------------|
| G1 | **Never corrupt the original project.** | Agents only write inside per-task Git worktrees; the original folder is read-only to agents (Section 13). |
| G2 | **Never merge unverified code.** | Merge is a server-side check that re-verifies every required gate result against the exact commit SHA being merged (Section 48.9). |
| G3 | **Never lose work after a crash.** | Every agent step is checkpointed (commit + DB state) before the next step starts; workers resume from the last checkpoint (Section 48.4). |
| G4 | **Never run twice by accident.** | Every job and tool call carries an idempotency key (Section 48.3). |
| G5 | **Never loop forever or overspend.** | Hard caps on attempts, cycles, tokens and cost, enforced outside the AI (Sections 47–50). |
| G6 | **Never show a status that isn't true.** | UI state is rebuilt from persisted, sequence-numbered events; animation is driven only by real events (Sections 7.6, 48.6). |
| G7 | **Never fail silently.** | Every failure becomes a visible event, a task state and (if needed) a notification with a clear next action. |

When something does fail, the system must degrade in this order: **retry → fall back → pause and ask the owner**. It must never guess, skip a gate, or continue in an unknown state.

---

## 2.7 Brand-First Experience

EAJ AI Dev Studio is an EAJ Web Development Services product. Every screen uses the EAJ brand palette, the EA monogram, and the EAJ motion language defined in Section 7. Client projects keep **their own** design languages (Section 22) — the EAJ brand applies only to the Studio itself.

---

# 3. Supported Project Types

EAJ AI Dev Studio must support modern and legacy-compatible web-development stacks.

The architecture must be extensible and must not assume every project uses the same framework.

---

# 4. Supported Backend Technologies

Minimum intended support:

- Laravel 7+
- Laravel 8
- Laravel 9
- Laravel 10
- Laravel 11
- Laravel 12+
- PHP 7.4+ where required by legacy Laravel projects
- PHP 8.x
- REST APIs
- JSON APIs
- Laravel Sanctum
- Laravel Passport
- Session authentication
- Queue workers
- Redis
- MySQL
- MariaDB
- PostgreSQL where project configuration requires it

The project scanner must detect framework and version before suggesting commands.

Never assume a Laravel 7 project supports the same commands, syntax, packages, or PHP version as a modern Laravel project.

---

# 5. Supported Frontend Technologies

Minimum intended support:

- React
- React + Vite
- React + TypeScript
- Next.js App Router
- Next.js Pages Router
- Next.js + TypeScript
- Vue 2
- Vue 3
- Laravel Blade
- Laravel Blade + Alpine.js
- Inertia.js
- Inertia + React
- Inertia + Vue
- Tailwind CSS
- Bootstrap legacy projects
- CSS / SCSS
- Vite
- Webpack
- Laravel Mix

The scanner must determine the actual project stack.

Example:

```text
Framework Detected: Laravel 10
Frontend: React 18
Bridge: Inertia
Bundler: Vite
CSS: Tailwind
Language: TypeScript
Tests: Pest + Playwright
```

or:

```text
Framework Detected: Laravel 7
Frontend: Blade + jQuery
Bundler: Laravel Mix
CSS: Bootstrap
Language: JavaScript
Tests: PHPUnit
```

The AI team must work with the existing architecture unless a migration or modernization is explicitly requested.

---

# 6. Modern UI/UX Requirement

EAJ AI Dev Studio itself must use a modern, production-quality, **branded and animated** UI.

**Decided frontend stack (fits EAJ's existing Laravel expertise and one-server deployment):**

- Laravel 12 + Inertia.js 2
- React 19 + TypeScript (strict)
- Tailwind CSS 4 with EAJ design tokens (Section 7.3)
- Radix UI / shadcn-style accessible primitives, re-skinned in EAJ brand
- Motion (Framer Motion) for component animation; CSS keyframes for always-on micro-animations
- Lucide icons (stroke 1.5–2px, matching the monogram's geometric weight)
- Laravel Reverb (WebSockets) for realtime, with an HTTP catch-up endpoint
- Dark mode as the **default** theme (it is the native logo background); light mode fully supported

A separate Next.js frontend is **not** recommended for v1: it adds a second deployment, a second auth surface and a second failure point without a product benefit (see Section 89).

---

# 7. EAJ Brand, Design System & Motion

The UI must feel like a premium software-engineering operations platform — Linear-style clarity, Vercel-style simplicity, GitHub-style technical utility — **expressed in EAJ's own identity**.

## 7.1 Brand Assets

Source asset: `eaj-primary.png` (EA monogram + "EAJ WEB DEVELOPMENT SERVICES" wordmark on navy).

Required production files (to be redrawn as vector before Phase 1 — the PNG is the visual reference only):

```text
resources/brand/
├── eaj-mark.svg              EA monogram, full colour (crimson + white), for dark backgrounds
├── eaj-mark-light.svg        EA monogram, crimson + navy, for light backgrounds
├── eaj-mark-mono.svg         Single-colour (currentColor) for favicons, badges, watermarks
├── eaj-lockup-horizontal.svg Monogram + "EAJ AI Dev Studio" wordmark (sidebar expanded)
├── eaj-lockup-stacked.svg    Monogram over wordmark (login, splash, empty states)
├── favicon.svg / favicon.ico / apple-touch-icon.png (180px) / og-image.png (1200×630)
└── eaj-mark-animated.svg     Paths split for the draw-on loading animation (Section 7.7)
```

Logo usage rules:

- Sidebar expanded: horizontal lockup, mark height 28px. Sidebar collapsed / mobile top bar: mark only, 24px.
- Clear space around the mark = the height of the "E" crossbar on all sides.
- Never recolour the mark outside the approved variants, stretch it, add shadows, or place the full-colour version on crimson.
- Minimum size: 16px (use `eaj-mark-mono.svg` below 24px).
- Wordmark product name: **EAJ** in crimson, **AI Dev Studio** in white (dark mode) / navy (light mode) — mirrors the "EAJ Web Development" treatment in the logo.

## 7.2 Colour Palette

Sampled directly from the logo:

| Token | Hex | Source / Use |
|-------|-----|--------------|
| `eaj-navy` | **#0A1034** | Logo background. App background (dark), sidebar, brand surfaces. |
| `eaj-crimson` | **#D61A5C** | Logo accent. Primary buttons, active nav, focus rings, "working" agent state. |
| `eaj-white` | **#FFFFFF** | Logo foreground. Primary text on navy, light-mode surfaces. |

Extended scales (derived from the three brand colours so every UI need is covered without off-brand hues):

```text
Navy                           Crimson
navy-950  #050820  deepest bg   crimson-50   #FDF1F5
navy-900  #0A1034  app bg       crimson-100  #FBDDE7
navy-850  #0F1641  sidebar      crimson-200  #F6B3CA
navy-800  #141C4D  card         crimson-300  #EF7FA6
navy-700  #1E2862  raised card  crimson-400  #E64A82
navy-600  #2B367A  border/hover crimson-500  #D61A5C  ← brand
navy-500  #404C95  muted border crimson-600  #B5134C
navy-400  #6470B0  disabled     crimson-700  #8F0F3C
navy-300  #8F98C9  secondary txt crimson-800 #6A0B2D
navy-200  #BCC2E2  body text (dark)
navy-100  #E3E6F4  light-mode border
navy-50   #F4F5FB  light-mode bg
```

Semantic status colours (chosen to stay clearly distinct from brand crimson, which is reserved for brand and "working"):

```text
success   #22C55E   tests passed, gate passed, done
warning   #F5A524   budget 70%+, waiting for approval, low confidence
danger    #F04438   failed gate, security CRITICAL/HIGH, error
info      #38BDF8   queued, informational, reconnecting
neutral   navy-300  idle, waiting, cancelled
```

Rules:

- Status is **never** conveyed by colour alone — always icon + label (colour-blind safe).
- All text meets WCAG 2.2 AA: body text ≥ 4.5:1, large text/icons ≥ 3:1. White on crimson-500 = 4.9:1 ✓. navy-200 on navy-900 ≥ 10:1 ✓. In light mode use crimson-600 for crimson text on white.
- Crimson is an accent, not a fill: maximum ~10% of any screen's surface.

## 7.3 Design Tokens (Tailwind 4)

```css
/* resources/css/tokens.css */
@theme {
  --color-brand-navy: #0A1034;
  --color-brand-crimson: #D61A5C;
  --color-brand-white: #FFFFFF;
  /* navy-50…950 and crimson-50…800 as in 7.2 */

  --font-sans: "Inter Variable", system-ui, sans-serif;
  --font-display: "Montserrat", "Inter Variable", sans-serif; /* wide geometric caps like the wordmark */
  --font-mono: "JetBrains Mono", ui-monospace, monospace;      /* logs, diffs, file paths */

  --radius-sm: 6px;  --radius-md: 10px;  --radius-lg: 14px;    /* echoes the monogram's rounded corners */

  --ease-out: cubic-bezier(0.22, 1, 0.36, 1);
  --ease-in-out: cubic-bezier(0.65, 0, 0.35, 1);
  --dur-instant: 100ms; --dur-fast: 160ms; --dur-base: 240ms; --dur-slow: 400ms;
}

:root            { --bg: var(--color-navy-50);  --surface: #fff;                  --text: var(--color-navy-900); --accent: var(--color-crimson-600); }
:root.dark       { --bg: var(--color-navy-900); --surface: var(--color-navy-800); --text: var(--color-navy-200); --accent: var(--color-brand-crimson); }
```

Typography: the wordmark style (Montserrat, uppercase, letter-spacing 0.2em) is used **only** for the product lockup, page eyebrows and section labels. Everything else uses Inter for density and legibility. Numbers in dashboards use `font-variant-numeric: tabular-nums`.

Brand signature details (subtle, never decorative noise):

- The logo's **diagonal cut** (the slanted ends of the E bars and A legs) appears as a 2px crimson slanted edge on the active nav item and on the left of the active task card.
- The logo's **underline rule** ("— SERVICES —") becomes the thin crimson divider under page titles.
- A very faint EA mark watermark (3% opacity) sits in empty states — not on working screens.

## 7.4 Avoid

- Childish robot avatars or cartoon AI characters — agents are represented by role monograms in EAJ style (e.g. `BE`, `FE`, `QA`) in rounded squares.
- Giant gradients, excessive glow, unnecessary glassmorphism.
- Giant empty cards, oversized headings, generic Bootstrap admin-template look.
- Fake dashboards and fake activity (Section 2.1).

## 7.5 Agent Identity

Every AI team member has a consistent visual identity used everywhere (War Room, Kanban, activity, notifications):

```text
Role                 Monogram  Icon (Lucide)
Project Manager      PM        kanban-square
Business Analyst     BA        clipboard-list
Solution Architect   SA        network
UI/UX Designer       UX        pen-tool
Frontend Developer   FE        layout-template
Backend Developer    BE        server
Database Engineer    DB        database
QA Engineer          QA        flask-conical
Security Engineer    SE        shield-check
Senior Reviewer      SR        git-pull-request
DevOps Engineer      DO        container
Release Manager      RM        rocket
```

The avatar is a 36px rounded square (`radius-md`) in navy-700 with a white monogram. Its **status ring** carries the animation (Section 7.6).

## 7.6 Motion System — "Animated When Working"

Principle: **motion means real work.** An agent animates only while its persisted state is an active state (`INITIALIZING`, `PLANNING`, `READING`, `WORKING`, `TESTING`, `REVIEWING`, `FIXING`). The animation is bound to the event stream, never to a timer — when events stop arriving the agent goes still and, after the heartbeat timeout, shows "Stalled" (Section 48.5).

Agent state → animation:

| Agent state | Visual | Animation |
|---|---|---|
| IDLE / COMPLETED | Static ring, navy-500 / success | None. Completed shows a one-time check-mark draw (400ms). |
| QUEUED | Dashed info ring | Slow dash rotation (6s loop). |
| INITIALIZING / PLANNING | Crimson ring | Ring fills from 0→100% once, then gentle breathing. |
| READING | Crimson ring + file chip | **Scanning bar** sweeps across the file-name chip (1.4s loop); file name cross-fades on each `agent.file.read` event. |
| WORKING | Crimson ring | **Orbit**: a crimson arc travels around the avatar (1.6s loop) + soft crimson glow pulse (2.4s, opacity 0.25→0.5). Typing-dots indicator beside "current activity". |
| TESTING | Crimson ring + progress | Test counter **counts up** live (`118/120 → 120/120`) with tabular numbers; segmented progress bar fills per result. |
| REVIEWING | Crimson ring | Orbit at half speed + diff line counter. |
| FIXING | Warning ring | Orbit in warning colour; failed-test count counts **down**. |
| WAITING_FOR_TOOL / WAITING_FOR_AGENT | Info ring | Three-dot ellipsis only — no orbit (it is not working). |
| BLOCKED / PAUSED | Warning ring | Static; single attention pulse on entering the state, then still. |
| FAILED | Danger ring | One-time 2-cycle shake (300ms), then static with the error summary. |

Shared micro-interactions:

- **Activity stream:** new events slide in from the top (y: −8px → 0, opacity 0 → 1, 240ms ease-out); max 1 animation per 100ms — bursts are batched so the log never jitters.
- **Kanban:** cards move between columns with a layout (FLIP) animation when `task.status.changed` arrives; the moving card briefly shows the brand diagonal edge.
- **Numbers:** tokens, cost and test counts tween to the new value (400ms) instead of jumping.
- **Gate pipeline:** each quality gate node lights up in sequence; passed = check draw, failed = danger fill + the line back to the responsible specialist animates in reverse.
- **Page transitions:** 160ms fade + 4px rise. No route animation longer than 240ms.
- **Skeletons:** navy-800 → navy-700 shimmer for loading — never a spinner on a full page.
- **Realtime indicator** (top bar): solid success dot when connected; pulsing info dot while reconnecting; danger dot with "Offline — showing last known state" when disconnected.

Performance and accessibility rules for motion:

- Animate only `transform` and `opacity` (GPU-friendly); no animated box-shadow/blur on lists.
- At most 12 continuously animated elements on screen; agents off-screen pause their animation (IntersectionObserver).
- Respect `prefers-reduced-motion`: replace all loops with static state rings + text, keep only 100ms opacity fades. Also provide a "Reduce motion" toggle in Settings.
- Pause all loop animations when the browser tab is hidden.
- Target: 60fps on a mid-range laptop with 12 active agents; verified in Phase 12 performance tests.

## 7.7 Branded Loading & Splash

- **App boot / login:** the EA monogram draws itself (stroke-dashoffset, white E then crimson A accents, 900ms) then fills; the wordmark fades in under it. Shown only if boot takes > 300ms.
- **Long operations** (project scan, index build): monogram outline with a crimson progress stroke tracing the shape, plus the real step text ("Detecting framework… Laravel 10").
- **Demo mode badge:** a crimson "DEMO" pill in the top bar on every screen so simulated data can never be mistaken for real work.

---

# 8. Responsive UX

Desktop is the primary experience.

The product must still work on:

- Desktop
- Laptop
- Tablet
- Mobile

Desktop should provide:

- Multi-column dashboards
- Persistent sidebar
- Large Kanban
- Split views
- Code/review panels
- Live activity streams

Mobile should provide:

- Collapsible navigation
- Stacked cards
- Drawer-based detail views
- Simplified data tables
- Full task functionality
- Readable activity logs

Never remove critical project-management functionality from mobile.

---

# 9. UX States

Every meaningful UI component must handle:

- Loading
- Empty state
- Error
- Success
- Permission denied
- Offline
- Reconnecting
- No results
- Partial results
- Blocked
- Paused

Never leave unexplained blank UI.

---

# 10. Core AI Team

EAJ AI Dev Studio should support the following specialist roles.

## 10.1 AI Project Manager

Responsibilities:

- Understand project goals.
- Create milestones.
- Create task breakdowns.
- Track dependencies.
- Track blockers.
- Manage priorities.
- Determine the next specialist.
- Summarize progress.

The Project Manager does not write implementation code.

---

## 10.2 AI Product / Business Analyst

Responsibilities:

- Convert natural-language requests into requirements.
- Define acceptance criteria.
- Define user stories.
- Identify edge cases.
- Document business rules.
- Identify ambiguity.
- Identify missing functional requirements.

---

## 10.3 AI Solution Architect

Responsibilities:

- Analyze architecture.
- Define module boundaries.
- Review integrations.
- Review scalability.
- Review authentication.
- Review authorization.
- Review database implications.
- Review concurrency risks.
- Create ADRs for significant decisions.

---

## 10.4 AI UI/UX Designer

Responsibilities:

- User flows.
- Information architecture.
- Page structure.
- Component hierarchy.
- Interaction design.
- Accessibility.
- Responsive behavior.
- Empty/loading/error states.
- Design-system consistency.
- UX review of completed work.

The Designer should support:

- React UI
- Next.js
- Blade
- Vue
- Inertia
- Tailwind
- Legacy Bootstrap when required

The Designer must adapt to the existing project's design language unless redesign is explicitly requested.

---

## 10.5 AI Senior Frontend Developer

Responsibilities:

- React
- Next.js
- Vue
- Blade
- TypeScript
- JavaScript
- Tailwind
- Existing CSS frameworks
- API integration
- State handling
- Form validation
- Responsive UI
- Accessibility
- Frontend testing

---

## 10.6 AI Senior Backend Developer

Responsibilities:

- Laravel 7+
- PHP
- APIs
- Services
- Actions
- Controllers
- Validation
- Policies
- Authorization
- Events
- Queues
- Transactions
- Notifications
- Tests
- Performance

The agent must respect framework version compatibility.

---

## 10.7 AI Database Engineer

Responsibilities:

- Schema design.
- Migrations.
- Indexes.
- Foreign keys.
- Constraints.
- Query performance.
- Locking.
- Transactions.
- Concurrency.
- Data integrity.
- Backward-compatible migrations.

---

## 10.8 AI QA Engineer

Responsibilities:

- Acceptance-test planning.
- Regression tests.
- Unit tests.
- Integration tests.
- API tests.
- E2E tests.
- Browser tests.
- Responsive checks.
- Edge-case testing.
- Failure reproduction.
- Bug reports.

---

## 10.9 AI Security Engineer

Responsibilities:

- Authorization.
- Authentication.
- Tenant isolation.
- SQL injection.
- XSS.
- CSRF.
- File uploads.
- API access control.
- Secrets.
- Dependency vulnerabilities.
- Rate limiting.
- OWASP-oriented review.

---

## 10.10 AI Senior Code Reviewer

Responsibilities:

- Review Git diff.
- Architecture consistency.
- Maintainability.
- Performance.
- Security.
- Naming.
- Error handling.
- Tests.
- Duplication.
- Query efficiency.
- Framework best practices.

Suggested approval threshold:

```text
Minimum Quality Score: 90 / 100
```

Scores below the threshold return work to development.

---

## 10.11 AI DevOps Engineer

Responsibilities:

- Docker.
- CI/CD.
- Queue workers.
- Redis.
- Deployments.
- Rollbacks.
- Environment configuration.
- Scheduled jobs.
- Build processes.
- Monitoring.
- Health checks.

---

## 10.12 AI Release Manager

Responsibilities:

Confirm:

- Required tests passed.
- Required review passed.
- Security review passed.
- Pre-Deployment Security Gate (Section 74.2) passed for the exact release commit.
- Build passed.
- Migration safety checked.
- Release checklist complete.
- Required human approvals completed.

Only then may a release be considered production-ready.

---

# 11. Local Project Mode

Local Project Mode is a primary feature.

Example supported paths:

```text
C:\laragon\www\project
C:\xampp\htdocs\project
D:\Projects\project
/home/user/projects/project
/Users/user/Projects/project
```

The application must support Windows, Linux, and macOS path handling.

---

# 12. Local Project Connection Flow

Example:

```text
New Project
    ↓
Local Repository
    ↓
Choose Folder
    ↓
Scan Project
    ↓
Detect Stack
    ↓
Create Project Profile
    ↓
Build Code Index
    ↓
Ready
```

Example scan result:

```text
Project: Booking SaaS
Framework: Laravel 11
Frontend: React
Integration: Inertia
Bundler: Vite
CSS: Tailwind
Database: MySQL
Tests: Pest
E2E: Playwright
Git: Enabled
```

---

# 13. Local Workspace Safety

Do not allow agents to work directly on the original project by default.

Example:

```text
Original:
C:\laragon\www\booking-saas

Workspaces:
C:\EAJ\workspaces\booking-saas\EAJ-101
C:\EAJ\workspaces\booking-saas\EAJ-102
```

All filesystem and Git operations on a developer machine are executed by the **EAJ Local Runner** (Section 89.2), never by the web server. Before creating a worktree the runner verifies: the original repo is clean or its changes are stashed into a named safety stash, free disk space ≥ 2× repo size, and no existing worktree for the task. If any check fails the task is set to `BLOCKED` with the reason.

Preferred implementation:

```bash
git worktree add <workspace> -b ai/<task-id>-<slug>
```

Each task receives:

- Unique branch.
- Unique worktree.
- Unique task ID.
- Isolated execution context.

---

# 14. Non-Git Projects

If a local project is not yet a Git repository:

Preferred behavior:

1. Detect absence of Git.
2. Explain that safe AI modifications require version control.
3. Offer project initialization.
4. Create `.gitignore`.
5. Initialize repository.
6. Create initial baseline commit.
7. Begin isolated task workflow.

Do not overwrite files without a recovery strategy.

---

# 15. Local Database Safety

Never run destructive AI tests against production databases.

Recommended tiers:

```text
Production Database
    ↓
NEVER used for agent testing

Developer Database
    ↓
Read-only or controlled access

Task Test Database
    ↓
Full automated testing allowed
```

Example test database:

```text
eaj_booking_EAJ101
```

For Laravel projects, support safe test configurations such as:

- `.env.testing`
- SQLite where compatible
- Temporary MySQL database
- Temporary PostgreSQL database

---

# 16. Secret Protection

Never send `.env` secrets to the AI provider.

Sensitive patterns must be redacted.

Examples:

```text
OPENAI_API_KEY
STRIPE_SECRET
PAYMONGO_SECRET
DB_PASSWORD
AWS_SECRET_ACCESS_KEY
PRIVATE_KEY
JWT_SECRET
SMTP_PASSWORD
```

Agents may know configuration shape without receiving credentials.

Example allowed representation:

```text
DB_CONNECTION=mysql
QUEUE_CONNECTION=redis
PAYMENT_PROVIDER_CONFIGURED=true
```

---

# 17. AI Tool Permissions

Agents must use explicit permissions.

Example Backend Developer:

Allowed:

- Read task workspace.
- Modify task workspace.
- Run approved framework commands.
- Run tests.
- Run formatters.
- Run static analysis.

Not allowed:

- Read unrelated projects.
- Read user personal files.
- Read SSH private keys.
- Read unredacted `.env`.
- Modify production.
- Delete databases.
- Force-push main.
- Deploy production.

---

# 18. Project Scanner

Every connected project should be scanned.

The scanner should detect:

- Framework.
- Framework version.
- PHP version.
- Node version.
- Package manager.
- Frontend framework.
- Build tool.
- CSS framework.
- Database driver.
- Test framework.
- E2E framework.
- Static-analysis tools.
- Formatter.
- Git state.
- Docker configuration.
- CI configuration.
- Auth provider (Laravel session/Sanctum/Passport, NextAuth/Auth.js, Supabase Auth).
- Payment providers (Stripe, PayMongo, others) and webhook routes.
- Browser-exposed env prefixes in use (`NEXT_PUBLIC_`, `VITE_`, `MIX_`, `REACT_APP_`).

---

# 19. Laravel Version Compatibility

Laravel 7+ must be supported.

Do not assume current Laravel conventions work on every version.

Create a compatibility layer.

Example:

```text
Laravel 7
- Laravel Mix likely
- PHP version may be older
- PHPUnit configuration differs
- Routing/bootstrap structure differs

Laravel 9+
- Vite increasingly common
- PHP 8+
- newer framework conventions

Laravel 11+
- streamlined application structure
- newer bootstrap configuration
```

Before modifying Laravel code, the system must inspect:

- `composer.json`
- framework version
- PHP requirements
- package versions

---

# 20. React Compatibility

Support:

- JavaScript
- TypeScript
- CRA legacy projects
- Vite
- Next.js
- React Router
- Inertia React
- Existing design systems

Never force a React rewrite unless requested.

---

# 21. Next.js Compatibility

Support:

- App Router
- Pages Router
- Server Components
- Client Components
- Route handlers
- Server Actions where project supports them
- TypeScript
- JavaScript
- Tailwind
- CSS Modules
- Existing component libraries

The scanner must determine router mode before generating code.

---

# 22. Existing UI Preservation

If the user requests a backend feature or small UI change:

Do not redesign unrelated UI.

Agents should understand:

- Existing spacing.
- Colors.
- Typography.
- Components.
- Form patterns.
- Button styles.
- Table styles.
- Modal patterns.

Modernization is allowed only when requested or required.

---

# 23. Design System Discovery

For existing projects, detect:

- Tailwind config.
- CSS variables.
- Theme files.
- UI component folder.
- Font setup.
- Existing button component.
- Existing card component.
- Existing modal/dialog component.
- Existing form controls.
- Existing icons.

The UI agent should reuse existing design primitives before introducing new ones.

---

# 24. Visual QA

For frontend tasks, support optional visual QA.

Use browser automation to capture:

- Desktop.
- Tablet.
- Mobile.

Suggested viewport checks:

```text
375px
768px
1024px
1440px
```

Visual QA should check:

- Overflow.
- Alignment.
- Missing content.
- Broken layouts.
- Unreadable text.
- Modal behavior.
- Loading states.
- Empty states.
- Navigation.
- Responsive behavior.

---

# 25. Realtime AI War Room

The flagship screen. It shows every AI team member on the current project, animated according to their **real** state (Section 7.6).

Layout (desktop ≥ 1280px):

```text
┌───────────────────────────────────────────────────────────────────────────┐
│ [EA] Booking SaaS ▾      Overall 74% ▓▓▓▓▓▓▓░░    ● Live    ⏸ STOP ALL     │
├─────────────────────────────────────────────┬─────────────────────────────┤
│ AI TEAM                                     │ LIVE ACTIVITY               │
│ ┌────┐ PM  Project Manager      ✓ Done      │ 21:31:58 BE 120/120 passed  │
│ ┌────┐ SA  Architect            ✓ Done      │ 21:31:27 BE Fixing 2 tests  │
│ ┌◜◝─┐ UX  UI/UX Designer       ◌ Reviewing │ 21:31:24 BE 118/120 passed  │
│ ┌◜◝─┐ FE  Frontend Developer   ◉ Working   │ 21:31:03 BE Running Pest    │
│        └ Editing LoyaltyCard.tsx ···        │ ...                         │
│ ┌◜◝─┐ BE  Backend Developer    ◉ Testing   │                             │
│        └ Pest  ▓▓▓▓▓▓▓▓▓░ 118/120           ├─────────────────────────────┤
│ ┌────┐ QA  QA Engineer          … Waiting   │ QUALITY GATES               │
│ ┌────┐ SE  Security Engineer    … Waiting   │ Format ✓ Static ✓ Tests ◉   │
│ ┌────┐ SR  Senior Reviewer      … Waiting   │ Build ○ QA ○ Review ○ Sec ○ │
│ ┌────┐ RM  Release Manager      … Waiting   │ Tokens 41.2k / 88k  ₱… cost │
└─────────────────────────────────────────────┴─────────────────────────────┘
```

(`◜◝` = animated crimson orbit ring on working agents.)

Tablet: two stacked panels. Mobile: agent list first; activity and gates in bottom-sheet tabs.

Behaviour:

- Working agents sort to the top; finished agents collapse to one line.
- A heartbeat badge shows "last event 3s ago" on hover; > 90s without an event shows "Stalled" (Section 48.5).
- The STOP ALL button is always visible on this screen (Section 57).

---

# 26. Live Activity

Display safe operational events.

Examples:

```text
21:30:04 Backend Developer started EAJ-114
21:30:08 Reading BookingService.php
21:30:14 Reading Booking.php
21:30:31 Updated transaction handling
21:30:49 Added 4 tests
21:31:03 Running Pest
21:31:24 118/120 passed
21:31:27 Fixing 2 failed tests
21:31:58 120/120 passed
21:32:01 Submitted for Senior Review
```

Each line shows the agent's monogram chip and status colour, a monospaced timestamp, and links (file → diff view, test run → results). Filters: by agent, by event type, errors only. Lines animate in per Section 7.6; when the user scrolls up the stream pauses with a "12 new events ↓" pill.

Never expose hidden chain-of-thought.

---

# 27. Agent Detail Panel

Clicking an agent should show:

- Name.
- Role.
- Current status.
- Current project.
- Current task.
- Current activity.
- Start time.
- Files read.
- Files modified.
- Lines added/removed.
- Tests passed.
- Tests failed.
- Token budget.
- Token usage.
- Estimated cost.
- Model currently used.
- Skill version.
- Tool permissions.

Actions:

- View Task.
- View Diff.
- View Tests.
- View Activity.
- Pause.
- Resume.
- Stop.
- Send Instruction.
- Reassign.

---

# 28. Command Center

Create an executive dashboard.

Suggested metrics:

- Active Projects.
- AI Agents Working.
- Tasks Completed Today.
- Tasks Blocked.
- Test Pass Rate.
- Open Security Issues.
- Pull Requests Ready.
- Human Approvals Needed.
- Tokens Today.
- AI Cost Today.
- AI Cost This Month.

---

# 29. Project Dashboard

Each project should include:

- Project health.
- Current milestone.
- Completion percentage.
- Active tasks.
- Blocked tasks.
- Working agents.
- Test status.
- Quality score.
- Security score.
- Token usage.
- Estimated cost.
- Recent commits.
- Recent events.
- Deployment status.

---

# 30. Kanban

Suggested task states:

```text
Backlog
Ready
Planning
In Development
Testing
Senior Review
Security Review
Changes Requested
Blocked
Release Ready
Completed
```

Task cards should display:

- ID.
- Title.
- Priority.
- Assigned agent.
- Model.
- Budget.
- Tokens used.
- Dependencies.
- Tests.
- Review score.
- Status.

---

# 31. Task State Machine

Supported states:

```text
BACKLOG
READY
PLANNING
IN_PROGRESS
TESTING
REVIEW
SECURITY_REVIEW
CHANGES_REQUESTED
BLOCKED
RELEASE_READY
DONE
CANCELLED
```

Invalid transitions must be prevented.

---

# 32. Agent State Machine

Supported states:

```text
IDLE
QUEUED
INITIALIZING
PLANNING
READING
WORKING
TESTING
WAITING_FOR_TOOL
WAITING_FOR_AGENT
REVIEWING
FIXING
BLOCKED
PAUSED
FAILED
COMPLETED
CANCELLED
```

Store meaningful transitions.

---

# 33. Quality Gates

Example:

```text
Requirements
    ↓
Requirements Gate
    ↓
Architecture
    ↓
Architecture Gate
    ↓
Development
    ↓
Formatter
    ↓
Static Analysis
    ↓
Tests
    ↓
Build
    ↓
QA
    ↓
Senior Review
    ↓
Security
    ↓
Regression
    ↓
Pre-Deployment Security Gate (Section 74.2 — 7 checks)
    ↓
Release Approval
```

Failed gates must return work only to the necessary specialist.

---

# 34. Framework-Aware Validation

The system must detect and run tools that actually exist in the project.

Examples:

Laravel modern:

```bash
php artisan test
vendor/bin/pint
vendor/bin/phpstan analyse
npm run build
```

Laravel legacy:

```bash
vendor/bin/phpunit
npm run production
```

React / Vite:

```bash
npm run lint
npm run test
npm run build
```

Next.js:

```bash
npm run lint
npm run typecheck
npm run test
npm run build
```

Commands must come from project configuration when possible.

Never blindly run unsupported commands.

---

# 35. Structured Agent Output

Agents should return structured results.

Example:

```json
{
  "task_id": "EAJ-114",
  "agent": "senior_backend",
  "status": "completed",
  "summary": "Added transaction-safe reservation creation.",
  "files_read": [
    "app/Services/BookingService.php"
  ],
  "files_modified": [
    "app/Services/BookingService.php",
    "tests/Feature/BookingTest.php"
  ],
  "tests_added": 4,
  "tests_passed": 120,
  "tests_failed": 0,
  "warnings": [],
  "ready_for_review": true
}
```

---

# 36. Review Output

Example:

```json
{
  "gate": "senior_code_review",
  "status": "failed",
  "score": 84,
  "minimum_score": 90,
  "issues": [
    {
      "severity": "major",
      "file": "app/Services/BookingService.php",
      "issue": "Concurrent request race condition remains possible."
    }
  ]
}
```

---

# 37. AI Skills System

Create reusable AI skills.

Example:

```text
skills/
├── laravel-senior-developer/
│   ├── SKILL.md
│   ├── references/
│   └── scripts/
├── react-senior-developer/
│   ├── SKILL.md
│   └── references/
├── nextjs-senior-developer/
│   ├── SKILL.md
│   └── references/
├── ui-ux-designer/
│   └── SKILL.md
├── qa-engineer/
│   └── SKILL.md
├── security-engineer/
│   └── SKILL.md
└── senior-code-reviewer/
    └── SKILL.md
```

Skills should load only when relevant.

---

# 38. Skill Metadata

Each skill should define:

- Name.
- Version.
- Supported frameworks.
- Supported versions.
- Responsibilities.
- Rules.
- Forbidden actions.
- Tools.
- Validation expectations.
- References.
- Output schema.

---

# 39. Admin Skills UI

Admin should be able to:

- Create skill.
- Edit skill.
- Clone skill.
- Enable/disable skill.
- Assign agents.
- Define framework compatibility.
- Version skill.
- Add references.
- Add tool rules.
- Test skill.
- View historical success.
- Roll back versions.

---

# 40. Token Optimization

Critical requirement.

Use:

- File relevance selection.
- Symbol indexing.
- Code summaries.
- Diff-first review.
- Prompt caching.
- Short structured handoffs.
- Project memory.
- Task memory.
- Context compaction.
- Model routing.

Do not use:

- Full repository every request.
- Full conversation every request.
- Every skill every request.
- Repeated architecture descriptions.

---

# 41. Code Index

Maintain a searchable index of:

- Files.
- Classes.
- Methods.
- Functions.
- Routes.
- Models.
- Migrations.
- Tables.
- APIs.
- Components.
- TypeScript types.
- React components.
- Vue components.
- Tests.
- Dependencies.

---

# 42. Context Builder

Before calling an AI:

1. Read task.
2. Determine project stack.
3. Determine relevant skill.
4. Find relevant files.
5. Find dependencies.
6. Find tests.
7. Find coding standards.
8. Find relevant architecture decisions.
9. Find prior review issues.
10. Build minimal context.

---

# 43. Project Memory

Maintain structured project knowledge.

Suggested files:

```text
.eaj/
├── PROJECT.md
├── STACK.md
├── ARCHITECTURE.md
├── DATABASE.md
├── API.md
├── UI_SYSTEM.md
├── SECURITY.md
├── TESTING.md
├── CODING_STANDARDS.md
└── decisions/
```

These documents should be concise and generated from the real codebase.

---

# 44. Project Memory Rules

Project memory must never override actual source code.

When memory conflicts with the project:

Source code wins.

Then update project memory.

---

# 45. Model Routing

The system should support configurable model tiers.

Example:

## Fast / Low-Cost

Use for:

- Classification.
- Status summaries.
- File relevance.
- Simple metadata.
- Routine transformations.

## Standard Engineering

Use for:

- Normal coding.
- Test generation.
- Bug fixing.
- Standard review.

## Advanced Reasoning

Use for:

- Architecture.
- Security-critical issues.
- Complex concurrency.
- Difficult production bugs.
- Final high-level review.

Do not hard-code provider model names throughout the codebase.

---

# 46. AI Model Configuration

Suggested table:

```text
ai_models

id
provider
model_key
display_name
tier
input_cost
cached_input_cost
output_cost
max_context
enabled
created_at
updated_at
```

---

# 47. Token Budgets

Support limits by:

- Agent.
- Task.
- Project.
- Organization.
- Day.
- Month.

Example:

```text
Planning       5,000
Architecture  10,000
Development   30,000
QA            12,000
Review         8,000
Security       8,000
Fixes         15,000
```

---

# 48. Retry Control & Reliability Engineering

This section turns the guarantees in Section 2.6 into concrete mechanisms.

## 48.1 Hard Limits (enforced outside the AI)

Every workflow carries:

```text
max_attempts          default 3 per step
max_fix_cycles        default 3 per task
max_review_cycles     default 2 per task
max_handoffs          default 12 per task
max_tokens            per task budget (Section 47)
max_cost              per task budget (Section 47)
max_wall_time         default 45 min per agent run, 4 h per task
```

When any limit is hit the task moves to `BLOCKED` with reason `LIMIT_REACHED` and asks the owner — it never silently continues. Never permit infinite loops.

## 48.2 Durable Workflow Engine

- The task workflow is a persisted state machine (`task_workflows`, `workflow_steps` tables). The **database is the source of truth**; queues only deliver "run step N" messages.
- Each step is a separate queued job (Laravel Horizon on Redis) with: timeout, `tries`, exponential back-off with jitter (5s, 30s, 2m), and `failed()` handler that records the error and transitions state.
- State transitions use row locking (`SELECT … FOR UPDATE`) plus an optimistic `version` column, so two workers can never advance the same task.

## 48.3 Idempotency

- Every job, AI call and tool command has an idempotency key `task_id:step:attempt`.
- Before executing, the worker checks `workflow_steps.status`; a step already `COMPLETED` is skipped, not re-run.
- AI responses are stored before they are applied, so a retried step re-applies the stored response instead of paying for a new call.

## 48.4 Checkpointing & Crash Recovery

- After every agent step the runner creates a **checkpoint commit** in the task worktree (`eaj-checkpoint: EAJ-114 step 6`) and records the SHA.
- On worker or runner restart, a recovery job finds steps stuck in `RUNNING`, resets the worktree to the last checkpoint SHA (`git reset --hard <checkpoint>` — allowed only inside the task worktree), and re-queues the step.
- Checkpoint commits are squashed when the task branch is prepared for merge.

## 48.5 Heartbeats & Stall Detection

- Running agents and the Local Runner send a heartbeat every 15s.
- No heartbeat for 90s → agent shown as "Stalled"; 5 minutes → step marked failed and retried from checkpoint (counts toward `max_attempts`).
- A scheduler job (every minute) also detects tasks whose state hasn't changed past their expected maximum duration.

## 48.6 Reliable Realtime (Transactional Outbox)

- Domain events are written to an `outbox_events` table **in the same DB transaction** as the state change, each with a per-project monotonic `sequence` number.
- A publisher process broadcasts outbox rows via Reverb and marks them sent; if Reverb is down, nothing is lost.
- Clients track the last `sequence` they saw. On reconnect (or on a gap) they call `GET /api/projects/{id}/events?after={seq}` and replay. A full refresh rebuilds the screen from the database — the UI can never drift from reality.

## 48.7 AI Provider Resilience

- Per-provider **circuit breaker**: 5 consecutive failures or > 50% errors in 2 minutes opens the circuit for 60s.
- **Fallback routing**: each model tier lists an ordered fallback (e.g. primary model → same-tier alternative provider). The fallback used is recorded in the audit log.
- Rate-limit (429) responses honour `retry-after`; timeouts are retried once, then fall back.
- **Structured-output validation**: every agent response is validated against its JSON schema (Section 35). Invalid output gets one automatic repair request; a second failure fails the step — invalid output is never applied.

## 48.8 Deterministic Guards Around AI Changes

- Patches are applied with `git apply --check` first; a patch that does not apply cleanly is rejected, not forced.
- After each change: formatter → static analysis → tests run by the tool runner, not by the AI. The AI's claim of "tests passed" is never trusted; only parsed tool output counts.
- Changes outside the task's declared file scope (from the impact analysis, Section 67) require reviewer approval.
- Flaky test handling: a failing test is re-run once in isolation; if it then passes it is marked **flaky** and reported, but still blocks merge until a human accepts it.

## 48.9 Merge Safety

At merge time the server re-checks, against the exact head SHA:

1. All required gates have a `passed` result for **this** SHA (any new commit invalidates earlier results).
2. Target branch has not moved since the last test run — otherwise rebase in the worktree and re-run tests.
3. Human approval exists for this SHA.
4. A **pre-merge backup tag** `eaj-backup/<task-id>/<timestamp>` is created on the target branch, enabling one-click revert.

## 48.10 Failure Handling Matrix

| Failure | Automatic response | Owner sees |
|---|---|---|
| AI provider timeout / 5xx | Retry with back-off → fallback model | Info event only |
| Invalid AI output | One repair attempt → fail step | Event + task stays in step |
| Tests fail | Route back to responsible specialist (fix cycle) | Failing tests + agent in FIXING |
| Fix cycles exhausted | Task → BLOCKED | Notification: "Needs your decision" |
| Worker crash | Resume from last checkpoint | "Recovered" event |
| Local Runner offline | Pause tasks for that machine | Banner: "Runner offline — reconnect your PC" |
| Budget 100% | Hard stop | Approval request to raise budget |
| Merge conflict | Rebase attempt → if conflict persists, BLOCKED | Conflict files listed |
| Redis/Reverb down | Outbox buffers; UI polls catch-up API | "Reconnecting" indicator |
| Database down | Workers pause; health check fails | Status page / alert |

## 48.11 Testing the Studio Itself

- Unit + feature tests for every state transition (valid and invalid).
- Chaos tests in CI: kill a worker mid-step, drop the WebSocket, return malformed AI JSON, simulate provider 429/500 — each must end in a correct, recoverable state.
- Fake AI provider (`FakeAIProvider`) with scripted responses so the whole pipeline is tested without paid API calls.
- Release of the Studio itself is blocked unless this suite passes.

## 48.12 Service Level Objectives

```text
Studio UI availability               99.5% monthly
Event delivery to UI (p95)           < 2 s
Lost or duplicated task steps        0  (verified by reconciliation job)
Unverified merges                    0  (enforced by 48.9)
Recovery after worker crash          < 2 min
Nightly DB backup + weekly restore test
```

---

# 49. Cost Dashboard

Show:

- Today.
- Week.
- Month.
- Project.
- Task.
- Agent.
- Model.
- Input tokens.
- Cached tokens.
- Output tokens.
- Estimated cost.
- Average cost/task.
- Cost/completed feature.

---

# 50. Cost Alerts

Support thresholds:

```text
70% warning
90% critical warning
100% hard stop
```

Allow hard-stop behavior.

---

# 51. Repository Support

Initial support:

- Local Git repository.
- GitHub.

Future-ready:

- GitLab.
- Bitbucket.
- Azure DevOps.

Provider logic should be abstracted.

---

# 52. Branch Strategy

Default:

```text
main
develop
ai/<task-id>-<slug>
fix/<task-id>-<slug>
feature/<task-id>-<slug>
```

Do not force-push protected branches.

---

# 53. Diff-First Review

The Senior Reviewer should primarily receive:

- Task.
- Acceptance criteria.
- Current diff.
- Relevant surrounding code.
- Tests.
- Tool output.

This dramatically reduces tokens.

---

# 54. Parallel Execution

Allow safe parallel tasks when dependencies permit.

Use a DAG.

Example:

```text
API Contract
   ↓
 ┌─────────────┐
 ↓             ↓
Frontend     Backend
 └──────┬──────┘
        ↓
Integration QA
```

---

# 55. File Conflict Protection

Avoid two agents modifying the same critical file at the same time.

Use:

- Branch isolation.
- Worktrees.
- File locks where needed.
- Dependency scheduling.
- Merge-conflict detection.

---

# 56. Human Approval

Allow configurable approval gates.

Examples:

- Start AI work.
- Architecture changes.
- Dependency installation.
- Destructive migration.
- Spending above limit.
- Merge.
- Production deployment.

---

# 57. Emergency Stop

Add:

```text
STOP ALL AI OPERATIONS
```

Behavior:

- Stop new jobs.
- Cancel queued jobs where safe.
- Preserve workspaces.
- Preserve logs.
- Preserve task state.
- Do not corrupt repositories.

Implementation: a `system_halt` flag in Redis **and** the database. Every worker checks it before each step and between tool calls; running processes receive a graceful stop signal, then a forced kill after 30s. Worktrees are left at their last checkpoint. Resuming requires an explicit owner action and shows exactly which tasks will resume.

---

# 58. Demo Mode

Support:

```text
DEMO_MODE=true
```

Demo mode should simulate:

- Projects.
- Agent statuses.
- Events.
- Tests.
- Reviews.
- Cost charts.

Production mode must never fake events. Demo mode shows a permanent crimson **DEMO** pill (Section 7.7), and demo data lives in a separate demo organization so it can never mix with real projects.

---

# 59. Auditability

Every important action should track:

- Organization.
- Project.
- Task.
- Agent.
- Agent role.
- Model.
- Model tier.
- Skill.
- Skill version.
- Prompt version.
- Tools called.
- Files modified.
- Tests.
- Tokens.
- Cost.
- Start time.
- End time.
- Result.
- Review score.
- Human approvals.

---

# 60. Suggested Core Tables

The exact schema should be normalized during implementation.

Likely entities:

```text
users
organizations
organization_members
projects
project_members
project_connections
project_snapshots
milestones
tasks
task_dependencies
task_comments
agents
agent_roles
skills
skill_versions
agent_skills
agent_runs
agent_events
ai_models
model_routes
token_usage
cost_usage
quality_gates
gate_results
test_runs
test_results
code_reviews
security_reviews
repositories
repository_workspaces
repository_branches
repository_commits
deployments
deployment_logs
notifications
system_settings
audit_logs
task_workflows
workflow_steps
workflow_checkpoints
outbox_events
local_runners
runner_heartbeats
provider_circuit_states
```

Do not create redundant tables merely because they are listed here.

---

# 61. Realtime Events

Example event types:

```text
agent.started
agent.status.changed
agent.file.read
agent.file.modified
agent.command.started
agent.command.completed
agent.test.started
agent.test.completed
agent.review.started
agent.review.completed
task.status.changed
gate.failed
gate.passed
workspace.created
workspace.deleted
deployment.started
deployment.completed
token.updated
budget.warning
human.approval.required
```

Persist meaningful events only.

---

# 62. Realtime Transport

Possible approaches:

- Laravel Reverb.
- WebSockets.
- Server-Sent Events.
- Redis pub/sub.

The architecture should allow frontend clients to reconnect and rebuild state.

---

# 63. Main Navigation

Suggested sidebar:

```text
EAJ

Command Center
Projects
AI War Room
Tasks
AI Team
Skills
Repositories
QA Center
Security
Deployments
Analytics
Usage & Cost
Activity
Settings
```

---

# 64. Project Creation Wizard

Step 1:
Project type.

Options:

- Local Repository.
- GitHub Repository.
- New Project.
- Import Archive.

Step 2:
Scan.

Step 3:
Review detected stack.

Step 4:
Choose AI team.

Step 5:
Budget.

Step 6:
Approval rules.

Step 7:
Create project.

---

# 65. Local Project Scan UI

Example:

```text
Project Found

Name
Boundary Cafe POS

Framework
Laravel 10

Frontend
React + Inertia

Language
PHP + TypeScript

Bundler
Vite

CSS
Tailwind

Database
MySQL

Tests
Pest

Git
Enabled

[Connect Project]
```

---

# 66. New Task Interface

Allow simple natural-language instructions.

Example:

```text
Add an expenses export to Excel.
Only Admin and Super Admin can access it.
Branch Managers must remain read-only.
```

The Orchestrator should convert this into structured work.

---

# 67. Change Impact Analysis

Before work begins, show:

- Backend impact.
- Frontend impact.
- Database impact.
- Security impact.
- Expected files.
- Expected tests.
- Recommended agents.
- Estimated complexity.
- Estimated token budget.
- Estimated cost.

---

# 68. UI/UX Task Workflow

Example:

```text
User Request
    ↓
UI/UX Designer
    ↓
Frontend Developer
    ↓
Automated Build
    ↓
Accessibility Checks
    ↓
Playwright
    ↓
Visual QA
    ↓
Senior Frontend Review
```

Do not call backend/database agents unless necessary.

---

# 69. Backend Task Workflow

Example:

```text
User Request
    ↓
Backend Developer
    ↓
Formatter
    ↓
Static Analysis
    ↓
Unit / Feature Tests
    ↓
QA
    ↓
Senior Review
    ↓
Security if required
```

---

# 70. Full Feature Workflow

Example:

```text
Product Analysis
    ↓
Architecture
    ↓
UI/UX
    ↓
Frontend + Backend in parallel
    ↓
Integration
    ↓
QA
    ↓
Senior Review
    ↓
Security
    ↓
Release Gate
```

---

# 71. Framework Modernization

EAJ should support modernization requests.

Examples:

- Laravel 7 → Laravel 10/11/12.
- Blade → React.
- Laravel Mix → Vite.
- JavaScript → TypeScript.
- Bootstrap → Tailwind.
- Pages Router → App Router.

Modernization must be treated as a major migration project with:

- Compatibility analysis.
- Dependency review.
- Incremental migration.
- Regression tests.
- Rollback strategy.

Never silently modernize a legacy codebase.

---

# 72. Legacy Project Support

Legacy projects must not be treated as broken simply because they do not use current patterns.

Agents should:

1. Detect version.
2. Respect existing architecture.
3. Fix the requested issue.
4. Avoid unrelated modernization.
5. Flag dangerous unsupported dependencies separately.

---

# 73. Testing Strategy

Testing should be project-aware.

Possible tools:

PHP:

- PHPUnit.
- Pest.

JavaScript/TypeScript:

- Jest.
- Vitest.
- React Testing Library.

E2E:

- Playwright.
- Cypress if existing.

Static analysis:

- PHPStan.
- Psalm.
- TypeScript.
- ESLint.

Formatting:

- Laravel Pint.
- Prettier.
- ESLint formatting where configured.

---

# 74. Security Review & Pre-Deployment Security Gate

## 74.1 Continuous Security Review

Security checks on every task diff should consider:

- Authentication.
- Authorization.
- Tenant isolation.
- Role escalation.
- Mass assignment.
- Validation.
- SQL injection.
- XSS.
- CSRF.
- File upload.
- SSRF.
- Unsafe redirects.
- API rate limiting.
- Sensitive logging.
- Secret leakage.
- Dependency vulnerabilities.

## 74.2 Pre-Deployment Security Gate (mandatory, blocking)

Before **any** deployment of a client project or of EAJ AI Dev Studio itself, the Security Engineer agent runs the seven checks below. Each check must produce an **evidence artifact** (real tool output, real file:line references, real test output) that is stored with the release and shown in the Security screen. An AI statement such as "all routes are protected" is never accepted as evidence.

Rules:

- The gate runs against the exact commit SHA being deployed. Any new commit invalidates the result.
- Deterministic tools run first (grep/AST scanners, route listers, schema dumps, test runners); the AI agent then reviews what tools cannot decide.
- A failed check opens a task for the responsible specialist, then re-runs the full gate.
- **Checks 1, 2, 3, 5 and 7 cannot be overridden.** Checks 4 and 6 may be overridden only by the Organization Owner with a written reason and an expiry date, recorded in `audit_logs`.
- The Release Manager (Section 10.12) cannot mark a release ready without a passing gate report.

The checks are written once and executed through stack adapters, so the same gate works on Laravel 7–12, Next.js, React SPAs, Inertia, Supabase and mixed projects.

---

### Check 1 — Secrets

**Goal:** no secret ever reaches the browser or the Git history.

The agent must:

1. **Inventory every environment variable**: parse `.env.example`, `.env*` (names only — values are never sent to an AI, Section 16), `config/*.php`, `process.env.*` / `import.meta.env.*` usages, `next.config.*`, CI files and Docker files.
2. **Classify browser exposure** for each variable:
   - Exposed: `NEXT_PUBLIC_*`, `VITE_*`, `MIX_*`, `REACT_APP_*`, `PUBLIC_*`, values rendered into Blade/Inertia shared props, `window.*` config, or bundled JS.
   - Verify by **building the frontend and scanning the built assets** (`public/build`, `.next/static`, `dist`) for secret patterns and known secret values (compared by hash on the runner, never sent to the AI).
3. **Move every secret behind a server route**: any third-party call needing a secret key (payment, AI provider, SMS, email, storage) must go through a Laravel controller / Next.js route handler / server action. Only publishable keys (e.g. Stripe `pk_`, PayMongo `pk_`, Supabase anon key) may be exposed, and only when the corresponding server-side protections (RLS, signature checks) are proven in Checks 3 and 5.
4. **Scan Git history** with gitleaks (or trufflehog) across all branches and tags.
5. **Rotation**: every secret found in history, built assets, logs or a public variable is marked `MUST ROTATE`. The agent cannot rotate provider keys itself; it creates a rotation checklist task (provider, key name, where it is used, steps), and the gate stays failed until the owner confirms rotation and the runner verifies the old value no longer appears in the current `.env`. History is rewritten only on explicit owner approval (dangerous command, Section 85) — rotation is required regardless, because rewriting history does not un-leak a key.

**Evidence:** env var table (name · used in · server-only / browser-exposed / publishable · status), built-asset scan output, gitleaks report, rotation checklist.

**Pass:** zero secrets in browser-exposed variables or built assets; zero unrotated leaked secrets.

---

### Check 2 — Ownership (IDOR / Broken Object-Level Authorization)

**Goal:** every route that touches a resource ID verifies the caller owns or may access it.

The agent must:

1. **List every route**: `php artisan route:list --json` (Laravel), App/Pages Router route handlers and server actions (Next.js), Supabase edge functions.
2. For each route with a path or body ID (`{booking}`, `[id]`, `?id=`, `user_id`, `order_id` …), locate **where ownership is verified**, as file:line:
   - Laravel: `$this->authorize()`, `Gate::`, `can:` middleware, Policies, Form Request `authorize()`, scoped route-model binding (`->scopeBindings()`), or a tenant global scope.
   - Next.js / Supabase: a session check plus a query filtered by the session user, or RLS (Check 3).
3. **Flag any route that trusts a user ID from the request**: `$request->user_id`, `$request->input('user_id')`, `body.userId`, `params.userId`, or `owner_id` set from input. The owner must always come from the session (`auth()->id()`, `$request->user()`, `session.user.id`, `auth.uid()`).
4. Flag public routes with no auth middleware that read or write user data.

**Evidence:** route matrix (method · URI · auth · ownership check file:line · trusts request userId? · verdict).

**Pass:** every ID-bearing route has a verified ownership check; zero routes trust a client-supplied user ID.

---

### Check 3 — Database Rules (Row-Level Security)

**Goal:** the database itself refuses cross-user access wherever clients can reach it directly.

**Postgres / Supabase projects** (any table reachable through PostgREST or a client SDK):

1. List every table in exposed schemas with RLS disabled:

```sql
select n.nspname as schema, c.relname as table
from pg_class c join pg_namespace n on n.oid = c.relnamespace
where c.relkind = 'r'
  and n.nspname in ('public')          -- plus any other exposed schemas
  and not c.relrowsecurity;
```

2. Enable RLS on all of them and write policies scoped to `auth.uid()`:

```sql
alter table public.bookings enable row level security;
alter table public.bookings force row level security;

create policy bookings_select_own on public.bookings
  for select using (user_id = auth.uid());
create policy bookings_insert_own on public.bookings
  for insert with check (user_id = auth.uid());
create policy bookings_update_own on public.bookings
  for update using (user_id = auth.uid()) with check (user_id = auth.uid());
create policy bookings_delete_own on public.bookings
  for delete using (user_id = auth.uid());
```

   Tables with no client access at all get RLS enabled with **no** policies (deny all) and are only used via the service role on the server. The service-role key must be server-only (Check 1).

3. **Prove it as another user**, inside a transaction on the task test database:

```sql
begin;
set local role authenticated;
select set_config('request.jwt.claims', '{"sub":"<user-B-uuid>","role":"authenticated"}', true);
select count(*) from public.bookings where user_id = '<user-A-uuid>';  -- expected: 0
update public.bookings set notes = 'x' where user_id = '<user-A-uuid>'; -- expected: UPDATE 0
delete from public.bookings where user_id = '<user-A-uuid>';           -- expected: DELETE 0
rollback;
```

**MySQL / MariaDB Laravel projects** (no native RLS — the browser never talks to the database directly):

- The equivalent control is mandatory and verified: every tenant/user-owned model uses a global scope or policy, DB users for the app have no `SUPER`/`DROP` rights, and the database port is not publicly reachable.
- The proof is Check 7's cross-user test plus a check that no route returns models fetched without the ownership scope (`withoutGlobalScopes()` usage is flagged for review).

**Evidence:** before/after RLS table list, policy SQL migration, cross-user query output (or the MySQL equivalent report).

**Pass:** zero exposed tables without RLS (Postgres); cross-user queries return zero rows / zero affected rows.

---

### Check 4 — Mass Assignment

**Goal:** a user can only set the fields they are allowed to set.

The agent must:

1. Ensure every write route validates its body with a **strict schema that rejects unknown fields**:
   - Laravel: Form Request per route; persist with `$request->validated()` / `$request->safe()->only([...])` only — never `$request->all()`. Unknown keys are rejected with a 422 via a shared `RejectsUnknownFields` trait (compares `array_keys($this->all())` against the rule keys). Models use explicit `$fillable` (no `$guarded = []`), and `Model::preventSilentlyDiscardingAttributes()` is enabled in non-production.
   - Next.js / Node: Zod schema with `.strict()` per route handler / server action.
2. Produce the schema per route and the list of **fields a user must never set**, at minimum: `id`, `user_id`/`owner_id`/`organization_id`/`tenant_id`, `role`, `is_admin`, `permissions`, `email_verified_at`, `status` (where workflow-controlled), `price`, `amount`, `total`, `discount`, `paid_at`, `payment_status`, `balance`, `credits`, `created_at`/`updated_at`/`deleted_at`.
3. Write a test per write route that sends each forbidden field and asserts it is rejected (422) or ignored and unchanged in the database.

**Evidence:** per-route table (route · schema file · allowed fields · forbidden fields · test name).

**Pass:** every write route has a strict schema; zero uses of `$request->all()` / unvalidated bodies in persistence; forbidden-field tests pass.

---

### Check 5 — Payments

**Goal:** the client never decides what it pays, and payment state changes only from verified provider events.

The agent must:

1. **Trace every price and amount** from the payment API call back to its source (file:line). Allowed sources: a server-side lookup by product/plan ID from the database or the provider's price object. Any amount that originates in the request body, query string, hidden form field, local storage or Inertia/React props submitted back is a failure.
2. Ensure checkout endpoints accept only **product ID + quantity** (quantity bounded and validated); totals, discounts, taxes and currency are calculated on the server.
3. **Webhook handler** must:
   - Verify the signature on the **raw request body** before parsing — Stripe: `\Stripe\Webhook::constructEvent($payload, $sigHeader, $secret)` (PHP) / `stripe.webhooks.constructEvent(rawBody, sig, secret)` (Node, with body parsing disabled for that route); PayMongo: HMAC-SHA256 verification of the `Paymongo-Signature` header (`t`, `te`/`li`) with timestamp tolerance.
   - Reject invalid signatures with 400 and log without the payload secret.
   - Be idempotent: store processed event IDs (unique index) and ignore repeats.
   - Re-fetch or cross-check the amount/currency against the server-side order before marking it paid.
   - Be excluded from CSRF middleware **only** for that single route.
4. Order status `paid` may only be set by the verified webhook (or a server-side retrieval of the payment), never by a client redirect to a "success" page.

**Evidence:** amount-source trace table, checkout schema, webhook handler file with the verification lines highlighted, tests: invalid signature → 400, replayed event → no double processing, tampered amount → order not marked paid.

**Pass:** every amount is server-derived; webhook verification and idempotency tests pass.

---

### Check 6 — Rate Limits

**Goal:** abuse-prone and cost-generating routes are throttled.

Required minimum limits (adjustable per project, never removed):

| Route type | Limit | Key |
|---|---|---|
| Login | 5 / minute + 20 / hour | IP + email |
| Signup | 3 / minute, 10 / hour | IP |
| Password reset request | 3 / 15 minutes | IP + email |
| Password reset submit / OTP verify | 5 / 15 minutes | IP + token/email |
| Routes calling a paid API (AI, SMS, email, maps, payments) | 10 / minute, plus daily quota per user | user ID (IP if guest) |
| General authenticated API | 60 / minute | user ID |

Implementation:

- Laravel: named `RateLimiter::for()` limiters in `AppServiceProvider` (or `RouteServiceProvider` on older versions) and `throttle:` middleware on the routes; Laravel's built-in login throttling kept enabled. Limits use Redis in production so they work across servers.
- Next.js: a shared limiter (e.g. Redis/Upstash sliding window) called at the top of each route handler / middleware.

Response when exceeded: **HTTP 429** with `Retry-After` and `X-RateLimit-Limit` / `X-RateLimit-Remaining` headers and a JSON body:

```json
{ "message": "Too many attempts. Please try again in 60 seconds.", "retry_after": 60 }
```

Login/reset responses must not reveal whether the email exists.

**Evidence:** route → limit table, test output showing request N+1 returns 429 with `Retry-After`.

**Pass:** every listed route type has a limiter and a passing 429 test.

---

### Check 7 — Prove It (Cross-User Access Test)

**Goal:** executable proof that user B cannot touch user A's data.

For every user-owned resource, the QA agent generates and runs a test in which user A creates a record and user B tries to **read, update and delete** it. Every attempt must return **403 or 404** (404 preferred to avoid revealing existence), and the record must be unchanged afterwards.

Laravel (Pest) template:

```php
it('prevents user B from accessing user A\'s booking', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $booking = actingAs($userA)
        ->postJson('/api/bookings', ['service_id' => Service::factory()->create()->id, 'date' => now()->addDay()->toDateString()])
        ->assertCreated()
        ->json('data');

    actingAs($userB)->getJson("/api/bookings/{$booking['id']}")->assertStatus(fn ($s) => in_array($s, [403, 404]));
    actingAs($userB)->putJson("/api/bookings/{$booking['id']}", ['date' => now()->addDays(2)->toDateString()])->assertStatus(fn ($s) => in_array($s, [403, 404]));
    actingAs($userB)->deleteJson("/api/bookings/{$booking['id']}")->assertStatus(fn ($s) => in_array($s, [403, 404]));

    expect(Booking::find($booking['id']))->not->toBeNull()
        ->and(Booking::find($booking['id'])->date->toDateString())->toBe(now()->addDay()->toDateString());
});
```

(The QA agent adapts the template to the project's real routes, factories and PHPUnit vs Pest version; an equivalent Playwright/Vitest API test is used for Next.js.)

The tool runner executes the suite and the **raw test output** is attached to the gate report, e.g.:

```text
PASS  Tests\Feature\Security\CrossUserAccessTest
✓ prevents user B from accessing user A's booking
✓ prevents user B from accessing user A's invoice
Tests: 2 passed (12 assertions)
```

Coverage rule: the agent derives the resource list from Check 2's route matrix; any ID-bearing resource without a cross-user test fails the gate.

**Pass:** every resource covered; all tests pass.

---

## 74.3 Security Gate Report

The gate produces one report per release, stored in `security_reviews` and shown in the Security screen and the release approval dialog:

```text
SECURITY GATE — Booking SaaS @ 3f9c2ab           Result: FAILED (1 blocking)

1 Secrets            PASS   41 vars · 6 browser-exposed (all publishable) · 0 leaks
2 Ownership          PASS   58 routes · 31 ID-bearing · 31 verified · 0 trust userId
3 Database rules     PASS   MySQL — scopes verified · cross-user report attached
4 Mass assignment    PASS   24 write routes · 24 strict schemas · 96 forbidden-field tests
5 Payments           FAIL   CheckoutController.php:42 uses $request->amount   [BLOCKING]
6 Rate limits        PASS   9 limited routes · 429 tests passing
7 Prove it           PASS   31 resources · 31 tests · 0 failures

[View evidence]  [Open fix task EAJ-207]
```

## 74.4 When the Gate Runs

- Automatically before every staging and production deployment (Phase 11).
- On demand from the Security screen ("Run pre-deployment security gate").
- Nightly on the main branch of active projects, so regressions are found before release day.
- On first connection of an existing project, as a baseline audit (results do not block work, only deployment).

---

# 75. Multi-Tenancy

If EAJ itself supports multiple clients:

Every relevant database query must be organization-scoped.

Add automated cross-tenant tests.

Never depend only on frontend filtering.

---

# 76. Authentication and Authorization

Recommended EAJ roles:

```text
Super Admin
Organization Owner
Project Manager
Developer
Viewer
```

Permissions should be explicit.

---

# 77. Observability

Admin monitoring should display:

- Queue status.
- Failed jobs.
- AI latency.
- AI provider failures.
- Redis status.
- Database status.
- WebSocket status.
- Running agents.
- Stalled tasks.
- Worker health.
- Average task duration.
- Token usage.
- Cost.

---

# 78. Project Health Score

Health should be calculated from meaningful metrics.

Potential inputs:

- Progress.
- Blocked tasks.
- Failed tests.
- Review failures.
- Security findings.
- Budget usage.
- Repeated retries.

Document the formula.

Do not invent arbitrary health scores.

---

# 79. Agent Performance

Measure:

- Tasks completed.
- Review approval rate.
- Average quality score.
- Tokens/task.
- Cost/task.
- Retries/task.
- Bugs found after completion.
- Tests produced.
- Security findings.
- Failure rate.

---

# 80. Skills Performance

Track:

- Skill version.
- Tasks using skill.
- Success rate.
- Average review score.
- Average tokens.
- Average cost.
- Retry rate.

This allows improving skills over time.

---

# 81. Prompt Versioning

Every important agent prompt should have a version.

Example:

```text
Senior Backend Developer
v1.0
v1.1
v2.0
```

Track which version produced each task result.

Allow rollback.

---

# 82. Provider Abstraction

Create interfaces similar to:

```text
AIProviderInterface
AgentRunnerInterface
EmbeddingProviderInterface
RepositoryProviderInterface
ExecutionSandboxInterface
```

The default provider is a configuration choice, not a code decision. Configure at least **two providers** so every model tier has a fallback (Section 48.7).

Do not tightly couple the entire application to one provider.

---

# 83. Execution Sandbox

Agent commands should execute inside a controlled workspace.

Potential implementations:

- Local process sandbox.
- Docker container.
- Restricted worker process.
- Remote isolated worker.

Production deployments should prefer stronger isolation.

---

# 84. Approved Commands

Each project can maintain an approved command set.

Example:

```text
composer install
php artisan test
vendor/bin/phpunit
vendor/bin/pest
vendor/bin/pint
vendor/bin/phpstan analyse
npm install
npm ci
npm run lint
npm run test
npm run build
npx playwright test
```

Dangerous commands require approval or must be blocked.

---

# 85. Dangerous Command Protection

Examples requiring explicit approval or blocking:

```text
rm -rf
DROP DATABASE
TRUNCATE
git reset --hard
git clean -fd
git push --force
composer update
npm audit fix --force
production migrations
server shutdown
```

---

# 86. Package Installation

Agents must not add libraries casually.

Before adding a dependency:

1. Confirm it is needed.
2. Check existing project packages.
3. Prefer existing capabilities.
4. Review compatibility.
5. Explain impact.
6. Run tests afterward.

---

# 87. Definition of Done

A coding task cannot be completed solely because an agent says "done."

Typical requirements:

- Implementation completed.
- Formatter passed.
- Static analysis passed where configured.
- Relevant tests passed.
- Build passed.
- Acceptance criteria checked.
- QA passed.
- Senior review passed.
- Security passed when required.
- No unresolved blocker.
- Required human approval completed.

---

# 88. Implementation Phases

## Phase 1 — Foundation

Build:

- Authentication.
- Organizations.
- Roles/permissions.
- Projects.
- Project connection model.
- EAJ brand assets (vector logo set), design tokens, dark/light themes.
- Branded UI shell with animated loading/splash.
- Sidebar.
- Command Center.
- Basic settings.
- **Reliability foundation:** workflow tables, outbox events, idempotency keys, Horizon, health checks, nightly backups. (Built first so every later phase inherits it.)

---

## Phase 2 — Local Project Engine

Build:

- Local path registration.
- Project scanning.
- Stack detection.
- Git detection.
- Git initialization workflow.
- Worktree manager.
- Workspace lifecycle.
- Secret filtering.
- Project index.

This phase is critical because local project support should be available early.

---

## Phase 3 — Task Engine

Build:

- Tasks.
- Priorities.
- Dependencies.
- State machine.
- Kanban.
- Comments.
- Change-impact analysis.

---

## Phase 4 — Agent Engine

Build:

- Agent definitions.
- Roles.
- Skills.
- Model routing.
- Agent runs.
- Agent events.
- Token budgets.
- Retry limits.

---

## Phase 5 — Realtime

Build:

- Redis.
- Queues.
- Realtime transport.
- AI War Room.
- Agent presence and heartbeats.
- Live activity.
- Motion system (Section 7.6) bound to real events.
- Sequence-numbered catch-up API and reconnection handling.

---

## Phase 6 — AI Integration

Build:

- Provider abstraction.
- First AI provider integration + second provider as fallback.
- Circuit breaker and fallback routing.
- Structured outputs.
- Orchestrator.
- Specialist routing.
- Context builder.
- Prompt versions.
- Skill loading.

---

## Phase 7 — Automated Engineering Tools

Build:

- Command runner.
- Formatter runner.
- Test runner.
- Static analysis runner.
- Build runner.
- Frontend test runner.
- Playwright integration.
- Result parser.

---

## Phase 8 — QA and Review

Build:

- QA agent.
- Senior review.
- Security review.
- Quality gates.
- Review scoring.
- Changes-requested loop.

---

## Phase 9 — Cost Control

Build:

- Token tracking.
- Cached token tracking.
- Model pricing.
- Cost estimation.
- Project budgets.
- Task budgets.
- Alerts.
- Hard-stop policies.

---

## Phase 10 — GitHub Integration

Build:

- GitHub authentication.
- Repository import.
- Branches.
- Pull requests.
- Review sync.
- Merge workflow.

Local projects remain fully supported.

---

## Phase 11 — Deployment

Build:

- Deployment environments.
- Staging.
- Pre-Deployment Security Gate (Section 74.2) as a hard requirement.
- Production approval.
- Deployment logs.
- Release manager.
- Rollback support.

---

## Phase 12 — Production Hardening

Build:

- E2E testing.
- Permission tests.
- Multi-tenant tests.
- Run the Section 74.2 security gate against EAJ AI Dev Studio itself; it must pass before the Studio's own first production deployment.
- Rate limits.
- Observability.
- Backups.
- Error tracking.
- Chaos test suite (Section 48.11).
- Animation performance budget (60fps with 12 active agents).
- Performance tuning.
- Documentation and runbooks for every failure in Section 48.10.

---

# 89. Decided Technical Architecture

## 89.1 Overview

```text
┌──────────────────────────────── EAJ Studio Server ───────────────────────────────┐
│                                                                                  │
│  Laravel 12 + Inertia 2 + React 19 + TypeScript + Tailwind 4 (EAJ brand)         │
│  Auth · Orgs · Projects · Tasks · Approvals · Usage/Cost · Admin                 │
│        │                                                                         │
│        ├── MySQL 8 ─── source of truth (workflows, events outbox, audit)         │
│        ├── Redis ───── queues (Horizon), locks, halt flag, cache                 │
│        ├── Reverb ──── realtime WebSockets  ◄── outbox publisher                 │
│        └── Workers ─── AI Orchestrator · Context Builder · Gate evaluator        │
│                           │                                                      │
│                           ├── AI Providers (primary + fallback, circuit breaker) │
│                           └── Runner Gateway (authenticated WebSocket)           │
└───────────────────────────────────────────┬──────────────────────────────────────┘
                                            │ outbound TLS connection from the PC
┌───────────────────────────────────────────▼──────────────────────────────────────┐
│ EAJ Local Runner (on the developer machine — Windows/macOS/Linux)                │
│  Project scanner · Git worktree manager · Secret redactor · Sandboxed            │
│  command runner (approved commands only) · Test/lint/build output parsers        │
│  C:\laragon\www\…  (read-only)      C:\EAJ\workspaces\…  (agent writes here)     │
└──────────────────────────────────────────────────────────────────────────────────┘
```

## 89.2 Why a Local Runner

A web server cannot (and must not) reach into `C:\laragon\www\…` on your PC. The **EAJ Local Runner** is a small signed background service installed on each development machine:

- Connects **outbound** to the Studio (no open ports on the PC), authenticated with a per-machine token that can be revoked.
- Only the runner touches local files, Git and tool commands; the server sends it signed, idempotent instructions.
- Redacts secrets (Section 16) **before** any file content leaves the machine.
- Enforces the approved/dangerous command lists (Sections 84–85) locally, so even a compromised server cannot run `rm -rf`.
- Streams real file/command/test events — the source of all War Room animation.
- Queues results locally if the connection drops and replays them on reconnect.
- Single-user option: the Studio and runner can run together on the same Laragon machine for solo use.

## 89.3 Why not Next.js + separate Laravel API for v1

One codebase, one deployment, one auth system and one set of tests = fewer failure points, which directly serves Section 2.6. The provider, runner and repository layers stay behind interfaces (Section 82), so a separate frontend can be added later without redesign.

---

# 90. Deployment Topology

```text
Solo / small team (start here)
  1 VPS (4 vCPU, 8 GB): Nginx + PHP-FPM + MySQL + Redis + Reverb + Horizon
  + EAJ Local Runner on each developer PC
  Nightly backups to off-server storage; Supervisor keeps Horizon/Reverb alive

Growth
  App/Reverb server · Worker server(s) · Managed MySQL · Managed Redis
  Remote isolated runners (Docker) for GitHub-only projects
```

Health checks (`/health` for DB, Redis, Reverb, Horizon, runner count, provider circuits) are monitored externally with alerts to the owner.

---

# 91. UI Component Architecture

Recommended reusable components:

```text
AppShell
Sidebar
Topbar
ProjectSwitcher
AgentStatusBadge
TaskStatusBadge
PriorityBadge
CostBadge
TokenMeter
HealthScore
ActivityTimeline
AgentCard
ProjectCard
TaskCard
KanbanBoard
QualityGatePanel
TestResultPanel
CodeReviewPanel
SecurityFindingPanel
DiffViewer
CommandLog
WorkspacePanel
ApprovalDialog
BudgetMeter
RealtimeStatusIndicator
EajLogo / EajMark (themed SVG variants)
AgentAvatar + AgentStatusRing (motion per Section 7.6)
GatePipeline (animated)
AnimatedNumber
RunnerStatusBanner
DemoBadge
```

---

# 92. Modern Frontend Standards

For the EAJ product UI:

- TypeScript required.
- Reusable components.
- Inertia page props kept small; realtime data merged from the event stream.
- Motion components respect `prefers-reduced-motion` and the in-app Reduce Motion setting.
- Accessible forms.
- Keyboard support.
- Proper loading skeletons.
- Error boundaries.
- Optimistic updates only where safe.
- Realtime state reconciliation.
- Avoid giant global state stores unless justified.
- Avoid unnecessary frontend dependencies.

---

# 93. Laravel Standards

For the EAJ backend:

- Form Request validation where appropriate.
- Policies/Gates for authorization.
- Service/Action layer when useful.
- Transactions for multi-step writes.
- Queue heavy operations.
- Events for realtime state changes.
- Database indexes.
- Strict tenant scoping.
- Avoid N+1 queries.
- Proper exception handling.
- Feature tests for critical flows.

---

# 94. API Standards

APIs should use:

- Stable JSON structures.
- Consistent errors.
- Pagination.
- Filtering.
- Sorting.
- Authorization.
- Rate limiting.
- Idempotency where needed.
- Request IDs for traceability.

---

# 95. User Control

The user must be able to:

- Start task.
- Pause task.
- Resume task.
- Stop task.
- Send new instruction.
- Request changes.
- Approve.
- Reject.
- Merge.
- View diff.
- View tests.
- View logs.
- View costs.
- Change model routing.
- Change task budget.

---

# 96. AI Confidence

Agents may report:

```text
HIGH
MEDIUM
LOW
```

Low confidence should trigger stronger review for important tasks.

Confidence is not proof of correctness.

---

# 97. Priority Levels

Support:

```text
CRITICAL
HIGH
MEDIUM
LOW
```

Priority may influence:

- Queue order.
- Model routing.
- Review depth.
- Notification behavior.

---

# 98. Review Severity

Support:

```text
BLOCKER
MAJOR
MINOR
SUGGESTION
```

Blocker/Major should prevent approval.

---

# 99. Security Severity

Support:

```text
CRITICAL
HIGH
MEDIUM
LOW
INFORMATIONAL
```

Critical issues block release automatically.

High issues normally block release.

---

# 100. First Production Milestone

The first useful version should support:

- Modern login.
- Organization/project structure.
- Local project connection.
- Laravel 7+ detection.
- React detection.
- Next.js detection.
- Vue detection.
- Blade detection.
- Tailwind/Bootstrap detection.
- Git project detection.
- Safe worktree creation.
- Task creation.
- AI Team screen.
- AI War Room.
- Agent statuses.
- Realtime events.
- Basic Skills system.
- Model configuration.
- Token usage architecture.
- Cost architecture.
- Basic QA gate.
- Basic senior review gate.
- Pre-Deployment Security Gate with all 7 checks (Section 74.2).
- Demo mode.
- EAJ-branded, responsive UI with the agent motion system.
- EAJ Local Runner (Windows first, since projects live in Laragon/XAMPP).
- Reliability foundation: durable workflows, checkpoints, outbox + catch-up API, idempotency, emergency stop, merge safety checks.
- Chaos tests for crash, disconnect and malformed-AI-output scenarios.

---

# 101. Initial Local-Project User Flow

Example:

```text
1. Open EAJ AI Dev Studio
2. Projects
3. New Project
4. Choose Local Repository
5. Select:
   C:\laragon\www\booking-saas
6. Scan Project
7. Review detected technologies
8. Connect
9. Create Task
10. Enter:
    "Add monthly revenue dashboard with export to Excel."
11. Review impact analysis
12. Start Development
13. Watch AI War Room
14. Inspect diff
15. Review tests
16. Approve
17. Merge branch
```

---

# 102. Critical Safety Rule

Never default to:

```text
AI
 ↓
Edit production/local main files directly
```

Always prefer:

```text
AI
 ↓
Isolated Workspace
 ↓
Tests
 ↓
Review
 ↓
Human Approval
 ↓
Merge
```

---

# 103. Critical Token Rule

Never default to:

```text
Entire Repository
        ↓
Every Agent
        ↓
Every Review
```

Always prefer:

```text
Task
+ Relevant Files
+ Related Tests
+ Diff
+ Minimal Architecture Context
        ↓
Relevant Agent
```

---

# 104. Critical UX Rule

Never make the UI look busy only to appear advanced.

Every visual element must help the owner answer at least one of these questions:

- What is happening?
- Who is responsible?
- What changed?
- Is it correct?
- Is it safe?
- What failed?
- What needs me?
- What will it cost?
- Can I release it?

---

# 105. Final Product Standard

EAJ AI Dev Studio must feel like:

```text
GitHub
+
Linear
+
AI Engineering Team
+
QA Platform
+
Security Review Platform
+
Realtime Development Operations Center
```

but remain clearly branded and designed for:

**EAJ Web Development Services**

— navy `#0A1034`, crimson `#D61A5C`, white, the EA monogram, and motion that only moves when real work is happening.

The platform must support both modern and legacy client projects without forcing unnecessary migrations.

Primary compatibility targets include:

- Laravel 7+
- PHP
- React
- Next.js
- Vue
- Blade
- Inertia
- JavaScript
- TypeScript
- Tailwind
- Bootstrap
- Vite
- Laravel Mix / Webpack
- MySQL
- Redis

The system must remain extensible for future frameworks and technologies.

---

# 106. Final Engineering Instruction

When implementing this plan:

1. Inspect the existing EAJ AI Dev Studio repository first.
2. Preserve working functionality.
3. Build features incrementally.
4. Write migrations carefully.
5. Use framework/version-aware tooling.
6. Test after every meaningful change.
7. Run formatter/static analysis/build tools.
8. Fix failures before continuing.
9. Avoid placeholder production behavior.
10. Avoid fake realtime activity.
11. Avoid excessive AI token usage.
12. Use deterministic tools wherever possible.
13. Protect local projects with isolated workspaces.
14. Protect secrets.
15. Require human approval for destructive or production-sensitive operations.
16. Keep the UI modern, responsive, accessible, and professional.
17. Treat React, Next.js, and Laravel 7+ as first-class supported project types.
18. Ensure the architecture can add more stacks without redesigning the entire system.
19. Use only EAJ design tokens — no hard-coded colours outside `tokens.css`.
20. Bind every animation to persisted state; never animate on a timer in production mode.
21. Uphold the reliability guarantees G1–G7 (Section 2.6); any change that weakens one requires an ADR.

---

# 107. Recommended Build Priority

If starting from zero, implement in this order:

```text
0. Reliability foundation (workflow tables, outbox, idempotency, backups)
1. EAJ Brand Assets + Branded UI Shell
2. Authentication
3. Projects
4. EAJ Local Runner + Local Project Scanner
5. Git Worktree Manager + Checkpoints
6. Tasks
7. Agent Definitions
8. Realtime Agent Events
9. AI War Room
10. Skills
11. AI Provider Integration
12. Context Builder
13. Tool Runner
14. QA Gates
15. Senior Review
16. Token / Cost Tracking
17. Security Review
18. GitHub Integration
19. Deployment
20. Advanced Analytics
```

Local Repository Mode should be functional before attempting complex remote integrations.

---

# 108. Success Criteria

EAJ AI Dev Studio is successful when the owner can connect a real local Laravel, React, Next.js, or mixed-stack project and issue a normal request such as:

```text
"Add a customer loyalty dashboard.
Use the existing design.
Do not change the payment module.
Add tests.
Make sure mobile works."
```

and the system can:

1. Understand the stack.
2. Find relevant files.
3. Select relevant agents.
4. Create a safe workspace.
5. Implement the feature.
6. Run appropriate framework tools.
7. Test the result.
8. Review the result.
9. Show all activity in realtime.
10. Show token/cost usage.
11. Present the final diff.
12. Ask the owner for approval.
13. Merge only when approved.
14. Refuse to deploy until the 7-check security gate passes with real evidence.
15. Survive a worker crash, a lost connection or an AI provider outage mid-task without losing work or showing a false status.

That is the core product experience.
