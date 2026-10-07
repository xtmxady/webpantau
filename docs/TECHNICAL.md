# Webpantau

Dashboard pribadi untuk memantau jatuh tempo domain dan server/hosting, dengan login pemilik dan pengingat Telegram. Menggunakan **HTML, JavaScript, CSS Tailwind yang sudah dikompilasi, PHP, dan penyimpanan JSON**. File siap dipasang di VPS dengan Nginx dan PHP-FPM.

## Menjalankan secara lokal

Memerlukan PHP 8.2+ dengan ekstensi cURL. Versi ini diuji menggunakan PHP 8.4.26. Dari direktori proyek:

```sh
php -S 127.0.0.1:3000 -t public public/router.php
```

Buka aplikasi, buat akun pemilik pertama, kemudian catat domain/server. Kata sandi minimal 12 karakter. Pendaftaran tersedia hanya sampai satu akun dibuat. Gunakan akses privat atau `SETUP_KEY` sebelum membuka server ke publik. Server bawaan PHP hanya untuk pengembangan; VPS produksi menggunakan Nginx dan PHP-FPM.

CSS Tailwind tersedia di `public/assets/app.css`, tanpa CDN. Penyesuaian tampilan tambahan dapat dilakukan di `public/assets/details.css`. Halaman menggunakan JavaScript browser biasa.

## VPS Ubuntu / Debian

Ikuti [langkah pemasangan di README](../README.md) untuk VPS yang sudah memiliki Nginx dan PHP 8.3. PHP-FPM dan PHP CLI diperlukan, serta ekstensi cURL untuk Telegram. Cron menjalankan pengingat. Periksa versi dan socket sesuai VPS kamu; panduan ini tidak otomatis memasang aplikasi di VPS.

Detail konfigurasi tambahan:

1. Gunakan `deploy/nginx.conf` sebagai konfigurasi site; ganti domain, lokasi checkout, dan socket PHP-FPM. **Document root harus menunjuk ke `public/`, bukan direktori proyek.** Direktori `app/`, `bin/`, dan `data/` tidak boleh disajikan web server. Socket PHP dapat dicek melalui `ls /run/php/`.
2. Aktifkan site, jalankan `sudo nginx -t`, lalu reload Nginx. Buat DNS domain menuju VPS dan pasang sertifikat HTTPS yang valid menggunakan alat yang tersedia di VPS.
3. Pengaturan pool dalam `deploy/php-fpm-env.conf` bersifat opsional bila menggunakan direktori dan zona waktu default. `DATA_DIR` dan `TZ` harus sama untuk PHP-FPM dan cron. Untuk pendaftaran awal yang terbuka ke Internet, atur `SETUP_KEY` pribadi pada pool, lalu masukkan nilai itu di formulir pendaftaran. Jangan menyimpan nilai kunci di Git. Restart PHP-FPM setelah perubahan.
4. Cookie Secure aktif otomatis saat PHP menerima HTTPS dari Nginx. `COOKIE_SECURE=true` hanya diperlukan sebagai override, misalnya pada konfigurasi reverse proxy TLS khusus. Secure cookies tidak bekerja pada HTTP biasa.
5. Salin jadwal dari `deploy/crontab.example` melalui `sudo crontab -u www-data -e`. Jangan mengganti jadwal cron lain yang sudah ada. Jalankan `sudo -u www-data php /var/www/webpantau/bin/reminders.php` untuk memastikan skrip dapat membaca data. Jangan menggunakan akun root untuk menulis data aplikasi. Cron harus berjalan dengan akun pemilik file data yang sama dengan PHP-FPM.
6. Buat akun pemilik sebelum membagikan alamat dashboard. Di menu Telegram, isi token bot dari @BotFather dan chat ID, simpan, lalu klik Uji pengiriman. Kirim `/start` ke bot terlebih dahulu. Untuk grup, tambahkan bot dan gunakan ID grup.

Pemeriksaan setelah pemasangan: `/api/auth` mengembalikan JSON, login dapat dilakukan, layanan baru bertahan setelah halaman dimuat ulang, dan `/data/dashboard.json` serta `/app/bootstrap.php` mengembalikan 404. Hanya tombol uji dengan token nyata yang memvalidasi pengiriman Telegram.

## Perhitungan biaya

Field `cost` menyimpan biaya dasar sebelum pajak. PPN dihitung tetap 11%, dibulatkan ke rupiah terdekat, lalu ditambahkan ke biaya dasar untuk total bayar per siklus. Rincian tampil di formulir, tabel layanan, dan pesan pengingat Telegram. Backup mempertahankan biaya dasar; setelah import pajak dihitung kembali sehingga tidak ditambahkan dua kali.

## Penyimpanan, keamanan, dan pengingat

- Data berada di `data/dashboard.json`, di luar document root dan diabaikan Git. `DATA_DIR` dapat mengubah lokasi; direktori di bawah `public/` ditolak. Jangan gunakan filesystem jaringan untuk JSON ini.
- Penguncian `flock` menggunakan lock file terpisah dan penulisan atomik. Beberapa proses PHP-FPM pada **satu VPS** dapat menulis file yang sama. JSON cocok untuk penggunaan pribadi dengan data kecil; SQLite/PostgreSQL lebih cocok untuk aplikasi besar atau banyak server.
- Kata sandi menggunakan `password_hash`/`password_verify`. Sesi PHP menggunakan cookie HttpOnly/SameSite, ID diregenerasi saat login, dan permintaan perubahan memerlukan token CSRF. Percobaan login dibatasi per IP selama 15 menit. Tidak ada akun/password default.
- Token Telegram tidak dikembalikan melalui menu pengaturan setelah disimpan, tetapi **tersimpan sebagai teks di JSON** berizin 0600. Token ikut diunduh hanya jika opsi Telegram dipilih saat backup. Jaga server dan cadangan tetap privat. Kolom token kosong mempertahankan token lama. Tombol uji memakai konfigurasi yang sudah disimpan. Server memerlukan akses HTTPS ke `api.telegram.org`; verifikasi TLS tetap aktif.
- Cron memeriksa tanggal sesuai Asia/Makassar. Satu pesan per layanan/tanggal/hari pengingat; setelah perpanjangan, edit tanggal di dashboard. Hari pengingat yang terlewat saat cron tidak berjalan tidak dikirim ulang. Kegagalan sesudah Telegram menerima pesan tetapi sebelum pencatatan lokal selesai dapat menyebabkan pesan ganda saat retry.
- Catatan tanggal diinput manual; aplikasi tidak mengambil informasi registrar atau membayar perpanjangan. Satu baris mencatat satu domain atau paket server, dengan nama klien bebas.
- Bila JSON rusak, aplikasi gagal dengan pesan kesalahan dan tidak mengosongkan file otomatis. Untuk menyalin direktori data secara langsung, hentikan sementara akses tulis web server dan cron atau ambil shared lock `dashboard.lock` saat menyalin JSON.

## Backup dan import

Menu **Backup & Import** tersedia setelah login. Unduhan JSON berisi layanan (termasuk kontak klien) dan riwayat pengingat. Akun pemilik, hash kata sandi, serta sesi login tidak diekspor. Opsi menyertakan pengaturan Telegram tidak aktif secara bawaan; bila dicentang, file juga berisi token bot dan chat ID. Simpan unduhan secara privat.

Untuk import, pilih file JSON dengan ukuran maksimal **5 MiB**, jalankan validasi, periksa ringkasan, lalu konfirmasikan penggantian. Backup dibatasi hingga **5.000 layanan dan 5.000 entri riwayat pengingat**. Import mengganti layanan dan riwayat pengingat secara keseluruhan; akun pemilik di VPS tujuan tetap dipertahankan. File yang tidak valid ditolak sebelum data diganti. Jika data tujuan berubah setelah validasi, pengguna harus memvalidasi ulang agar konfirmasi sesuai dengan data terbaru.

Untuk VPS yang sudah terpasang, salin blok `location = /api/backup/validate` dari [deploy/nginx.conf](../deploy/nginx.conf) terbaru ke dalam blok `server` site Webpantau yang aktif, termasuk site HTTPS jika dipisah. Blok tersebut menetapkan `client_max_body_size 5m` khusus untuk validasi backup dan meneruskan permintaan ke `public/api.php`; sesuaikan socket PHP-FPM. Konfigurasi lama membatasi semua permintaan hingga 32 KiB, sehingga file lebih besar akan ditolak Nginx sebelum masuk ke aplikasi. Pertahankan domain, sertifikat, dan pengaturan site yang sudah ada, lalu jalankan:

```sh
sudo nginx -t && sudo systemctl reload nginx
```

- Jika file tidak memuat Telegram, konfigurasi Telegram tujuan tetap dipertahankan.
- Jika file memuat Telegram, pengaturan dan token dipulihkan, tetapi **pengingat otomatis dinonaktifkan**. Saat pindah VPS, hentikan cron Webpantau di VPS lama sebelum mengaktifkannya di VPS baru agar pengingat tidak dikirim dari dua server.
- Buat akun pemilik di VPS baru sebelum import. Gunakan kata sandi yang kamu tentukan sendiri.

Sebelum import mengganti data, aplikasi menyimpan backup pemulihan di `data/backups/` (atau `<DATA_DIR>/backups/` jika lokasi data diubah). Penyimpanan ini berada di luar document root. File tidak valid, validasi gagal, atau kegagalan menyimpan salinan pemulihan mencegah penggantian data. Jika respons jaringan terputus setelah penyimpanan berhasil, import mungkin sudah selesai; muat ulang dashboard dan periksa data sebelum mencoba lagi.

Untuk memulihkan keadaan sebelum import, unduh file JSON pemulihan dari folder tersebut melalui SFTP, simpan secara privat, lalu gunakan menu **Backup & Import** seperti biasa. Akun tujuan tetap dipakai. Backup pemulihan dapat memuat token Telegram; jangan menaruhnya di `public/` atau Git. Belum ada pembersihan otomatis, sehingga pemilik perlu menghapus salinan lama secara berkala setelah memastikan backup yang diperlukan tersedia. Cadangan pada VPS yang sama membantu memulihkan salah import; simpan juga unduhan di tempat lain untuk menghadapi kehilangan VPS.

## Reset kata sandi

Jika lupa kata sandi, jalankan perintah berikut melalui SSH di VPS. Akun tetap memakai nama pengguna yang sama, dan sesi lama tidak dapat digunakan setelah kata sandi diganti.

```sh
read -r -s -p 'Kata sandi baru (minimal 12 karakter): ' webpantau_password
printf '%s' "$webpantau_password" | sudo -u www-data php /var/www/webpantau/bin/set-password.php
unset webpantau_password
```

Untuk `DATA_DIR` khusus, set variabel tersebut pada perintah CLI. Jangan memasukkan password lewat argumen perintah, file kode, atau chat.

## Validasi

```sh
php tests/run.php
php tests/backup.php
python3 tests/api.py
python3 tests/backup_api.py
```

Python hanya dipakai oleh tes integrasi, tidak dibutuhkan untuk menjalankan aplikasi. Unit tests mencakup batas tanggal, validasi input, penulisan gagal, korupsi data, pengingat berulang, dan pembaruan tanggal. Tes integrasi memeriksa login, CSRF, kunci pendaftaran, CRUD, proteksi token, pembatasan login, rute privat, dan 25 penulis PHP bersamaan. Tes backup memeriksa ekspor, validasi file, penggantian data, salinan pemulihan, dan pengaturan Telegram. Semua tes memakai direktori sementara, tidak menyentuh data nyata. Jika PHP bukan pada PATH, jalankan tes PHP dengan path lengkap dan gunakan `PHP_BIN=/path/to/php python3 tests/api.py` serta `PHP_BIN=/path/to/php python3 tests/backup_api.py`.

Tampilan desktop dan mobile tersedia dalam `artifacts/`, menggunakan data contoh terpisah dari aplikasi asli.
