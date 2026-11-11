# Software Factory — Build Plan

A web app for teams to run [Spec Kit](https://speckit.org/) (spec-driven development) on their git repos.
A Kanban board tracks each feature through the Spec Kit phases, and an AI agent (Claude Code) does the work in background jobs.

## Decisions

| Topic          | Decision                                                                         |
| -------------- | -------------------------------------------------------------------------------- |
| Framework      | Laravel 13, official React starter kit (teams branch)                            |
| Frontend       | Inertia + React + TypeScript, shadcn/ui, dnd-kit for the board                   |
| Database       | MySQL                                                                            |
| Users          | Teams. A user can be in many teams. Roles: owner / admin / member                |
| Sign-up        | None. Super admins create accounts; team owners/admins invite existing users     |
| Tokens         | GitHub, GitLab and Anthropic tokens, at team level plus optional personal tokens |
| Phases         | Each ticket chooses manual approval or auto; default is manual approval          |
| Model / effort | Default per project, override per ticket (and optionally per phase)              |
| Git            | Commit after each phase, open PR/MR automatically, merge policy per project      |
| Hosting        | Local Mac first (no Docker), server later                                        |
| Spec Kit       | v1.4.0, Claude integration. Commands are skills: `/speckit-specify` etc.         |

## How a run works (built in Phase 3)

1. A run is queued on the `runs` queue (`redis-runs` connection) and Horizon picks it up.
2. The project has one clone under `FACTORY_WORKSPACE_PATH`; each run gets its own git worktree on a new branch, so runs never share files.
3. Steps run in order and each writes to the run's log: e.g. `specify init`, then the agent.
4. The agent is Claude Code: `claude -p "<prompt>" --bare --output-format stream-json --model … --effort … --permission-mode acceptEdits --permission-prompts none --settings <sandbox>`.
    - `--bare`: no personal settings, hooks, plugins, memory or keychain login; only the project's Anthropic key.
    - Own config folder per project (`CLAUDE_CONFIG_DIR`), so sessions can be resumed later (for `/speckit-clarify`).
    - Sandbox (macOS Seatbelt / Linux bubblewrap) for shell commands: writes only in the worktree, network only to `FACTORY_SANDBOX_DOMAINS`, the app folder and secret folders (`~/.ssh`, `~/.aws`, …) unreadable. Refuses to run if the sandbox isn't available.
    - Web tools off; anything needing permission is denied; spend capped by `FACTORY_MAX_BUDGET_USD`.
    - Child processes get a clean environment (no `APP_KEY`, `DB_PASSWORD`, …). The agent never gets the git token.
5. The app commits (as "Software Factory"), pushes the branch with the token via `GIT_ASKPASS` (never in URLs, arguments or the keychain) and opens a PR/MR.
6. Cost, tokens, turns and the Claude session id are saved on the run. Cancel stops the agent within ~2 seconds.
7. The run page polls for new log lines every 1.5 s while the run is active.

Drivers: `FACTORY_RUNNER_DRIVER=local` now; a `docker` driver (one container per run) comes with server readiness.

Board columns (Phase 4): Backlog → Specify → Clarify → Plan → Tasks → Analyze → Implement → Review → Done

## Data model

- `users` (+ `is_super_admin`, `current_team_id`), `teams`, `team_members`, `team_invitations` — **Phase 1**
- `credentials` — owner is a team or a user; type github / gitlab / anthropic; encrypted token — **Phase 2**
- `projects` — team, repo, credential, default model / effort / approval, merge policy — **Phase 2**
- `runs` — type, status, model, effort, branch, commit, PR, tokens, cost, session id — **Phase 3**
- `run_events` — the run's log lines — **Phase 3**
- `tickets` — feature: title, description, column, position, branch, model, effort, approval mode, PR/MR info — Phase 4 (runs get a `ticket_id`)
- `clarifications`, `approvals`, `activity_log` — Phase 4

## Phases

1. **Foundation** ✅
    - Laravel 13 + Inertia React teams starter kit, MySQL
    - Login only (registration and email verification removed); 2FA, passkeys, password confirmation kept
    - Teams, roles, invitations (existing users only), team switcher
    - Super admin: `/admin/users` to create, edit, reset passwords, delete users
    - `php artisan app:create-super-admin`
2. **Credentials & projects** ✅
    - Team tokens (Settings → Teams → team → Tokens) and personal tokens (Settings → Tokens) for GitHub, GitLab (incl. self-hosted) and Anthropic
    - Tokens encrypted at rest, never sent to the browser (only the last 4 characters), checked against the provider on save
    - Owners/admins manage team tokens and projects; members view
    - Projects: create a new repo (account, org or group) or connect an existing one; push access is checked; Spec Kit detected by its `.specify` folder
    - Per-project git token, Anthropic key, default model / effort, approval mode, merge policy (`config/factory.php` lists models and efforts)
3. **Runner** ✅
    - Redis + Horizon (`/horizon`, super admins only); `composer run dev` starts Horizon instead of a plain queue worker
    - Local runner with sandboxing and secret isolation (see "How a run works")
    - Project page: "Set up Spec Kit" (runs `specify init`, optionally writes the constitution, opens a PR) and "Update constitution" (agent edits it, opens a PR)
    - Run page with live log, model / effort / duration / cost / tokens, PR link, cancel
    - Health check on the project page: git, Claude Code, Spec Kit version, workspace folder, Horizon
    - Live updates use polling instead of Reverb (fewer moving parts locally); Reverb can come with the board if needed
4. **Board** — tickets as cards in phase columns; each phase is a run (`/speckit-specify`, `-clarify`, `-plan`, `-tasks`, `-analyze`, `-implement`); approval gates; clarify Q&A resumes the Claude session; spec/plan/tasks viewer
5. **Git automation** — one branch per feature across phases, PR/MR after implement, merge policies, webhooks, conflict handling
6. **Server readiness** — Docker runner driver, production config, HTTPS, backups

## Local setup

```bash
brew install redis uv
brew services start redis
uv tool install specify-cli --from git+https://github.com/github/spec-kit.git@v1.4.0
composer install && npm install
cp .env.example .env && php artisan key:generate   # then fill in DB_PASSWORD
php artisan migrate
php artisan app:create-super-admin
composer run dev        # web server, Vite, Horizon, logs
```

Runner settings live in `.env` (`FACTORY_*`, `CLAUDE_BIN`, `SPECIFY_BIN`, `GIT_BIN`) and `config/factory.php`.
After changing PHP code, restart Horizon (it doesn't reload code on its own): stop and start `composer run dev`, or `php artisan horizon:terminate`.

For local test data instead: `php artisan migrate:fresh --seed` (logins are listed in `database/seeders/DatabaseSeeder.php`).

## Notes

- Spec Kit command names differ by version (v1.4: `/speckit-specify`; older: `/speckit.specify` or `/specify`). They live in `config/factory.php`; update them together with the CLI version.
- Upgrading Spec Kit: install the new CLI version, then set `SPECKIT_VERSION` and check the command names.
- A run that's killed hard (e.g. the machine sleeps) can stay "Running"; a cleanup for stuck runs is still to do.
- Webhooks need a public URL: a tunnel (Cloudflare Tunnel / ngrok) while running on the Mac.
