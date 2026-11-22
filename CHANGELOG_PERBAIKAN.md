# Catatan Perbaikan UI Suara Fasilkom

## Perubahan
- Tombol **Ajukan Keluhan** pada beranda membuka modal/popup, bukan berpindah ke halaman baru.
- Formulir 4 langkah asli `lapor.php` dimuat di dalam modal agar validasi, upload foto, pemeriksaan laporan serupa, CSRF, dan proses penyimpanan tetap memakai alur PHP yang sudah ada.
- Formulir pada modal menggunakan target top-level saat dikirim sehingga hasil sukses/validasi tetap ditangani oleh endpoint asli.
- Modal dapat ditutup melalui tombol X, klik area backdrop, atau tombol Escape.
- Perbaikan padding khusus `input[type=search]` untuk menghindari ikon pencarian menimpa teks placeholder.
- Bagian data transparansi dan kartu statistik memiliki animasi saat masuk viewport; scroll ke bagian data dibuat halus.
- Animasi menghormati preferensi `prefers-reduced-motion`.

## File yang diubah
- `index.php`
- `app.php`
- `lapor.php`

Tidak ada perubahan pada skema database.

## Pembaruan UI popup dan animasi
- Popup Ajukan Keluhan dibuat terpusat dengan ukuran ringkas mengikuti referensi visual.
- Latar belakang halaman diredupkan dengan blur ringan; dialog memakai transisi masuk/keluar.
- Header popup disederhanakan agar fokus pada judul dan tombol tutup.
- Tampilan `lapor.php?embed=1` dirapikan agar hanya menampilkan wizard empat langkah di dalam popup.
- Transisi antar-langkah memakai animasi `viewIn` seperti pada HTML referensi.
- Animasi bagian Data Transparansi diaktifkan dengan `IntersectionObserver`, sehingga elemen muncul bertahap saat masuk viewport.
- Preferensi `prefers-reduced-motion` tetap dihormati.


## Pembaruan desain premium — 22 November 2026
- Memperbesar dialog popup pengajuan keluhan menjadi maksimal 700 × 740 px pada desktop, tetap layar penuh pada perangkat kecil.
- Menambahkan garis aksen emas, bayangan bertingkat, transisi masuk/keluar yang lebih halus, dan animasi glow halus pada dialog.
- Menambahkan kategori `Lainnya` yang diarahkan ke `Bagian Umum` untuk verifikasi awal.
- Mendesain ulang hero beranda dengan animasi intro bertahap pada judul, garis aksen, tombol, dan potret.
- Menambahkan potret Dr. Oman Komarudin, S.Si., M.Kom. pada kartu profil di area hero.
- Menambahkan dukungan `prefers-reduced-motion` untuk pengguna yang memilih pengurangan animasi.

## Revisi formulir & panel admin — 22 November 2026
- Popup pengaduan kini menyesuaikan tinggi dengan isi langkah aktif melalui komunikasi ukuran iframe, sehingga ruang kosong berlebih berkurang.
- Kategori pengaduan kembali memakai dropdown/select yang dapat digulir, bukan seluruh kartu kategori yang ditampilkan sekaligus.
- Kategori `Lainnya` menampilkan kolom penjelasan khusus; detailnya ikut disimpan bersama uraian laporan.
- Pilihan identitas pelapor pada langkah akhir diubah menjadi `Anonim` atau `Publik`, dengan perilaku penyimpanan mengikuti pilihan.
- Tipografi pada area admin diseragamkan untuk judul, label, kontrol formulir, dan panel agar lebih konsisten.


## Revisi keamanan, tema, dan popup detail
- Laporan anonim baru disimpan tanpa `user_id`; pemilik tetap dapat mengakses laporan melalui HMAC satu arah (`owner_hash`).
- Migrasi setup memutus relasi akun dari laporan anonim lama dan menyimpan hash kepemilikan.
- Admin tidak melihat nama/email pada laporan anonim; FAQ dan kebijakan privasi diselaraskan.
- Mode terang/gelap ditetapkan eksplisit saat halaman dimuat dan disinkronkan lintas iframe.
- Panel admin memakai token warna tema dan ukuran tipografi konsisten; pseudo-element dekoratif yang berpotensi meluber dinonaktifkan pada panel admin.
- Klik laporan pada papan membuka detail laporan di modal untuk pengguna dan admin, termasuk navigasi detail lanjutan dari dalam modal.
- Setup membuat `.sf-anon-key.php`; file ini wajib disimpan saat pembaruan. Jangan menghapusnya.
