# Deployment (Plesk / AlmaLinux)

Use PHP 8.3+, MySQL 8, Redis, Node 22+, Chromium dependencies, Supervisor for `php artisan horizon`, and systemd for `automation-worker`. The browser service must bind only to `127.0.0.1:3100`.

Before production:

1. Copy `.env.example` to `.env`, generate `APP_KEY`, and generate a separate random `AUTOMATION_HMAC_KEY`.
2. Keep credentials only in the server environment. Never commit `.env` or browser profiles.
3. Run `composer install --no-dev --optimize-autoloader`, `php artisan migrate --force`, `npm ci && npm run build`, and build the worker.
4. Run `php artisan soundon:process-pending --limit=1 --dry-run` after completing controlled selector discovery.
5. Complete UAT for one release and manually verify its SoundOn draft before enabling Soundfresh review.
6. Keep queue concurrency at one. Enable the schedule only after UAT.

Selector promotion is documented in `automation-worker/SELECTOR-DISCOVERY.md`. Deploy `config/selectors.json` as reviewed configuration and never generate selectors automatically in production.

Horizon requires Linux `pcntl` and `posix`; it is intentionally not runnable under XAMPP Windows.
