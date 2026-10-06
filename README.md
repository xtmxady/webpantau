# Webpantau

Dashboard pribadi untuk memantau jatuh tempo domain dan server/hosting, dengan login pemilik dan pengingat Telegram. **HTML + JavaScript biasa, CSS Tailwind yang sudah dikompilasi, PHP, dan JSON. Tidak membutuhkan Node.js, npm, Composer, atau build saat dipasang.**

## Menjalankan secara lokal

Memerlukan PHP 8.2+ dengan ekstensi cURL. Versi ini diuji menggunakan PHP 8.4.26. Dari direktori proyek:

```sh
php -S 127.0.0.1:3000 -t public public/router.php
```

Buka aplikasi, buat akun pemilik pertama, kemudian catat domain/server. Kata sandi minimal 12 karakter. Pendaftaran tersedia hanya sampai satu akun dibuat. Gunakan akses privat atau `SETUP_KEY` sebelum membuka server ke publik. Server bawaan PHP hanya untuk pengembangan; VPS produksi menggunakan Nginx dan PHP-FPM.

CSS Tailwind tersedia di `public/assets/app.css`, tanpa CDN. Penyesuaian tampilan tambahan dapat dilakukan di `public/assets/details.css`. Halaman menggunakan JavaScript browser biasa, bukan React.

## VPS Ubuntu / Debian

Contoh menggunakan Ubuntu 24.04, Nginx, dan PHP 8.3. Sesuaikan nama paket/socket bila distribusi VPS menyediakan versi PHP lain. Tidak ada perintah ini yang otomatis dijalankan pada VPS kamu.

```sh
sudo apt update
sudo apt install -y nginx php-fpm php-cli php-curl git cron
sudo git clone https://github.com/xtmxady/webpantau.git /var/www/webpantau
sudo install -d -m 700 -o www-data -g www-data /var/www/webpantau/data
sudo find /var/www/webpantau/public /var/www/webpantau/app /var/www/webpantau/bin -type d -exec chmod 755 {} \;
sudo find /var/www/webpantau/public /var/www/webpantau/app /var/www/webpantau/bin -type f -exec chmod 644 {} \;
```

1. Gunakan `deploy/nginx.conf` sebagai konfigurasi site; ganti domain, lokasi checkout, dan socket PHP-FPM. **Document root harus menunjuk ke `public/`, bukan direktori proyek.** Direktori `app/`, `bin/`, dan `data/` tidak boleh disajikan web server. Socket PHP dapat dicek melalui `ls /run/php/`.
2. Aktifkan site, jalankan `sudo nginx -t`, lalu reload Nginx. Buat DNS domain menuju VPS dan pasang sertifikat HTTPS yang valid menggunakan alat yang tersedia di VPS.
3. Gunakan pengaturan pool dalam `deploy/php-fpm-env.conf`. `DATA_DIR` dan `TZ` harus sama untuk PHP-FPM dan cron. Untuk pendaftaran awal yang terbuka ke Internet, atur `SETUP_KEY` pribadi pada pool, lalu masukkan nilai itu di formulir pendaftaran. Jangan menyimpan nilai kunci di Git. Restart PHP-FPM setelah perubahan.
4. Aktifkan `COOKIE_SECURE=true` setelah HTTPS aktif. Contoh Nginx meneruskan flag ini; jika melakukan pengujian HTTP sementara, hilangkan flag tersebut dulu lalu pulihkan setelah HTTPS. Secure cookies tidak bekerja pada HTTP biasa.
5. Salin jadwal dari `deploy/crontab.example` melalui `sudo crontab -u www-data -e`. Jangan mengganti jadwal cron lain yang sudah ada. Jalankan `sudo -u www-data php /var/www/webpantau/bin/reminders.php` untuk memastikan skrip dapat membaca data. Jangan menggunakan akun root untuk menulis data aplikasi. Cron harus berjalan dengan akun pemilik file data yang sama dengan PHP-FPM.
6. Buat akun pemilik sebelum membagikan alamat dashboard. Di menu Telegram, isi token bot dari @BotFather dan chat ID, simpan, lalu klik Uji pengiriman. Kirim `/start` ke bot terlebih dahulu. Untuk grup, tambahkan bot dan gunakan ID grup.

Pemeriksaan setelah pemasangan: `/api/auth` mengembalikan JSON, login dapat dilakukan, layanan baru bertahan setelah halaman dimuat ulang, dan `/data/dashboard.json` serta `/app/bootstrap.php` mengembalikan 404. Hanya tombol uji dengan token nyata yang memvalidasi pengiriman Telegram.

## Penyimpanan, keamanan, dan pengingat

- Data berada di `data/dashboard.json`, di luar document root dan diabaikan Git. `DATA_DIR` dapat mengubah lokasi; direktori di bawah `public/` ditolak. Jangan gunakan filesystem jaringan untuk JSON ini.
- Penguncian `flock` menggunakan lock file terpisah dan penulisan atomik. Beberapa proses PHP-FPM pada **satu VPS** dapat menulis file yang sama. JSON cocok untuk penggunaan pribadi dengan data kecil; SQLite/PostgreSQL lebih cocok untuk aplikasi besar atau banyak server.
- Kata sandi menggunakan `password_hash`/`password_verify`. Sesi PHP menggunakan cookie HttpOnly/SameSite, ID diregenerasi saat login, dan permintaan perubahan memerlukan token CSRF. Percobaan login dibatasi per IP selama 15 menit. Tidak ada akun/password default.
- Token Telegram tidak dikembalikan ke browser setelah disimpan, tetapi **tersimpan sebagai teks di JSON** berizin 0600. Jaga server dan cadangan tetap privat. Kolom token kosong mempertahankan token lama. Tombol uji memakai konfigurasi yang sudah disimpan. Server memerlukan akses HTTPS ke `api.telegram.org`; verifikasi TLS tetap aktif.
- Cron memeriksa tanggal sesuai Asia/Makassar. Satu pesan per layanan/tanggal/hari pengingat; setelah perpanjangan, edit tanggal di dashboard. Hari pengingat yang terlewat saat cron tidak berjalan tidak dikirim ulang. Kegagalan sesudah Telegram menerima pesan tetapi sebelum pencatatan lokal selesai dapat menyebabkan pesan ganda saat retry.
- Catatan tanggal diinput manual; aplikasi tidak mengambil informasi registrar atau membayar perpanjangan. Satu baris mencatat satu domain atau paket server, dengan nama klien bebas.
- Cadangkan direktori data secara privat. Bila JSON rusak, aplikasi gagal dengan pesan kesalahan dan tidak mengosongkan file otomatis. Salinan konsisten dapat dibuat saat web server dan cron berhenti atau dengan mengambil shared lock `dashboard.lock` ketika menyalin JSON.

## Migrasi dari versi Node.js / reset kata sandi

Riwayat Git menyimpan versi Node.js sebelumnya. Data layanan, Telegram, dan riwayat pengingat tetap kompatibel, tetapi hash kata sandi versi Node.js perlu diganti. Cadangkan data sebelum migrasi, hentikan server lama, pasang versi PHP dengan `DATA_DIR` yang sama, lalu tetapkan password baru melalui CLI berikut. Perintah juga dapat dipakai jika lupa password. Akun tetap memakai nama pengguna lama, dan sesi lama tidak dapat digunakan setelah password diganti.

```sh
read -r -s -p 'Kata sandi baru (minimal 12 karakter): ' webpantau_password
printf '%s' "$webpantau_password" | sudo -u www-data php /var/www/webpantau/bin/set-password.php
unset webpantau_password
```

Untuk `DATA_DIR` khusus, set variabel tersebut pada perintah CLI. Jangan memasukkan password lewat argumen perintah, file kode, atau chat.

## Validasi

```sh
php tests/run.php
python3 tests/api.py
```

Python hanya dipakai oleh tes integrasi, tidak dibutuhkan untuk menjalankan aplikasi. Unit tests mencakup batas tanggal, validasi input, penulisan gagal, korupsi data, pengingat berulang, dan pembaruan tanggal. Tes integrasi memeriksa login, CSRF, kunci pendaftaran, CRUD, proteksi token, pembatasan login, rute privat, dan 25 penulis PHP bersamaan. Keduanya memakai direktori sementara, tidak menyentuh data nyata. Jika PHP bukan pada PATH, gunakan `PHP_BIN=/path/to/php python3 tests/api.py`.

Tampilan desktop dan mobile tersedia dalam `artifacts/`, menggunakan data contoh terpisah dari aplikasi asli.
