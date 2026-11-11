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

Drivers (`FACTORY_RUNNER_DRIVER`): `local` runs Claude Code; `simulated` writes placeholder Spec Kit files with no AI and no cost, for trying the board (refused in production); a `docker` driver (one container per run) comes with server readiness.

## How the board works (built in Phase 4)

Columns: Backlog → Specify → Clarify → Plan → Tasks → Analyze → Implement → Review → Done

- A card is a feature. Moving it into a phase column starts that phase's run (`/speckit-specify`, `-clarify`, `-plan`, `-tasks`, `-analyze`, `-implement`).
- Cards move forward only. A phase must finish ("Ready for review") before the card moves on. Clarify and Analyze are optional: dragging past them skips them; the other phases can't be skipped.
- Approving can carry notes, passed to the next command (e.g. the tech stack for Plan).
- Replying continues the same Claude session (`--resume`): answers to Clarify's questions, or requested changes to any phase.
- Auto mode starts the next phase by itself, except after Clarify (its questions need a person) and after a reply.
- First Specify run: the app picks a feature number above every `specs/NNN-*` on the base branch and every other ticket of the project, so parallel features never collide. Branch `NNN-short-title`, folder `specs/NNN-short-title`.
- Every run: checks out the ticket's branch in `worktrees/ticket-{id}` (a stable folder, which Claude needs to resume sessions), passes `SPECIFY_FEATURE` and `SPECIFY_FEATURE_DIRECTORY`, commits "Phase: Title (#n)", pushes, and copies the feature folder's files (spec.md, plan.md, tasks.md, …) into the database for the ticket panel.
- The agent is told it runs unattended: ask questions in its final message, don't commit or switch branches.
- After Implement the card goes to Review, then Done (both moved by hand until Phase 5 adds PRs and webhooks).
- Every team member can create and move cards; project settings and Spec Kit setup stay with owners and admins.

## Data model

- `users` (+ `is_super_admin`, `current_team_id`), `teams`, `team_members`, `team_invitations` — **Phase 1**
- `credentials` — owner is a team or a user; type github / gitlab / anthropic; encrypted token — **Phase 2**
- `projects` — team, repo, credential, default model / effort / approval, merge policy — **Phase 2**
- `runs` — type, status, model, effort, branch, commit, PR, tokens, cost, session id — **Phase 3**
- `run_events` — the run's log lines — **Phase 3**
- `tickets` — number, title, description, stage, status, feature number / branch / folder, model, effort, approval mode, skip flags, Claude session id — **Phase 4**
- `ticket_documents` — copies of the feature folder's files — **Phase 4**
- `runs.ticket_id`, `runs.result_text` (the agent's final message) — **Phase 4**
- `activity_log` — later

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
4. **Board** ✅
    - Kanban board on the project page with drag and drop (dnd-kit); refreshes every 3 s while anything runs
    - New feature dialog (title, description, model, effort, approval mode, skip Clarify / Analyze)
    - Ticket panel: start / approve with notes / reply / retry / cancel, the agent's last message (questions, report, summary), rendered documents, run history, settings, delete
    - Feature branch and folder per ticket; one commit per phase; session resume for replies (see "How the board works")
    - `simulated` runner driver for trying the board without an API key
5. **Git automation** — open the PR/MR after Implement, merge policies, webhooks that move cards to Done, rebase and conflict handling, optional `/speckit-converge`
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
