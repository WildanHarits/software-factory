# Deploying Software Factory

A guide for one Ubuntu 24.04 server running everything: the app, MySQL, Redis, Horizon, and a Docker container per agent run. Commands assume the domain `factory.example.com` and the app in `/srv/software-factory`.

## What runs where

| Piece                                                   | Runs as                                                       |
| ------------------------------------------------------- | ------------------------------------------------------------- |
| Web app                                                 | PHP-FPM behind Caddy (Caddy handles HTTPS certificates)       |
| Queue workers, including agent runs                     | Horizon, kept alive by supervisor                             |
| Pull request sync, stuck-run recovery, pruning, backups | Laravel scheduler, from cron                                  |
| Agent                                                   | One Docker container per run (`FACTORY_RUNNER_DRIVER=docker`) |
| git, `specify init`                                     | The app's user, on the host                                   |

Size: 2 vCPU / 4 GB RAM is enough for the app plus one or two runs at once. Each run may use up to `FACTORY_DOCKER_MEMORY` (4 GB) and `FACTORY_DOCKER_CPUS` (2), so scale the server with `FACTORY_PARALLEL_RUNS`.

## 1. Packages

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y git unzip curl supervisor mysql-server redis-server \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-bcmath php8.3-intl
```

Composer, Node 22, Docker and Caddy come from their own repositories:

```bash
curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/usr/local/bin --filename=composer
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash - && sudo apt install -y nodejs
curl -fsSL https://get.docker.com | sudo sh
sudo apt install -y debian-keyring debian-archive-keyring apt-transport-https
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | sudo gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | sudo tee /etc/apt/sources.list.d/caddy-stable.list
sudo apt update && sudo apt install -y caddy
```

## 2. The app user

```bash
sudo adduser --disabled-password --gecos "" factory
sudo usermod -aG docker factory      # lets the app start runner containers
sudo usermod -aG factory www-data    # lets PHP-FPM read the app
```

Being in the `docker` group is effectively root on this machine. Use a server dedicated to Software Factory.

Make PHP-FPM run as `factory` so web requests and Horizon write files as the same user: in `/etc/php/8.3/fpm/pool.d/www.conf` set `user = factory` and `group = factory`, then `sudo systemctl restart php8.3-fpm`.

## 3. Database and Redis

```bash
sudo mysql -e "CREATE DATABASE software_factory CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER 'software_factory'@'localhost' IDENTIFIED BY 'CHANGE-ME';
  GRANT ALL PRIVILEGES ON software_factory.* TO 'software_factory'@'localhost';"
```

Redis listens on localhost only by default; keep it that way.

## 4. The code

```bash
sudo mkdir -p /srv/software-factory /srv/software-factory-workspaces
sudo chown factory:factory /srv/software-factory /srv/software-factory-workspaces
sudo chmod 700 /srv/software-factory-workspaces
sudo -iu factory
git clone <your repository> /srv/software-factory && cd /srv/software-factory
cp deploy/env.production.example .env      # fill in APP_URL, DB_PASSWORD, MAIL_*
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan key:generate
php artisan migrate --force
php artisan optimize
php artisan app:create-super-admin
```

**Save the `APP_KEY` from `.env` somewhere safe** (a password manager). Tokens are encrypted with it; a database backup is useless without it.

## 5. Spec Kit CLI and the runner image

Still as `factory`:

```bash
curl -LsSf https://astral.sh/uv/install.sh | sh
uv tool install specify-cli --from git+https://github.com/github/spec-kit.git@v1.4.0
docker build -t software-factory-runner:latest docker/runner
```

The image has Claude Code, git, bash, jq and ripgrep. If your projects need their own toolchain during Implement (PHP, Go, …), build an image on top of it and set `FACTORY_DOCKER_IMAGE` (see the comments in `docker/runner/Dockerfile`).

## 6. Horizon and the scheduler

```bash
sudo cp /srv/software-factory/deploy/supervisor/software-factory-horizon.conf /etc/supervisor/conf.d/
sudo supervisorctl reread && sudo supervisorctl update
sudo crontab -u factory /srv/software-factory/deploy/crontab
```

## 7. HTTPS

Point the domain's DNS at the server, then:

```bash
sudo cp /srv/software-factory/deploy/Caddyfile /etc/caddy/Caddyfile   # change the domain
sudo systemctl reload caddy
sudo ufw allow OpenSSH && sudo ufw allow 80 && sudo ufw allow 443 && sudo ufw enable
```

## 8. Check it

```bash
cd /srv/software-factory && php artisan factory:doctor
```

It checks the runner (Docker and the image, git, Spec Kit), Horizon, the scheduler, HTTPS, cookies, mail and backups, and exits with an error if something would break.

## 9. Webhooks

With a public `APP_URL`, open each project's **Settings → Pull request updates** and press **Add the webhook**. Merged pull requests then move cards to Done right away. Without webhooks, open pull requests are still checked every 5 minutes.

## Updating

```bash
sudo -iu factory
cd /srv/software-factory && ./deploy/deploy.sh
```

The script pulls, installs, builds, migrates, caches, restarts Horizon (running agent runs finish first) and runs the doctor.

To upgrade Claude Code in the runner: `docker build --build-arg CLAUDE_CODE_VERSION=<version> -t software-factory-runner:latest docker/runner`.
To upgrade Spec Kit: install the new CLI version, set `SPECKIT_VERSION`, and check the command names in `config/factory.php`.

## Backups and restore

`factory:backup` runs every night at 02:30 and keeps 14 days in `storage/app/private/backups`. For a copy off the server, set `FACTORY_BACKUP_DISK=s3` and the `AWS_*` values.

Restore:

```bash
gunzip -c storage/app/private/backups/software-factory-YYYY-MM-DD-HHMMSS.sql.gz | mysql -u software_factory -p software_factory
```

Then put the saved `APP_KEY` back in `.env`.

The workspace folder (`/srv/software-factory-workspaces`) doesn't need backups: it holds clones that are rebuilt from the repositories.

## Security notes

- Each agent run is a fresh container: it sees only its worktree and the project's Claude config folder, runs as the app's user with no Linux capabilities, and has memory, CPU and process limits. It never gets the git token or the app's `.env`.
- Containers can reach the internet (package registries, the Anthropic API). To restrict that, create a Docker network with an egress proxy and set `FACTORY_DOCKER_NETWORK`.
- `FACTORY_MAX_BUDGET_USD` caps what one run can spend.
- `/horizon` is only open to super admins.
