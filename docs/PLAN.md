# Software Factory — Build Plan

A web app for teams to run [Spec Kit](https://speckit.org/) (spec-driven development) on their git repos.
A Kanban board tracks each feature through the Spec Kit phases, and an AI agent (Claude Code) does the work in background jobs.

## Decisions

| Topic | Decision |
|---|---|
| Framework | Laravel 13, official React starter kit (teams branch) |
| Frontend | Inertia + React + TypeScript, shadcn/ui, dnd-kit for the board |
| Database | MySQL |
| Users | Teams. A user can be in many teams. Roles: owner / admin / member |
| Sign-up | None. Super admins create accounts; team owners/admins invite existing users |
| Tokens | GitHub, GitLab and Anthropic tokens, at team level plus optional personal tokens |
| Phases | Each ticket chooses manual approval or auto; default is manual approval |
| Model / effort | Default per project, override per ticket (and optionally per phase) |
| Git | Commit after each phase, open PR/MR automatically, merge policy per project |
| Hosting | Local Mac first (no Docker), server later |

## How a phase runs

1. Triggered by dragging the card, or automatically when approval is set to auto.
2. `RunPhase` job fetches the repo and checks out the feature branch in its own worktree.
3. A runner executes `claude -p "/speckit.<phase> …" --model … --output-format stream-json`.
   - `local` driver (now): runs on this machine, limited to a dedicated workspace folder with Claude Code permission rules and sandbox.
   - `docker` driver (server): each run in its own container.
   - Switch with `RUNNER_DRIVER` in `.env`.
4. Output streams live to the ticket page (Reverb). Token usage and cost are saved per run.
5. `/clarify` questions show as a form; answers resume the same Claude session (`--resume`).
6. Commit and push. Then wait for approval, or queue the next phase.
7. After `/implement`: rebase on main, open the PR/MR, apply merge policy. A webhook moves the card to Done when merged.

Board columns: Backlog → Specify → Clarify → Plan → Tasks → Analyze → Implement → Review → Done

## Data model (planned)

- `users` (+ `is_super_admin`, `current_team_id`), `teams`, `team_members`, `team_invitations` — **done in Phase 1**
- `credentials` — owner is a team or a user; type github / gitlab / anthropic; encrypted token
- `projects` — team, repo, credential, default model / effort / approval, merge policy
- `tickets` — feature: title, description, column, position, branch, model, effort, approval mode, PR/MR info
- `runs` — per phase run: status, model, effort, tokens, cost, commit SHA, log, Claude session id
- `clarifications`, `approvals`, `activity_log`

## Phases

1. **Foundation** ✅
   - Laravel 13 + Inertia React teams starter kit, MySQL
   - Login only (registration and email verification removed); 2FA, passkeys, password confirmation kept
   - Teams, roles, invitations (existing users only), team switcher
   - Super admin: `/admin/users` to create, edit, reset passwords, delete users
   - `php artisan app:create-super-admin`
2. **Credentials & projects** — team/personal tokens checked against the provider API; create new repo or connect existing; `specify init`; constitution editor
3. **Runner** — runner interface with local driver, `RunPhase` job, Horizon + Redis, live logs via Reverb, cost tracking, cancel
4. **Board** — Kanban with phase columns, ticket settings (model / effort / approval), approval gates, clarify Q&A, spec/plan/tasks viewer
5. **Git automation** — commit per phase, PR/MR creation, merge policies, webhooks, conflict handling
6. **Server readiness** — Docker runner driver, production config, HTTPS, backups

## Local setup

```bash
brew install redis uv          # needed from Phase 3
composer install && npm install
cp .env.example .env && php artisan key:generate   # then fill in DB_PASSWORD
php artisan migrate
php artisan app:create-super-admin
composer run dev
```

For local test data instead: `php artisan migrate:fresh --seed` (logins are listed in `database/seeders/DatabaseSeeder.php`).

## Notes

- Spec Kit command names differ by version (`/specify` vs `/speckit.specify`); pin the version and keep names in config.
- Confirm the exact Claude Code flag for effort when building the runner.
- Webhooks need a public URL: a tunnel (Cloudflare Tunnel / ngrok) while running on the Mac.
