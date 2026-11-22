# Suara Fasilkom

Sistem transparansi keluh kesah dan laporan progres perbaikan Fakultas Ilmu Komputer UNSIKA.
Dibuat dengan PHP 8 dan MySQL (XAMPP).

## Cara menjalankan

1. Pasang **XAMPP** di `C:\xampp` (sudah termasuk PHP 8.2 dan MySQL).
2. Klik dua kali **`setup.bat`**. Skrip ini akan:
   - menyalakan MySQL XAMPP bila belum menyala,
   - membuat database `suara_fasilkom` beserta tabelnya dan daftar ruangan awal,
   - menjalankan web di <http://localhost:8000> lalu membukanya di browser.
3. Biarkan jendela `setup.bat` tetap terbuka selama web dipakai. Tutup jendelanya untuk mematikan web.

Menjalankan lewat Apache XAMPP juga bisa: salin folder ini ke `C:\xampp\htdocs\apsi`, nyalakan Apache dan MySQL,
jalankan `C:\xampp\php\php.exe app.php setup` sekali dari folder tersebut, lalu buka <http://localhost/apsi>.

Jika MySQL Anda memakai kata sandi, ubah `DB_PASS` di bagian atas `app.php`.

## Akun

- **Pengguna** (mahasiswa dan sivitas) mendaftar sendiri di layar masuk, pilihan **Pengguna → Belum punya akun? Buat akun**.
- **Admin pertama (pemilik)** dibuat lewat pilihan **Admin** di layar masuk saat belum ada admin. Gunakan email staf atau dosen.
- **Admin lain** ditambahkan pemilik lewat **Admin → Akun admin**.
- Semua akun wajib memakai email berakhiran `unsika.ac.id`. Akun admin tidak boleh memakai email `@student.unsika.ac.id`.
- Kata sandi disimpan dengan `password_hash` (bcrypt).

## Daftar file

| File | Isi |
| --- | --- |
| `app.php` | Konfigurasi database, sesi dan akun, keamanan CSRF, fungsi bantu, tampilan bersama (header, footer, CSS, JS), dan perintah `php app.php setup` |
| `index.php` | Beranda: hero, data transparansi, papan progres laporan, laporan saya, alur dan bantuan. `index.php?tiket=…` menampilkan detail laporan, dukungan, dan konfirmasi pelapor |
| `lapor.php` | Formulir ajukan keluhan 4 langkah (Keluhan, Kategori, Lokasi & foto, Kirim) dengan peringatan laporan serupa |
| `jadwal.php` | Kalender pemeliharaan bulanan dan daftar 30 hari ke depan; admin menambah, mengubah, dan menghapus jadwal |
| `ruangan.php` | Daftar ruangan beserta laporan aktif dan jadwal terdekat; admin mengelola ruangan |
| `admin.php` | Antrean verifikasi, tindak lanjut, moderasi, ekspor CSV, dan akun admin |
| `login.php` | Masuk dan buat akun dengan dua pilihan: Pengguna dan Admin |
| `logout.php` | Keluar dari akun |
| `setup.bat` | Menyalakan MySQL, menyiapkan database, dan menjalankan web |
| `brand/` | Logo dan foto gedung |
| `uploads/` | Foto yang diunggah (dibuat otomatis) |

## Tabel database

`users`, `ruangan`, `laporan`, `riwayat`, `dukungan`, `jadwal`. Bisa dilihat di phpMyAdmin (<http://localhost/phpmyadmin>) setelah Apache XAMPP dinyalakan.

## Alur laporan

Menunggu verifikasi → Diverifikasi → Diproses → Selesai (atau Ditolak dengan alasan).
Setiap perubahan tercatat di riwayat yang bisa dibaca publik. Pelapor bisa menyatakan "Sudah beres" dengan penilaian bintang,
atau "Belum beres" untuk membuka kembali laporannya.


## Pembaruan privasi anonimitas dan popup detail

- Jalankan `setup.bat` kembali setelah pembaruan agar migrasi kolom `owner_hash` dan `user_id` nullable diterapkan.
- Laporan anonim baru tidak menyimpan relasi `user_id`; sistem menyimpan HMAC satu arah untuk memastikan pemilik tetap dapat memantau laporan sendiri.
- Laporan anonim lama akan diputus dari `user_id` saat migrasi pertama dan dipetakan ke HMAC. Admin tidak melihat nama/email pemilik laporan anonim.
- File `.sf-anon-key.php` dibuat otomatis oleh setup. **Simpan dan jangan hapus file ini ketika memperbarui aplikasi**, karena diperlukan untuk mengenali laporan anonim milik pengguna. File dilindungi dari akses HTTP melalui `.htaccess`.
- Klik kartu/judul laporan pada Papan akan membuka detail dalam popup untuk pengguna dan admin. Detail tetap menggunakan halaman PHP asli di dalam iframe, tanpa berpindah antarmuka.
- Mode terang/gelap menggunakan tema eksplisit dan konsisten pada komponen admin.
