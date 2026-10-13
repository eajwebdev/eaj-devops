# EAJ Local Runner

The only part of EAJ AI Dev Studio that touches project folders and Git on a developer PC (Plan §89.2). It connects **out** to the Studio over HTTPS (plain HTTP only to localhost) — the PC opens no ports. No dependencies; Node.js 18.18+.

## Set up a PC

1. In the Studio: **Settings → Local runners → Pair a runner** (shows a one-time code, valid 10 minutes).
2. On the PC:

```bash
node runner/bin/eaj-runner.js pair https://studio.example.com ABCD-2345 --name "Laragon PC"
node runner/bin/eaj-runner.js allow "C:\laragon\www"        # folders the Studio may scan
node runner/bin/eaj-runner.js workspaces "C:\EAJ\workspaces" # where task worktrees go (default)
node runner/bin/eaj-runner.js start                          # keep running
```

`eaj-runner scan <folder>` prints a local scan without contacting the Studio. Config lives in `%APPDATA%\EAJ\Runner` (Windows), `~/Library/Application Support/EAJ Runner` (macOS) or `~/.config/eaj-runner` (Linux); `EAJ_RUNNER_HOME` overrides it.

## What it enforces locally (a compromised Studio cannot change this)

- Reads only folders allowed with `eaj-runner allow`; real paths are compared, so junctions/symlinks/8.3 names cannot escape.
- Runs only HMAC-signed, fresh (≤ 15 min) commands from its own list: `project.scan`, `project.index`, `git.init`, `workspace.create|checkpoint|reset|remove`. There is no "run arbitrary command".
- Git only, through argument arrays (no shell), against an allow-list: no push, no branch deletion, no config writes, no stash pop/drop; `reset --hard` / `clean` only inside a task workspace; commits use `--no-verify` so project hooks never run.
- Never reads `.env*`, keys, certificates or credential files' contents; env files become *shape* (`DB_CONNECTION=mysql`, `DB_PASSWORD=<set>`); credential-shaped text is redacted before anything leaves the PC.
- Each finished command's outcome is kept locally: a re-delivered command is answered from that record, never run twice; results that could not be sent are replayed on reconnect.

## Develop

```bash
cd runner
npm test           # node:test, real Git repos in temp folders
npm run typecheck  # JSDoc types, strict (uses the Studio's TypeScript)
```
