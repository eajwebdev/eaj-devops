# EAJ Local Runner

The only part of EAJ AI Dev Studio that touches project folders and Git on a developer PC (Plan §89.2). It connects **out** to the Studio over HTTPS (plain HTTP only to localhost) — the PC opens no ports. No dependencies; Node.js 18.18+.

## Set up a PC

1. In the Studio: **Settings → Local runners → Pair a runner** (shows a one-time code, valid 10 minutes).
2. On the PC:

```bash
node runner/bin/eaj-runner.js pair https://studio.example.com ABCD-2345 --name "Laragon PC"
node runner/bin/eaj-runner.js allow "C:\laragon\www"        # folders the Studio may scan
node runner/bin/eaj-runner.js workspaces "C:\EAJ\workspaces" # where task worktrees go (default)
node runner/bin/eaj-runner.js tool-path php "C:\xampp\php\php.exe"   # only if php is not on PATH
node runner/bin/eaj-runner.js start                          # keep running
```

`eaj-runner tools <folder>` lists the engineering tools the runner would run for a project (nothing is run).

`eaj-runner scan <folder>` prints a local scan without contacting the Studio. Config lives in `%APPDATA%\EAJ\Runner` (Windows), `~/Library/Application Support/EAJ Runner` (macOS) or `~/.config/eaj-runner` (Linux); `EAJ_RUNNER_HOME` overrides it.

## What it enforces locally (a compromised Studio cannot change this)

- Reads only folders allowed with `eaj-runner allow`; real paths are compared, so junctions/symlinks/8.3 names cannot escape.
- Runs only HMAC-signed, fresh (≤ 15 min) commands from its own list: `project.scan`, `project.index`, `git.init`, `workspace.create|checkpoint|reset|remove|read|apply|diff`, `tool.run`. There is no "run arbitrary command".
- Agents change code only through `workspace.apply`: inside a task workspace, all files or none, and only when each file is byte-for-byte what the agent read (a SHA-1 check). Never `.git`, `vendor`, `node_modules`, secret files, files that contain credentials, or content with a `[REDACTED]` marker. `workspace.read` sends contents redacted.
- `tool.run` names a project tool by key (`php.test`, `js.build`, …). The runner finds that tool in the **workspace's own** files (composer.json, package.json scripts, tool configs) and builds the command itself — only PHP, Composer, Node and package managers, never through a shell, only inside a task workspace. Scripts containing dangerous commands (`rm -rf`, `git push --force`, …) are refused. Tools see a minimal environment (no tokens, cloud keys or `DB_*`), and PHP tests never use the project's development database: without a test database configured they get in-memory SQLite. Stop AI or cancel kills the whole process tree.
- Git only, through argument arrays (no shell), against an allow-list: no push, no branch deletion, no config writes, no stash pop/drop; `reset --hard` / `clean` only inside a task workspace; commits use `--no-verify` so project hooks never run.
- Never reads `.env*`, keys, certificates or credential files' contents; env files become *shape* (`DB_CONNECTION=mysql`, `DB_PASSWORD=<set>`); credential-shaped text is redacted before anything leaves the PC.
- Each finished command's outcome is kept locally: a re-delivered command is answered from that record, never run twice; results that could not be sent are replayed on reconnect.

## Develop

```bash
cd runner
npm test           # node:test, real Git repos in temp folders
npm run typecheck  # JSDoc types, strict (uses the Studio's TypeScript)
```
