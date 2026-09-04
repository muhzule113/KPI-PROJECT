# Deploy KPI Project ke VPS

Runbook ini menargetkan Ubuntu/Debian dengan Nginx, PHP-FPM, MySQL, dan Supervisor. Tidak ada secret yang disimpan di repository.

## 1. DNS dan paket server

Buat dua record DNS ke IP VPS:

- `kpi.example.com` untuk website/API
- `ws.kpi.example.com` untuk Reverb/WebSocket

Gunakan PHP `>= 8.2` dan sesuaikan angka versi pada perintah jika VPS memakai PHP 8.2.

```bash
sudo apt update
sudo apt install -y git unzip nginx mysql-server supervisor certbot python3-certbot-nginx \
  php8.3-cli php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-xml \
  php8.3-curl php8.3-zip php8.3-bcmath php8.3-gd
sudo systemctl enable --now nginx php8.3-fpm mysql supervisor
```

Node.js `22 LTS` diperlukan hanya saat build asset Vite di VPS. Composer 2 juga wajib tersedia.

## 2. Database

Buat database dan user khusus aplikasi. Jangan memakai user `root` dari `.env` aplikasi.

```sql
CREATE DATABASE kpi_management_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'kpi_app'@'127.0.0.1' IDENTIFIED BY 'GANTI_PASSWORD_KUAT';
GRANT ALL PRIVILEGES ON kpi_management_db.* TO 'kpi_app'@'127.0.0.1';
FLUSH PRIVILEGES;
```

## 3. Checkout dan environment

```bash
sudo mkdir -p /var/www
sudo chown "$USER":www-data /var/www
cd /var/www
git clone <URL_REPOSITORY_ANDA> kpi-project
cd /var/www/kpi-project
cp backend/.env.production.example backend/.env
nano backend/.env
```

Isi domain, kredensial MySQL, `APP_KEY`, dan kredensial Reverb. Jika ini bukan instalasi baru, pertahankan `APP_KEY` production yang sudah digunakan; jangan membuat key baru karena data terenkripsi dan session lama akan tidak terbaca.

## 4. Backup, dependency, migration, dan asset

Backup dilakukan sebelum migration. Password MySQL sebaiknya dimasukkan melalui prompt atau `~/.my.cnf`, bukan ditulis di shell history.

```bash
mkdir -p /var/backups/kpi-project
mysqldump --single-transaction --routines --triggers -h 127.0.0.1 -u kpi_app -p kpi_management_db \
  > /var/backups/kpi-project/kpi_management_db_$(date +%F_%H%M%S).sql

cd /var/www/kpi-project/backend
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan storage:link
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Jangan menjalankan `migrate:fresh`, `db:wipe`, atau `migrate:rollback` pada database production tanpa rencana pemulihan yang sudah diuji.

Atur permission runtime:

```bash
sudo chown -R www-data:www-data /var/www/kpi-project/backend/storage /var/www/kpi-project/backend/bootstrap/cache
sudo chmod -R ug+rwX /var/www/kpi-project/backend/storage /var/www/kpi-project/backend/bootstrap/cache
```

## 5. Nginx dan HTTPS

```bash
sudo cp /var/www/kpi-project/deploy/nginx/kpi-project.conf.example /etc/nginx/sites-available/kpi-project.conf
sudo nano /etc/nginx/sites-available/kpi-project.conf
sudo ln -s /etc/nginx/sites-available/kpi-project.conf /etc/nginx/sites-enabled/kpi-project.conf
sudo nginx -t
sudo systemctl reload nginx
sudo certbot --nginx -d kpi.example.com -d ws.kpi.example.com
```

Setelah Certbot selesai, pastikan `APP_URL=https://kpi.example.com`, `REVERB_HOST=ws.kpi.example.com`, `REVERB_PORT=443`, `REVERB_SCHEME=https`, dan `REVERB_ALLOWED_ORIGINS=https://kpi.example.com` di `.env`, lalu jalankan ulang `php artisan config:cache`.

## 6. Queue, WebSocket, dan scheduler

Broadcast Reverb dan import XLSX berjalan melalui queue, sehingga worker dan Reverb harus selalu hidup.

```bash
sudo cp /var/www/kpi-project/deploy/supervisor/kpi-worker.conf /etc/supervisor/conf.d/kpi-worker.conf
sudo cp /var/www/kpi-project/deploy/supervisor/kpi-reverb.conf /etc/supervisor/conf.d/kpi-reverb.conf
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart kpi-worker kpi-reverb
sudo supervisorctl status
```

Tambahkan scheduler Laravel untuk future-proofing:

```bash
sudo crontab -u www-data -e
```

```cron
* * * * * cd /var/www/kpi-project/backend && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

## 7. Mobile release

Build release Flutter dengan URL API dan Reverb production. `REVERB_APP_KEY` boleh berada di aplikasi client; `REVERB_APP_SECRET` tidak boleh.

```bash
cd mobile
flutter pub get
flutter build apk --release \
  --dart-define=API_BASE_URL=https://kpi.example.com/api/v1 \
  --dart-define=REVERB_APP_KEY=GANTI_APP_KEY \
  --dart-define=REVERB_HOST=ws.kpi.example.com \
  --dart-define=REVERB_PORT=443 \
  --dart-define=REVERB_SCHEME=https
```

## 8. Smoke check dan rollback

```bash
curl --fail https://kpi.example.com/up
sudo supervisorctl status kpi-worker kpi-reverb
cd /var/www/kpi-project/backend
php artisan queue:failed
tail -n 100 storage/logs/laravel-$(date +%F).log
```

Sebelum release, buat tag commit yang sedang berjalan. Jika perlu rollback aplikasi, checkout tag sebelumnya pada server, install dependency/cache ulang, lalu restart Supervisor. Jangan rollback migration secara membabi buta; gunakan backup SQL dan prosedur migration yang kompatibel.
