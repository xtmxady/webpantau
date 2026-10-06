# Webpantau

Dashboard pribadi untuk mencatat masa aktif domain dan server, dengan login dan pengingat Telegram.

**VPS kamu sudah memakai Nginx dan PHP 8.3: cukup pasang file aplikasi, atur Nginx, lalu buat akun. Tidak perlu Node.js, npm, Composer, atau database MySQL.**

## 1. Taruh file aplikasi di VPS

Upload isi repositori ini ke `/var/www/webpantau`, atau clone bila belum ada:

```sh
sudo git clone https://github.com/xtmxady/webpantau.git /var/www/webpantau
```

Jika foldernya sudah ada, jangan clone lagi. Perbarui file aplikasi sambil menjaga folder `data` yang sudah berisi data kamu.

## 2. Siapkan folder penyimpanan

Jalankan sekali untuk membuat folder JSON yang dapat ditulis PHP:

```sh
sudo install -d -m 700 -o www-data -g www-data /var/www/webpantau/data
```

`www-data` adalah pengguna PHP-FPM bawaan Ubuntu/Debian. Jika pool PHP kamu menggunakan nama lain, gunakan nama itu juga pada perintah dan cron di bawah. File aplikasi harus bisa dibaca pengguna PHP-FPM.

## 3. Arahkan Nginx ke aplikasi

Gunakan [contoh konfigurasi Nginx](deploy/nginx.conf) untuk **site baru Webpantau**. Jangan menimpa site lain yang sudah berjalan. Pengaturan utamanya:

| Pengaturan | Isi |
| --- | --- |
| Domain | Subdomain kamu, misalnya `pantau.domainkamu.com` |
| Document root | `/var/www/webpantau/public` |
| PHP-FPM socket | `/run/php/php8.3-fpm.sock` |

Contoh konfigurasi sudah memuat aturan API yang dibutuhkan aplikasi. Ubah `pantau.example.com` menjadi domainmu. Simpan sebagai `/etc/nginx/sites-available/webpantau`, lalu aktifkan sekali:

```sh
sudo ln -s /etc/nginx/sites-available/webpantau /etc/nginx/sites-enabled/webpantau
sudo nginx -t && sudo systemctl reload nginx
```

Jika site sudah aktif, cukup jalankan baris pemeriksaan dan reload. Pastikan DNS subdomain mengarah ke IP VPS dan HTTPS aktif memakai cara yang biasa kamu gunakan. Cookie aman otomatis mengikuti HTTPS.

**Document root wajib di `public/`.** Folder JSON berada di luar folder itu agar tidak dapat diunduh pengunjung.

## 4. Buka dashboard dan buat akun

Buka domain Webpantau, buat nama pengguna dan kata sandi, lalu tambahkan domain/server kamu. Kontak klien bisa diisi dengan nomor WhatsApp, telepon, atau email pada formulir layanan; kolom ini opsional. Untuk layanan lama, klik edit lalu tambahkan kontaknya. Tidak ada akun bawaan. Selesaikan pendaftaran lewat akses privat sebelum membuka site ke publik. Jika site harus terbuka lebih dulu, gunakan `SETUP_KEY` sesuai [panduan teknis](docs/TECHNICAL.md).

Menu **Telegram** menyediakan kolom token bot dan chat ID. Kirim `/start` ke botmu, isi pengaturan di dashboard, lalu klik **Simpan pengaturan → Uji pengiriman**. PHP memerlukan ekstensi cURL untuk fitur Telegram; periksa dengan `php8.3 -m`. Jika cURL belum tersedia, pasang `php8.3-curl` dan restart PHP-FPM.

## 5. Aktifkan pengingat otomatis

Buka cron pengguna PHP:

```sh
sudo crontab -u www-data -e
```

Tambahkan satu baris ini di bawah jadwal yang sudah ada:

```cron
5 * * * * /usr/bin/php8.3 /var/www/webpantau/bin/reminders.php >> /var/www/webpantau/data/reminders.log 2>&1
```

Artinya: cek pengingat setiap jam pada menit ke-5. Pengingat tetap berjalan meskipun dashboard ditutup. Di menu Telegram, pilih hari pengingat dan aktifkan **Pengingat otomatis**.

## Preview dan informasi lainnya

- [Preview HTML](preview/index.html): di GitHub pilih **Download raw file**, lalu buka file yang diunduh di browser; bisa mencoba menu dan formulir tanpa PHP. Menggunakan data contoh sementara, tidak menyimpan data dan tidak mengirim Telegram.
- [Panduan teknis](docs/TECHNICAL.md): backup, reset password, migrasi, dan pengujian.
- Folder `data/` berisi data pribadi dan token Telegram. Jangan upload folder ini ke GitHub.
