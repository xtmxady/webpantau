# Webpantau

Dashboard pribadi untuk mencatat jatuh tempo domain dan server/hosting. React, Tailwind CSS 4, Express, dan penyimpanan JSON di sisi server. Tidak ada data contoh otomatis.

## Menjalankan

Memerlukan Node.js 22 atau lebih baru (diuji di Node 24).

```sh
npm ci
npm run dev
```

Server aplikasi menggunakan port 3000. Buka aplikasi melalui akses server yang tersedia, lalu buat akun pemilik pertama. Kata sandi minimal 12 karakter. **Selesaikan pendaftaran pertama melalui akses privat sebelum membuka server ke publik**, karena siapa pun yang pertama membuka halaman bisa membuat akun pemilik.

Produksi:

```sh
npm run build
NODE_ENV=production npm start
```

Gunakan HTTPS melalui reverse proxy dan set `COOKIE_SECURE=true` pada produksi HTTPS. `PORT` mengubah port, `DATA_DIR` mengubah lokasi data, dan `TZ` mengubah zona waktu penjadwal (default Asia/Makassar; UI menggunakan Asia/Makassar). Jalankan satu proses server saja. Proses harus tetap aktif agar pengingat berjalan; gunakan process supervisor yang sesuai hosting kamu.

## Data dan login

Data dibuat di `data/dashboard.json` (diabaikan Git). Password di-hash menggunakan scrypt dan salt acak. Sesi menggunakan cookie HttpOnly/SameSite, disimpan di memori dan berakhir saat server restart atau setelah 7 hari. Percobaan login dibatasi. Permintaan perubahan dari origin berbeda ditolak.

JSON sesuai untuk pemakaian pribadi, data kecil, dan satu proses. Penulisan diserialisasi dan menggunakan penggantian file atomik. Jangan menjalankan beberapa worker atau instance yang menulis file yang sama. Untuk banyak pengguna atau skala lebih besar, migrasikan ke SQLite/PostgreSQL. Cadangkan direktori data ke tempat privat; **token Telegram tersimpan sebagai teks di file data** dengan izin file 0600, bukan terenkripsi. Jangan mengunggah data atau cadangannya ke Git. Jika file rusak, server tidak menimpanya secara otomatis.

## Telegram

Di menu Telegram, isi token dari @BotFather dan chat ID. Kirim `/start` ke bot terlebih dahulu. Chat ID dapat ditemukan melalui metode `getUpdates` Telegram Bot API setelah mengirim pesan; jaga token agar tidak tampil di log atau tangkapan layar. Untuk grup, tambahkan bot dan gunakan ID grup. Simpan pengaturan, uji pengiriman, lalu aktifkan jadwal pengingat.

Token tidak dikembalikan ke browser setelah disimpan. Kolom token kosong mempertahankan token lama. Uji pengiriman memakai konfigurasi yang **sudah disimpan**. Server membutuhkan akses HTTPS ke `api.telegram.org`.

Penjadwal memeriksa saat startup dan setiap satu jam. Pesan dikirim sekali per layanan, tanggal kedaluwarsa, dan hari pengingat. Setelah perpanjangan, edit layanan dan masukkan tanggal baru. Hari pengingat yang terlewat saat server mati tidak dikirim ulang. Tanggal adalah catatan manual; aplikasi tidak mengecek registrar atau membayar perpanjangan. Jika Telegram menerima pesan tetapi respons jaringan terputus, retry bisa menyebabkan pesan ganda.

## Validasi

```sh
npm test
npm run build
```

Tes mencakup login, sesi, pembatasan origin, CRUD, tanggal, kerahasiaan token pada API, penulisan JSON bersamaan, dan zona waktu. Pengiriman nyata ke Telegram memerlukan token/chat ID pengguna dan diuji melalui tombol Uji pengiriman.
