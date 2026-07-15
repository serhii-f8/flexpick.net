# Mailcoach service (FlexPick)

Standalone Mailcoach app used for audit transactional email tracking.

## One-time setup

1. `cp auth.json.example auth.json` and fill in your Spatie account email and
   Mailcoach license key (provided by the project owner — never commit auth.json).
2. `cp .env.example .env`, then set:
   - `APP_URL=http://localhost:8090`
   - `DB_HOST=mysql`, `DB_DATABASE=mailcoach`, `DB_USERNAME=sail`, `DB_PASSWORD=password` (match backend/.env DB credentials)
   - `REDIS_HOST=redis`, `REDIS_DB=2`, `REDIS_CACHE_DB=3`
   - `MAIL_MAILER=smtp`, `MAIL_HOST=mailpit`, `MAIL_PORT=1025`
3. From the repo root:
   - `docker compose up -d mailcoach`
   - `docker compose exec mysql bash -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "CREATE DATABASE IF NOT EXISTS mailcoach; GRANT ALL PRIVILEGES ON mailcoach.* TO '"'"'$MYSQL_USER'"'"'@'"'"'%'"'"';"'`
     (only needed on an existing MySQL volume; fresh volumes run the init script)
   - `docker compose exec mailcoach composer install`
   - `docker compose exec mailcoach php artisan key:generate`
   - `docker compose exec mailcoach php artisan migrate`
   - `docker compose exec mailcoach php artisan tinker --execute="\App\Models\User::create(['name' => 'Admin', 'email' => 'admin@flexpick.net', 'password' => bcrypt('password')]);"`
   - `docker compose up -d mailcoach.horizon`
4. Log in at http://localhost:8090, create an API token (Settings → API tokens),
   and put it into `backend/.env` as `MAILCOACH_API_TOKEN` with
   `MAILCOACH_ENDPOINT=http://mailcoach/api` and `MAILCOACH_UI_URL=http://localhost:8090`.
