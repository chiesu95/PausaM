#!/usr/bin/env bash

set -euo pipefail

cd /var/www/html

if [[ -z "${APP_KEY:-}" ]]; then
  echo "APP_KEY non impostata. Impostala nelle Environment Variables di Render."
  exit 1
fi

mkdir -p storage/framework/{cache,sessions,views} bootstrap/cache

run_with_retry() {
  local cmd="$1"
  local attempts=1
  local max_attempts="${DB_MAX_ATTEMPTS:-30}"
  local sleep_seconds="${DB_RETRY_SECONDS:-3}"

  until bash -lc "$cmd"; do
    if [[ "$attempts" -ge "$max_attempts" ]]; then
      echo "Comando fallito dopo ${attempts} tentativi: $cmd"
      return 1
    fi

    echo "Tentativo ${attempts}/${max_attempts} fallito. Riprovo tra ${sleep_seconds}s..."
    attempts=$((attempts + 1))
    sleep "$sleep_seconds"
  done
}

is_first_boot() {
  php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$schema = Illuminate\Support\Facades\Schema::connection(config("database.default"));

try {
    if (! $schema->hasTable("migrations")) {
        echo "yes";
        exit(0);
    }

    $count = Illuminate\Support\Facades\DB::table("migrations")->count();
    echo $count === 0 ? "yes" : "no";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    echo "unknown";
}
'
}

if [[ "${RUN_STORAGE_LINK:-true}" == "true" ]]; then
  php artisan storage:link || true
fi

if [[ "${RUN_MIGRATIONS:-true}" == "true" ]]; then
  first_boot_state="unknown"
  attempts=1
  max_attempts="${DB_MAX_ATTEMPTS:-30}"
  sleep_seconds="${DB_RETRY_SECONDS:-3}"

  while [[ "$first_boot_state" == "unknown" && "$attempts" -le "$max_attempts" ]]; do
    first_boot_state="$(is_first_boot)"

    if [[ "$first_boot_state" != "unknown" ]]; then
      break
    fi

    echo "Impossibile determinare lo stato iniziale del DB (tentativo ${attempts}/${max_attempts})."
    attempts=$((attempts + 1))
    sleep "$sleep_seconds"
  done

  if [[ "$first_boot_state" == "unknown" ]]; then
    echo "DB non pronto per la verifica first-boot. Procedo con migrate standard."
    first_boot_state="no"
  fi

  if [[ "$first_boot_state" == "yes" && "${RUN_FRESH_SEED_ON_FIRST_BOOT:-true}" == "true" ]]; then
    echo "Primo avvio rilevato: eseguo migrate:fresh --seed..."
    run_with_retry "php artisan migrate:fresh --seed --force"
  else
    echo "Eseguo migrate --force..."
    run_with_retry "php artisan migrate --force"

    if [[ "${RUN_SEED_AFTER_MIGRATE:-false}" == "true" ]]; then
      echo "Eseguo db:seed --force..."
      run_with_retry "php artisan db:seed --force"
    fi
  fi
fi

pids=()

if [[ "${RUN_SCHEDULER:-true}" == "true" ]]; then
  echo "Avvio Laravel scheduler (php artisan schedule:work)..."
  php artisan schedule:work &
  pids+=($!)
fi

echo "Avvio web server Laravel..."
php artisan serve --host=0.0.0.0 --port="${PORT:-10000}" &
pids+=($!)

wait -n "${pids[@]}"
exit_code=$?

kill "${pids[@]}" 2>/dev/null || true
wait "${pids[@]}" 2>/dev/null || true

exit "$exit_code"
