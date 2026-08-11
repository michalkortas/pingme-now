# PingMe Now

Agent monitoringu dla aplikacji Laravel 9–13, współpracujący z self-hosted hubem PingMe. Wymaga PHP 8.1 lub nowszego.

Paczka cyklicznie wysyła do huba stan aplikacji, kolejki, schedulera, bazy danych, cache, dysków, logów oraz wersje zależności Composer i npm. Zawiera również skrypty raportujące backupy wykonywane poza Laravelem.

## Instalacja

Najpierw utwórz aplikację w hubie PingMe i skopiuj wygenerowany dla niej token integracyjny.

Zainstaluj paczkę:

```bash
composer require michalkortas/pingme-now
```

Uruchom konfigurator:

```bash
php artisan pingme:install
```

Konfigurator kolejno zapyta o:

1. adres huba, np. `https://pingme.example.com`,
2. token integracyjny wygenerowany w konfiguracji aplikacji.

Połączenie zostanie sprawdzone, skrypty backupowe opublikowane do katalogu `scripts/`, a pierwszy pełny status natychmiast wysłany do huba.

Instalacja bez interakcji, przydatna podczas deploymentu:

```bash
php artisan pingme:install \
  --url=https://pingme.example.com \
  --token=pm_token_wygenerowany_w_hubie
```

Polecenie zapisuje w `.env`:

```dotenv
PINGME_ENABLED=true
PINGME_URL="https://pingme.example.com"
PINGME_TOKEN="pm_token_wygenerowany_w_hubie"
```

Token przypisany jest do jednej aplikacji. Nie należy go umieszczać w repozytorium ani współdzielić między aplikacjami.

## Ręczne wysłanie statusu

```bash
php artisan pingme:now
```

Polecenie wyświetla wynik połączenia i najważniejsze zebrane metryki. Można go użyć po wdrożeniu albo do diagnostyki integracji.

## Scheduler

Paczka automatycznie rejestruje `pingme:now --quiet` w Laravel Scheduler i uruchamia go co minutę z ochroną przed nakładaniem wykonań. Nie trzeba dopisywać zadania do `routes/console.php` ani `app/Console/Kernel.php`.

Na serwerze musi działać standardowy scheduler Laravela, np. z crona:

```cron
* * * * * cd /var/www/app && php artisan schedule:run >> /dev/null 2>&1
```

W Dockerze można uruchomić osobny kontener/proces:

```bash
php artisan schedule:work
```

## Zbierane informacje

- wersja Laravel i PHP, środowisko oraz hostname,
- dostępność bazy danych i cache,
- heartbeat schedulera,
- test kolejki oraz liczba nieudanych jobów z ostatnich 24 godzin,
- całkowite i wolne miejsce na wskazanych dyskach,
- logi na poziomie `error` lub wyższym,
- lokalny bufor logów na wypadek czasowej niedostępności huba,
- pakiety z `composer.lock`,
- pakiety z `package-lock.json`,
- statusy backupów przekazane przez dołączone skrypty.

Hasła, tokeny, nagłówki autoryzacyjne, cookies i klucze API są maskowane przed zapisaniem logu do bufora i wysłaniem do huba.

## Konfiguracja

Wszystkie ustawienia są opcjonalne poza adresem huba i tokenem:

```dotenv
PINGME_ENABLED=true
PINGME_URL=https://pingme.example.com
PINGME_TOKEN=pm_token_wygenerowany_w_hubie

# Ścieżki rozdzielone przecinkami. Domyślnie storage_path().
PINGME_DISK_PATHS=/var/www/html/storage,/srv/backups

# Monitoring kolejki.
PINGME_QUEUE_ENABLED=true
PINGME_QUEUE_CONNECTION=redis
PINGME_QUEUE=default

# Minimalny poziom przesyłanych logów.
PINGME_LOG_LEVEL=error

# Lokalny bufor niewysłanych logów.
PINGME_SPOOL_PATH=/var/www/html/storage/framework/pingme
PINGME_SPOOL_MAX_BYTES=20971520

# Timeout komunikacji z hubem w sekundach.
PINGME_TIMEOUT=10
```

Po ręcznej zmianie konfiguracji w środowisku produkcyjnym odśwież cache:

```bash
php artisan config:cache
```

## Backupy wykonywane poza aplikacją

`pingme:install` publikuje trzy pliki:

```text
scripts/pingme-backup.sh
scripts/pingme-backup.bat
scripts/pingme-backup.ps1
```

Skrypty korzystają ze zmiennych środowiskowych `PINGME_URL` i `PINGME_TOKEN`. Token powinien należeć do monitorowanej aplikacji.

### Debian / Bash

Zgłoszenie aktualnego stanu katalogu z backupami:

```bash
export PINGME_URL=https://pingme.example.com
export PINGME_TOKEN=pm_token_wygenerowany_w_hubie

scripts/pingme-backup.sh scan database /srv/backups/database
```

Uruchomienie istniejącego skryptu backupowego z raportowaniem rozpoczęcia, powodzenia lub błędu:

```bash
scripts/pingme-backup.sh run database /srv/backups/database -- ./backup.sh
```

Przykład z `pg_dump`:

```bash
scripts/pingme-backup.sh run postgres /srv/backups/postgres -- \
  sh -c 'pg_dump "$DB_DATABASE" | gzip > "/srv/backups/postgres/$(date +%F-%H%M%S).sql.gz"'
```

### Windows CMD / PowerShell

```bat
set PINGME_URL=https://pingme.example.com
set PINGME_TOKEN=pm_token_wygenerowany_w_hubie

scripts\pingme-backup.bat scan database D:\Backups\database
scripts\pingme-backup.bat run database D:\Backups\database -- backup.cmd
```

Bezpośrednio z PowerShell:

```powershell
$env:PINGME_URL = 'https://pingme.example.com'
$env:PINGME_TOKEN = 'pm_token_wygenerowany_w_hubie'

.\scripts\pingme-backup.ps1 scan database 'D:\Backups\database'
.\scripts\pingme-backup.ps1 run database 'D:\Backups\database' -- backup.cmd
```

Raport zawiera nazwę i wynik backupu, czas wykonania, liczbę plików, łączny rozmiar, rozmiar najnowszego pliku oraz datę najstarszej i najnowszej kopii.

## Aktualizacja

```bash
composer update michalkortas/pingme-now
php artisan pingme:now
```

## Wymagania

- PHP 8.1+,
- Laravel 9–13,
- działający Laravel Scheduler,
- dostęp HTTPS do huba PingMe,
- `curl`, `find`, `awk` i GNU `date` dla skryptu Bash,
- PowerShell dla wariantu Windows.

## Licencja

[MIT](LICENSE.md)
