# Webpantau

Dashboard pribadi untuk mencatat masa aktif domain dan server, dengan login dan pengingat Telegram.

**Untuk VPS yang sudah memakai Nginx dan PHP 8.3: pasang file aplikasi, atur Nginx, lalu buat akun. Data disimpan dalam file JSON.**

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

Biaya per siklus diisi **sebelum PPN**. Dashboard otomatis menampilkan PPN 11% dan total bayar untuk siklus bulanan atau tahunan. Rincian yang sama disertakan pada pengingat Telegram. PPN dibulatkan ke rupiah terdekat.

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

## Backup dan pindah VPS

Buka menu **Backup & Import**, lalu unduh backup JSON. File ini berisi layanan, kontak klien, dan riwayat pengingat. Opsi menyertakan pengaturan Telegram termasuk token bot tidak dicentang secara bawaan; aktifkan jika ingin memindahkan pengaturan tersebut juga. Simpan file backup secara privat, terutama jika berisi token bot.

Jika aplikasi sudah terpasang, perbarui blok `/api/backup/validate` Nginx sesuai [panduan teknis](docs/TECHNICAL.md#backup-dan-import) lalu periksa dan reload Nginx agar import di atas 32 KiB dapat diterima.

Untuk pindah VPS:

1. Pasang Webpantau di VPS baru dan buat akun. Akun, kata sandi, dan sesi login tidak ikut dalam backup.
2. Buka **Backup & Import**, pilih file JSON (maksimal 5 MiB), lalu validasi.
3. Periksa ringkasannya dan konfirmasikan penggantian data. Import **mengganti seluruh layanan dan riwayat pengingat** di VPS tujuan; akun yang sedang dipakai tetap sama.
4. Jika backup menyertakan Telegram, pengaturannya dipulihkan dengan pengingat otomatis dalam keadaan nonaktif. Hentikan cron Webpantau di VPS lama sebelum mengaktifkan pengingat di VPS baru. Jika Telegram tidak disertakan, pengaturan Telegram di VPS tujuan tetap dipakai.

Sebelum mengganti data, aplikasi menyimpan salinan pemulihan secara privat di `data/backups/`. File tersebut dapat diambil melalui SFTP lalu diimport kembali melalui dashboard. Salinan ini belum dihapus otomatis; kelola berkala. File tidak valid atau kegagalan menyimpan salinan pemulihan membatalkan penggantian data. Jika koneksi terputus saat import, muat ulang dashboard dan periksa hasilnya. Bila ada perubahan data setelah validasi, validasi file lagi sebelum melanjutkan.

## Preview dan informasi lainnya

- [Preview HTML](preview/index.html): di GitHub pilih **Download raw file**, lalu buka file yang diunduh di browser; bisa mencoba menu dan formulir tanpa PHP. Menggunakan data contoh sementara, tidak menyimpan data dan tidak mengirim Telegram.
- [Panduan teknis](docs/TECHNICAL.md): konfigurasi tambahan, backup, reset password, dan pengujian.
- Folder `data/` berisi data pribadi dan token Telegram. Jangan upload folder ini ke GitHub.
