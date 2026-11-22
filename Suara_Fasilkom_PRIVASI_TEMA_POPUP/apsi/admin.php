<?php
/* Halaman admin: antrean verifikasi, tindak lanjut, catatan internal, gabung laporan duplikat, moderasi,
   pengaturan (kontak darurat, unit admin, ekspor CSV, data contoh), dan akun admin (khusus pemilik). */
require __DIR__ . '/app.php';
require_admin();
$me = user();
$rooms = ruangan_list();

/* ---------- Ekspor CSV ---------- */
if (($_GET['export'] ?? '') === 'csv') {
  $rows = q("SELECT l.tiket, l.judul, l.kategori, r.nama ruang, l.unit, l.prioritas, l.status, l.dibuat, l.selesai_pada, l.rating, l.anonim, l.tersembunyi, l.contoh,
    (SELECT COUNT(*) FROM dukungan d WHERE d.laporan_id = l.id) dukung FROM laporan l LEFT JOIN ruangan r ON r.id = l.ruangan_id
    WHERE l.status NOT IN ('digabung','dibatalkan') ORDER BY l.dibuat")->fetchAll();
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="rekap-suara-fasilkom-' . date('Y-m') . '.csv"');
  $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
  $cell = fn($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v;
  fputcsv($out, ['Nomor tiket', 'Judul', 'Kategori', 'Ruangan', 'Unit', 'Prioritas', 'Status', 'Tanggal masuk', 'Tanggal selesai', 'Lama (hari)', 'Dukungan', 'Penilaian pelapor', 'Anonim', 'Disembunyikan', 'Contoh']);
  foreach ($rows as $r) fputcsv($out, array_map($cell, [$r['tiket'], $r['judul'], $r['kategori'], $r['ruang'], $r['unit'], $r['prioritas'], STATUS[$r['status']], tgl($r['dibuat']),
    $r['selesai_pada'] ? tgl($r['selesai_pada']) : '', $r['selesai_pada'] ? round((strtotime($r['selesai_pada']) - strtotime($r['dibuat'])) / 86400, 1) : hari_sejak($r['dibuat']),
    $r['dukung'], $r['rating'] ?: '', $r['anonim'] ? 'Ya' : 'Tidak', $r['tersembunyi'] ? 'Ya' : 'Tidak', $r['contoh'] ? 'Ya' : 'Tidak']));
  exit;
}

/* ---------- Data contoh ---------- */
function isi_contoh(int $uid, array $rooms): void {
  $rid = fn($i) => $rooms ? $rooms[$i % count($rooms)]['id'] : null;
  $ago = fn($d) => date('Y-m-d H:i:s', time() - $d * 86400);
  $R = [
    ['AC Lab Komputer mati sejak minggu lalu', 'Laboratorium', 0, 'Suhu ruangan mencapai 31°C saat praktikum siang. Beberapa PC sempat mati sendiri karena panas.', 'diproses', 'Sarpras', 'tinggi', 8, [[8, 'Tim Verifikasi', 'Laporan valid. Diteruskan ke Sarpras.'], [6, 'Sarpras', 'Kompresor rusak, suku cadang dipesan.'], [1, 'Sarpras', 'Suku cadang tiba. Pemasangan dijadwalkan lusa.']], null, null],
    ['Wi-Fi mahasiswa putus-putus di lantai 4', 'Jaringan & Wi-Fi', 1, 'Koneksi terputus setiap 5–10 menit, terutama jam 10.00–14.00. Mengganggu ujian daring.', 'diproses', 'Unit TIK', 'tinggi', 4, [[4, 'Tim Verifikasi', '3 laporan serupa digabung. Diteruskan ke Unit TIK.'], [2, 'Unit TIK', 'Access point lantai 4 kelebihan beban. Penambahan 2 AP diajukan.']], null, null],
    ['Nilai UTS Basis Data belum muncul di SIAKAD', 'Akademik & Perkuliahan', 2, 'Nilai UTS kelas Basis Data B sudah diumumkan di kelas tapi belum masuk SIAKAD.', 'selesai', 'Bagian Akademik', 'sedang', 12, [[12, 'Tim Verifikasi', 'Diteruskan ke Bagian Akademik.'], [10, 'Bagian Akademik', 'Nilai sudah diunggah dan tersinkron. Status diubah ke Selesai.']], 10, 5],
    ['Antrean surat aktif kuliah lebih dari 7 hari kerja', 'Layanan Administrasi', 3, 'Surat aktif kuliah untuk beasiswa belum terbit, padahal batas beasiswa sebentar lagi.', 'verifikasi', 'Bagian Umum', 'sedang', 3, [[2, 'Tim Verifikasi', 'Laporan valid. Diteruskan ke Bagian Umum.']], null, null],
    ['Proyektor ruang kelas warnanya pudar', 'Fasilitas & Ruang Kelas', 4, 'Tampilan slide kebiruan dan kode sulit dibaca dari baris belakang.', 'selesai', 'Sarpras', 'rendah', 20, [[19, 'Tim Verifikasi', 'Diteruskan ke Sarpras.'], [15, 'Sarpras', 'Lampu proyektor diganti dan sudah diuji. Status diubah ke Selesai.']], 15, 4],
    ['Permintaan kursi tambahan di ruang diskusi', 'Fasilitas & Ruang Kelas', 5, 'Kursi sering kurang saat jam sibuk.', 'ditolak', null, 'rendah', 9, [[8, 'Tim Verifikasi', 'Ditolak. Alasan: kapasitas ruangan sudah maksimal sesuai standar K3.']], null, null],
  ];
  foreach ($R as $i => [$judul, $kat, $ri, $isi, $st, $unit, $prio, $d, $log, $done, $rating]) {
    do { $t = tiket_baru(); } while (q('SELECT 1 FROM laporan WHERE tiket = ?', [$t])->fetchColumn());
    q('INSERT INTO laporan (tiket, user_id, judul, isi, kategori, ruangan_id, anonim, status, unit, prioritas, dibuat, selesai_pada, rating, konfirmasi, contoh) VALUES (?,?,?,?,?,?,1,?,?,?,?,?,?,?,1)',
      [$t, $uid, $judul, $isi, $kat, $rid($ri), $st, $unit, $prio, $ago($d), $done !== null ? $ago($done) : null, $rating, $rating ? 'beres' : null]);
    $id = (int)db()->lastInsertId();
    q('INSERT INTO riwayat (laporan_id, oleh, teks, dibuat) VALUES (?, "Sistem", "Laporan diterima.", ?)', [$id, $ago($d)]);
    foreach ($log as [$ld, $oleh, $teks]) q('INSERT INTO riwayat (laporan_id, oleh, teks, dibuat) VALUES (?, ?, ?, ?)', [$id, $oleh, $teks, $ago($ld)]);
    if ($rating) q('INSERT INTO riwayat (laporan_id, oleh, teks, dibuat) VALUES (?, "Pelapor", ?, ?)', [$id, "Pelapor mengonfirmasi masalah sudah beres ($rating/5).", $ago(max(0, $done - 1))]);
  }
  $in = fn($d) => date('Y-m-d', time() + $d * 86400);
  foreach ([['Penambahan access point lantai 3–4', 'Unit TIK', 45, 'Rp 38,5 jt', 25, 'Pengadaan disetujui, menunggu pengiriman perangkat.'],
            ['Penggantian 6 unit AC laboratorium', 'Sarpras', 70, 'Rp 72 jt', 10, '4 dari 6 unit sudah terpasang.'],
            ['Digitalisasi surat keterangan mahasiswa', 'Bagian Umum', 30, 'Internal', -20, 'Uji coba formulir daring tertunda.']] as [$n, $u, $p, $a, $t, $k])
    q('INSERT INTO proyek (nama, unit, progres, anggaran, target, kabar, contoh, diperbarui) VALUES (?,?,?,?,?,?,1,NOW())', [$n, $u, $p, $a, $in($t), $k]);
  foreach ([['Pemasangan AC baru lab', 0, 'Sarpras', 2, 2, '08.00–15.00', 'berat', 'Lab ditutup. Praktikum dipindah sementara.', 'terjadwal'],
            ['Penambahan access point lantai 4', 1, 'Unit TIK', 6, 6, '18.00–21.00', 'sedang', 'Wi-Fi lantai 4 padam sementara selama pemasangan.', 'terjadwal'],
            ['Servis rutin proyektor ruang kelas', 4, 'Sarpras', 9, 10, '07.00–09.00', 'ringan', 'Proyektor diservis bergantian sebelum kuliah pagi.', 'terjadwal'],
            ['Pembersihan tandon air gedung', 5, 'Sarpras', -3, -3, '06.00–10.00', 'sedang', 'Air toilet mati sementara.', 'selesai']] as [$j, $ri, $u, $a, $b, $jam, $dm, $ket, $st])
    q('INSERT INTO jadwal (judul, ruangan_id, unit, mulai, sampai, jam, dampak, keterangan, status, contoh) VALUES (?,?,?,?,?,?,?,?,?,1)', [$j, $rid($ri), $u, $in($a), $b !== $a ? $in($b) : null, $jam, $dm, $ket, $st]);
}

/* ---------- Aksi ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  check_csrf();
  $aksi = $_POST['aksi'] ?? '';
  /* Pengaturan */
  if ($aksi === 'kontak') {
    $lines = array_slice(array_filter(array_map('trim', preg_split('/\r?\n/', $_POST['kontak'] ?? '')), fn($l) => substr_count($l, '|') >= 1), 0, 10);
    set_pengaturan('kontak_darurat', implode("\n", array_map(fn($l) => mb_substr($l, 0, 160), $lines)));
    flash('Kontak darurat tersimpan.'); go('admin.php?tab=pengaturan');
  }
  if ($aksi === 'unit_admin') {
    if (!is_owner()) { flash('Hanya pemilik yang bisa mengubah unit admin.', 'err'); go('admin.php?tab=pengaturan'); }
    $u = $_POST['unit'] ?? ''; q("UPDATE users SET unit = ? WHERE id = ? AND role IN ('admin','owner')", [in_array($u, UNITS, true) ? $u : null, (int)$_POST['id']]);
    flash('Unit admin diperbarui.'); go('admin.php?tab=pengaturan');
  }
  if ($aksi === 'contoh_isi') { isi_contoh((int)$me['id'], $rooms); flash('Data contoh ditambahkan.'); go('admin.php?tab=pengaturan'); }
  if ($aksi === 'contoh_hapus') {
    q('DELETE FROM laporan WHERE contoh = 1'); q('DELETE FROM proyek WHERE contoh = 1'); q('DELETE FROM jadwal WHERE contoh = 1');
    flash('Data contoh dihapus.'); go('admin.php?tab=pengaturan');
  }
  /* Akun admin */
  if ($aksi === 'tambah_admin' || $aksi === 'hapus_admin') {
    if (!is_owner()) { flash('Hanya pemilik yang bisa mengatur akun admin.', 'err'); go('admin.php?tab=akun'); }
    if ($aksi === 'hapus_admin') { q("DELETE FROM users WHERE id = ? AND role = 'admin'", [(int)$_POST['id']]); flash('Akun admin dihapus.'); go('admin.php?tab=akun'); }
    $nama = trim($_POST['nama'] ?? ''); $email = strtolower(trim($_POST['email'] ?? '')); $pw = (string)($_POST['password'] ?? ''); $unit = $_POST['unit'] ?? '';
    $e = mb_strlen($nama) < 2 ? 'Isi nama admin minimal 2 huruf.' : (!preg_match(EMAIL_KAMPUS, $email) || preg_match(EMAIL_MAHASISWA, $email) ? 'Gunakan email staf atau dosen UNSIKA, bukan email mahasiswa.'
      : (strlen($pw) < 8 ? 'Kata sandi awal minimal 8 karakter.' : (q('SELECT 1 FROM users WHERE email = ?', [$email])->fetchColumn() ? 'Email ini sudah terdaftar.' : '')));
    if ($e) { flash($e, 'err'); go('admin.php?tab=akun'); }
    q("INSERT INTO users (nama, email, password_hash, role, unit, dibuat) VALUES (?, ?, ?, 'admin', ?, NOW())", [$nama, $email, password_hash($pw, PASSWORD_DEFAULT), in_array($unit, UNITS, true) ? $unit : null]);
    flash('Akun admin ditambahkan. Berikan kata sandi awalnya langsung ke orangnya.'); go('admin.php?tab=akun');
  }
  /* Aksi pada laporan */
  $l = q('SELECT * FROM laporan WHERE tiket = ?', [$_POST['tiket'] ?? ''])->fetch();
  if (!$l) { flash('Laporan tidak ditemukan.', 'err'); go('admin.php'); }
  $back = 'admin.php?kelola=' . urlencode($l['tiket']);
  if ($aksi === 'verifikasi' && $l['status'] === 'menunggu') {
    $judul = trim($_POST['judul'] ?? ''); $isi = trim($_POST['isi'] ?? ''); $kat = $_POST['kategori'] ?? ''; $rid = (int)($_POST['ruangan_id'] ?? 0);
    $unit = $_POST['unit'] ?? ''; $prio = $_POST['prioritas'] ?? 'sedang'; $st = ($_POST['status'] ?? '') === 'diproses' ? 'diproses' : 'verifikasi'; $note = trim($_POST['tanggapan'] ?? '');
    if (mb_strlen($judul) < 5 || mb_strlen($isi) < 10 || !isset(KATEGORI[$kat]) || !in_array($unit, UNITS, true) || !in_array($prio, ['rendah', 'sedang', 'tinggi'], true)) { flash('Judul, uraian, kategori, dan unit harus terisi dengan benar.', 'err'); go($back); }
    q('UPDATE laporan SET judul=?, isi=?, kategori=?, ruangan_id=?, unit=?, prioritas=?, status=? WHERE id=?', [$judul, $isi, $kat, $rid ?: null, $unit, $prio, $st, $l['id']]);
    tambah_riwayat($l['id'], actor(), ($note !== '' ? $note . ' ' : 'Laporan valid dan dipublikasikan. ') . "Diteruskan ke $unit.");
    flash('Laporan diverifikasi dan tampil di papan publik.'); go($back);
  }
  if ($aksi === 'tolak') {
    $alasan = trim($_POST['alasan'] ?? '');
    if (mb_strlen($alasan) < 8) { flash('Tulis alasan penolakan minimal 8 karakter.', 'err'); go($back); }
    q("UPDATE laporan SET status = 'ditolak', tersembunyi = ? WHERE id = ?", [isset($_POST['publik']) ? 0 : 1, $l['id']]);
    tambah_riwayat($l['id'], actor(), 'Ditolak. Alasan: ' . $alasan);
    flash('Laporan ditolak dan alasannya dikirim ke pelapor.'); go($back);
  }
  if ($aksi === 'tindak') {
    $st = $_POST['status'] ?? $l['status']; $unit = $_POST['unit'] ?? ''; $prio = $_POST['prioritas'] ?? $l['prioritas']; $note = trim($_POST['tanggapan'] ?? '');
    if (!in_array($st, ['verifikasi', 'diproses', 'selesai', 'ditolak'], true) || ($unit !== '' && !in_array($unit, UNITS, true)) || !in_array($prio, ['rendah', 'sedang', 'tinggi'], true)) { flash('Isian tidak valid.', 'err'); go($back); }
    $foto = simpan_foto('foto', $fErr); if ($fErr) { flash($fErr, 'err'); go($back); }
    if ($st !== $l['status'] && $note === '') { flash('Tulis tanggapan publik saat mengubah status, agar pelapor tahu alasannya.', 'err'); go($back); }
    $parts = [];
    if ($st !== $l['status']) $parts[] = 'Status diubah ke ' . STATUS[$st] . '.';
    if ($unit !== (string)$l['unit']) $parts[] = $unit ? "Diteruskan ke $unit." : 'Unit dikosongkan.';
    if ($prio !== $l['prioritas']) $parts[] = "Prioritas diubah ke $prio.";
    if ($note !== '') $parts[] = $note;
    if (!$parts && !$foto) { flash('Belum ada perubahan untuk disimpan.', 'err'); go($back); }
    if (!$parts) $parts[] = 'Foto tindak lanjut ditambahkan.';
    $selesai = $st === 'selesai' ? ($l['status'] === 'selesai' ? $l['selesai_pada'] : date('Y-m-d H:i:s')) : null;
    q('UPDATE laporan SET status=?, unit=?, prioritas=?, selesai_pada=?, konfirmasi = IF(? = "selesai", konfirmasi, NULL), rating = IF(? = "selesai", rating, NULL) WHERE id=?', [$st, $unit ?: null, $prio, $selesai, $st, $st, $l['id']]);
    tambah_riwayat($l['id'], actor(), implode(' ', $parts), $foto);
    flash('Tindak lanjut tersimpan dan tampil di riwayat publik.'); go($back);
  }
  if ($aksi === 'catatan') {
    $t = trim($_POST['teks'] ?? '');
    if ($t === '') { flash('Catatan masih kosong.', 'err'); go($back); }
    q('INSERT INTO catatan (laporan_id, user_id, teks, dibuat) VALUES (?, ?, ?, NOW())', [$l['id'], $me['id'], mb_substr($t, 0, 2000)]);
    flash('Catatan internal tersimpan.'); go($back . '#catatan');
  }
  if ($aksi === 'gabung') {
    $t = q('SELECT * FROM laporan WHERE tiket = ?', [strtoupper(trim($_POST['target'] ?? ''))])->fetch();
    if (!$t || $t['id'] == $l['id'] || !in_array($t['status'], ['verifikasi', 'diproses'], true)) { flash('Pilih laporan tujuan yang sedang ditangani.', 'err'); go($back); }
    db()->beginTransaction();
    q("UPDATE laporan SET status = 'digabung', digabung_ke = ? WHERE id = ?", [$t['id'], $l['id']]);
    q('UPDATE laporan SET digabung_ke = ? WHERE digabung_ke = ?', [$t['id'], $l['id']]);
    q('UPDATE laporan SET dup_count = dup_count + 1 + ? WHERE id = ?', [(int)$l['dup_count'], $t['id']]);
    q('INSERT IGNORE INTO dukungan (laporan_id, user_id) SELECT ?, user_id FROM dukungan WHERE laporan_id = ? AND user_id IS NOT NULL AND user_id <> ?', [$t['id'], $l['id'], $t['user_id']]);
    tambah_riwayat($l['id'], actor(), "Digabung ke {$t['tiket']} karena membahas masalah yang sama.");
    tambah_riwayat($t['id'], actor(), "Laporan serupa ({$l['tiket']}) digabung ke tiket ini.");
    db()->commit();
    flash("Laporan digabung ke {$t['tiket']}."); go('admin.php?kelola=' . urlencode($t['tiket']));
  }
  if ($aksi === 'sembunyi') {
    q('UPDATE laporan SET tersembunyi = 1 - tersembunyi WHERE id = ?', [$l['id']]);
    flash($l['tersembunyi'] ? 'Laporan tampil lagi ke publik.' : 'Laporan disembunyikan dari publik.'); go($back);
  }
  go($back);
}

/* ---------- Tampilan ---------- */
$tab = $_GET['tab'] ?? 'antrean';
$cnt = q("SELECT SUM(status='menunggu') baru, SUM(status='menunggu' AND dibuat < NOW() - INTERVAL " . VERIF_DAYS . " DAY) lama, SUM(konfirmasi_baru=1) konf,
  SUM(status IN ('verifikasi','diproses')) aktif, SUM(prioritas='tinggi' AND status IN ('verifikasi','diproses')) tinggi FROM laporan")->fetch();
page_head('Admin', 'admin');
?>
<main class="wrap">
<?php if (isset($_GET['kelola'])):
  $l = q('SELECT l.*, r.nama ruang, u.nama pelapor_nama, u.email pelapor_email, (SELECT COUNT(*) FROM dukungan d WHERE d.laporan_id = l.id) dukung
          FROM laporan l LEFT JOIN ruangan r ON r.id = l.ruangan_id LEFT JOIN users u ON u.id = l.user_id WHERE l.tiket = ?', [$_GET['kelola']])->fetch();
  if (!$l): ?><div class="panel empty"><b>Laporan tidak ditemukan.</b><a class="btn" href="admin.php">Kembali</a></div><?php else:
  if ($l['konfirmasi_baru']) q('UPDATE laporan SET konfirmasi_baru = 0 WHERE id = ?', [$l['id']]);
  $riw = q('SELECT * FROM riwayat WHERE laporan_id = ? ORDER BY dibuat, id', [$l['id']])->fetchAll();
  $notes = q('SELECT c.*, u.nama FROM catatan c JOIN users u ON u.id = c.user_id WHERE c.laporan_id = ? ORDER BY c.dibuat', [$l['id']])->fetchAll();
  $mirip = laporan_mirip($l['judul'] . ' ' . $l['ruang'], $l['kategori'], (int)$l['id']);
  $mid = array_column($mirip, 'tiket');
  $targets = q("SELECT tiket, judul FROM laporan WHERE status IN ('verifikasi','diproses') AND id <> ? ORDER BY dibuat DESC LIMIT 200", [$l['id']])->fetchAll();
  usort($targets, fn($a, $b) => in_array($b['tiket'], $mid, true) <=> in_array($a['tiket'], $mid, true));
  $tujuan = $l['digabung_ke'] ? q('SELECT tiket FROM laporan WHERE id = ?', [$l['digabung_ke']])->fetchColumn() : null; ?>
  <div class="page-head"><div><a class="h" href="index.php?hal=papan">‹ Kembali ke papan</a><h1 style="margin-top:6px"><?= e($l['judul']) ?></h1><p class="mono"><?= e($l['tiket']) ?> · dikirim <?= e(tgl($l['dibuat'], true)) ?></p></div>
    <div class="btn-row"><?= pill($l['status']) ?><?php if ($l['tersembunyi']): ?><span class="tag hid">Disembunyikan</span><?php endif; ?><?php if ($l['contoh']): ?><span class="tag demo">Contoh</span><?php endif; ?><a class="btn sm" href="index.php?tiket=<?= urlencode($l['tiket']) ?>">Lihat halaman publik</a></div></div>
  <?php if ($tujuan): ?><div class="box"><span>Laporan ini sudah digabung ke <a class="btn sm" href="admin.php?kelola=<?= urlencode($tujuan) ?>"><?= e($tujuan) ?></a>.</span></div><?php endif; ?>
  <?php if ($l['konfirmasi']): ?><div class="box <?= $l['konfirmasi'] === 'beres' ? 'ok' : 'warn' ?>"><h3><?= $l['konfirmasi'] === 'beres' ? 'Pelapor menyatakan sudah beres' : 'Pelapor minta dibuka kembali' ?></h3><?php if ($l['rating']): ?><span>Penilaian: <span class="stars-txt"><?= str_repeat('★', (int)$l['rating']) ?></span></span><?php endif; ?><?php if ($l['catatan_pelapor']): ?><div class="note"><small>Catatan pelapor (privat)</small><?= e($l['catatan_pelapor']) ?></div><?php endif; ?></div>
  <?php elseif ($l['catatan_pelapor']): ?><div class="box warn"><h3>Pelapor minta dibuka kembali</h3><div class="note"><small>Catatan pelapor (privat)</small><?= e($l['catatan_pelapor']) ?></div></div><?php endif; ?>
  <div class="grid2">
    <section class="panel" style="display:grid;gap:16px">
      <dl class="kv"><dt>Pelapor</dt><dd><?= $l['anonim'] ? 'Anonim — identitas tidak ditautkan ke laporan' : e(($l['pelapor_nama'] ?? 'Pengguna') . ' · ' . ($l['pelapor_email'] ?? '')) ?></dd><dt>Kategori</dt><dd><?= e($l['kategori']) ?></dd><dt>Lokasi</dt><dd><?= e($l['ruang'] ?? '–') ?></dd><dt>Unit</dt><dd><?= e($l['unit'] ?: '–') ?></dd><dt>Dukungan</dt><dd><?= (int)$l['dukung'] ?><?= $l['dup_count'] ? ' · ' . (int)$l['dup_count'] . ' laporan serupa digabung' : '' ?></dd></dl>
      <p style="white-space:pre-line"><?= e($l['isi']) ?></p>
      <?php if ($f = foto_url($l['foto'])): ?><a class="photo" href="<?= e($f) ?>"><img src="<?= e($f) ?>" alt="Foto pendukung"></a><?php endif; ?>
      <div><h2 style="font-size:17px;margin-bottom:12px">Riwayat penanganan</h2><ol class="tl"><?php foreach ($riw as $r): ?><li><span><span class="w"><?= e(tgl($r['dibuat'], true)) ?></span><b><?= e($r['oleh']) ?></b> — <?= e($r['teks']) ?><?php if ($rf = foto_url($r['foto'])): ?><br><a class="photo" style="margin-top:8px;max-width:260px" href="<?= e($rf) ?>"><img src="<?= e($rf) ?>" alt="Foto tindak lanjut"></a><?php endif; ?></span></li><?php endforeach; ?></ol></div>
      <div class="box" id="catatan"><h3>Catatan internal</h3><span class="h">Hanya terlihat oleh admin. Tidak tampil di riwayat publik.</span>
        <?php foreach ($notes as $n): ?><div class="note"><small><?= e(tgl($n['dibuat'], true)) ?> · <?= e($n['nama']) ?></small><?= e($n['teks']) ?></div><?php endforeach; ?><?php if (!$notes): ?><span class="h">Belum ada catatan.</span><?php endif; ?>
        <form method="post" class="stack" style="gap:8px"><?= csrf_field() ?><input type="hidden" name="aksi" value="catatan"><input type="hidden" name="tiket" value="<?= e($l['tiket']) ?>"><textarea name="teks" style="min-height:64px" placeholder="Contoh: Sudah koordinasi dengan vendor AC, menunggu PO." aria-label="Catatan internal"></textarea><div><button class="btn sm" type="submit">Tambah catatan</button></div></form></div>
    </section>
    <aside style="display:grid;gap:16px">
    <?php if ($l['status'] === 'menunggu'): ?>
      <?php if ($mirip): ?><div class="box warn"><h3>Mungkin duplikat</h3><div class="dup"><?php foreach ($mirip as $m): ?><div class="dup-item"><a href="admin.php?kelola=<?= urlencode($m['tiket']) ?>"><b><?= e($m['judul']) ?></b><br><span class="h"><?= e($m['tiket']) ?> · <?= e(STATUS[$m['status']]) ?></span></a>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="aksi" value="gabung"><input type="hidden" name="tiket" value="<?= e($l['tiket']) ?>"><input type="hidden" name="target" value="<?= e($m['tiket']) ?>"><button class="btn sm" type="submit">Gabungkan</button></form></div><?php endforeach; ?></div></div><?php endif; ?>
      <form class="panel stack" method="post"><?= csrf_field() ?><input type="hidden" name="aksi" value="verifikasi"><input type="hidden" name="tiket" value="<?= e($l['tiket']) ?>">
        <h2>Verifikasi laporan</h2><span class="h">Rapikan judul dan uraian sebelum dipublikasikan. Hapus nomor HP, NIM, nama orang lain, atau kata kasar.</span>
        <label for="judul">Judul<input type="text" id="judul" name="judul" maxlength="90" value="<?= e($l['judul']) ?>"></label>
        <label for="isi">Uraian<textarea id="isi" name="isi"><?= e($l['isi']) ?></textarea></label>
        <div class="two"><label for="kategori">Kategori<select id="kategori" name="kategori"><?php foreach (KATEGORI as $k => $u): ?><option <?= $l['kategori'] === $k ? 'selected' : '' ?>><?= e($k) ?></option><?php endforeach; ?></select></label>
          <label for="ruangan_id">Lokasi<select id="ruangan_id" name="ruangan_id"><?php foreach ($rooms as $r): ?><option value="<?= $r['id'] ?>" <?= $l['ruangan_id'] == $r['id'] ? 'selected' : '' ?>><?= e($r['nama']) ?></option><?php endforeach; ?></select></label></div>
        <div class="two"><label for="unit">Teruskan ke unit<select id="unit" name="unit"><?php foreach (UNITS as $u): ?><option <?= (KATEGORI[$l['kategori']] ?? '') === $u ? 'selected' : '' ?>><?= e($u) ?></option><?php endforeach; ?></select></label>
          <label for="prioritas">Prioritas<select id="prioritas" name="prioritas"><option value="rendah">Rendah</option><option value="sedang" selected>Sedang</option><option value="tinggi">Tinggi</option></select></label></div>
        <label for="status">Status awal<select id="status" name="status"><option value="verifikasi">Diverifikasi</option><option value="diproses">Langsung diproses</option></select></label>
        <label for="tanggapan">Tanggapan publik <span class="h">Opsional.</span><textarea id="tanggapan" name="tanggapan" style="min-height:70px" placeholder="Contoh: Laporan valid, teknisi dijadwalkan besok."></textarea></label>
        <button class="btn primary" type="submit">Verifikasi &amp; publikasikan</button></form>
      <form class="panel stack" method="post"><?= csrf_field() ?><input type="hidden" name="aksi" value="tolak"><input type="hidden" name="tiket" value="<?= e($l['tiket']) ?>">
        <h2>Tolak laporan</h2>
        <label for="alasan">Alasan penolakan <span class="h">Dikirim ke pelapor.</span><textarea id="alasan" name="alasan" style="min-height:70px" placeholder="Contoh: Lokasi tidak jelas. Mohon kirim ulang dengan nomor ruangan."></textarea></label>
        <label class="chk"><input type="checkbox" name="publik"> Tampilkan di papan publik beserta alasannya</label>
        <span class="h">Biarkan tidak dicentang untuk spam atau laporan berisi kata kasar.</span>
        <button class="btn danger" type="submit">Tolak laporan</button></form>
    <?php elseif (in_array($l['status'], ['verifikasi', 'diproses', 'selesai', 'ditolak'], true)): ?>
      <form class="panel stack" method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="aksi" value="tindak"><input type="hidden" name="tiket" value="<?= e($l['tiket']) ?>">
        <h2>Tindak lanjut admin</h2>
        <div class="two"><label for="status">Status<select id="status" name="status"><?php foreach (['verifikasi', 'diproses', 'selesai', 'ditolak'] as $s): ?><option value="<?= $s ?>" <?= $l['status'] === $s ? 'selected' : '' ?>><?= e(STATUS[$s]) ?></option><?php endforeach; ?></select></label>
          <label for="unit">Unit<select id="unit" name="unit"><option value="">Belum ditentukan</option><?php foreach (UNITS as $u): ?><option <?= $l['unit'] === $u ? 'selected' : '' ?>><?= e($u) ?></option><?php endforeach; ?></select></label></div>
        <label for="prioritas">Prioritas<select id="prioritas" name="prioritas"><?php foreach (['rendah' => 'Rendah', 'sedang' => 'Sedang', 'tinggi' => 'Tinggi'] as $k => $p): ?><option value="<?= $k ?>" <?= $l['prioritas'] === $k ? 'selected' : '' ?>><?= $p ?></option><?php endforeach; ?></select></label>
        <label for="tanggapan">Tanggapan publik <span class="h">Tampil di riwayat dan bisa dibaca semua orang. Wajib saat status diubah.</span><textarea id="tanggapan" name="tanggapan" style="min-height:80px" placeholder="Contoh: Teknisi dijadwalkan memeriksa besok pukul 09.00."></textarea></label>
        <label for="foto">Lampiran foto <span class="h">Opsional, misalnya foto sesudah perbaikan.</span><input type="file" id="foto" name="foto" accept="image/jpeg,image/png,image/webp"></label>
        <button class="btn primary" type="submit">Simpan tindak lanjut</button></form>
    <?php endif; ?>
    <?php if (in_array($l['status'], ['menunggu', 'verifikasi', 'diproses'], true)): ?>
      <form class="panel stack" method="post"><?= csrf_field() ?><input type="hidden" name="aksi" value="gabung"><input type="hidden" name="tiket" value="<?= e($l['tiket']) ?>">
        <h2>Gabungkan sebagai duplikat</h2><span class="h">Laporan ini ditutup dan diarahkan ke laporan tujuan. Dukungannya ikut dipindahkan.</span>
        <label for="target">Laporan tujuan<select id="target" name="target"><option value="">Pilih laporan tujuan</option><?php foreach ($targets as $t): ?><option value="<?= e($t['tiket']) ?>"><?= in_array($t['tiket'], $mid, true) ? 'Mirip · ' : '' ?><?= e($t['tiket']) ?> — <?= e(mb_substr($t['judul'], 0, 60)) ?></option><?php endforeach; ?></select></label>
        <div><button class="btn sm" type="submit">Gabungkan</button></div></form>
    <?php endif; ?>
      <form class="panel" method="post" style="display:grid;gap:10px"><?= csrf_field() ?><input type="hidden" name="aksi" value="sembunyi"><input type="hidden" name="tiket" value="<?= e($l['tiket']) ?>">
        <h2>Moderasi</h2><span class="h">Sembunyikan laporan yang memuat data pribadi atau kata kasar. Laporan tetap tersimpan dan bisa ditampilkan lagi.</span>
        <button class="btn <?= $l['tersembunyi'] ? '' : 'danger' ?>" type="submit"><?= $l['tersembunyi'] ? 'Tampilkan lagi ke publik' : 'Sembunyikan dari publik' ?></button></form>
    </aside>
  </div>
<?php endif; else: ?>
  <div class="page-head"><div><span class="eyebrow">Admin</span><h1>Halaman admin</h1><p>Verifikasi laporan baru, teruskan ke unit, catat setiap tindak lanjut, dan atur pengaturan sistem.</p></div>
    <div class="btn-row"><a class="btn" href="index.php?hal=papan&amp;tampil=papan">Buka papan</a><a class="btn" href="admin.php?export=csv">Unduh rekap CSV</a></div></div>
  <div class="feat" style="border-top:1px solid var(--ink)">
    <div><span class="k">Perlu diverifikasi</span><span class="v"><?= (int)$cnt['baru'] ?></span></div>
    <div><span class="k">Menunggu &gt; <?= VERIF_DAYS ?> hari</span><span class="v" style="<?= $cnt['lama'] ? 'color:var(--warn)' : '' ?>"><?= (int)$cnt['lama'] ?></span></div>
    <div><span class="k">Tanggapan pelapor</span><span class="v" style="<?= $cnt['konf'] ? 'color:var(--warn)' : '' ?>"><?= (int)$cnt['konf'] ?></span></div>
    <div><span class="k">Sedang ditangani</span><span class="v"><?= (int)$cnt['aktif'] ?></span><span class="d"><?= (int)$cnt['tinggi'] ?> prioritas tinggi</span></div>
  </div>
  <div class="chips"><?php foreach (['antrean' => 'Perlu diverifikasi', 'konfirmasi' => 'Tanggapan pelapor', 'aktif' => 'Sedang ditangani', 'semua' => 'Semua laporan', 'pengaturan' => 'Pengaturan'] + (is_owner() ? ['akun' => 'Akun admin'] : []) as $k => $lbl): ?><a class="chip <?= $tab === $k ? 'on' : '' ?>" href="admin.php?tab=<?= $k ?>"><?= e($lbl) ?></a><?php endforeach; ?></div>
  <?php if ($tab === 'pengaturan'):
    $adm = q("SELECT id, nama, email, role, unit FROM users WHERE role IN ('owner','admin') ORDER BY role DESC, nama")->fetchAll();
    $nContoh = (int)q('SELECT (SELECT COUNT(*) FROM laporan WHERE contoh = 1) + (SELECT COUNT(*) FROM proyek WHERE contoh = 1) + (SELECT COUNT(*) FROM jadwal WHERE contoh = 1)')->fetchColumn(); ?>
    <div class="grid-even">
      <form class="panel stack" method="post"><?= csrf_field() ?><input type="hidden" name="aksi" value="kontak"><h2>Kontak darurat</h2>
        <label for="kontak">Satu kontak per baris, format: <span class="mono">Nama layanan | nomor atau email</span><textarea id="kontak" name="kontak" placeholder="Satgas PPKS | 0812-xxxx-xxxx&#10;Pos Keamanan Gedung | ext. 1234"><?= e(pengaturan('kontak_darurat')) ?></textarea></label>
        <span class="h">Tampil di halaman Alur &amp; bantuan. Isi hanya kontak resmi.</span><div><button class="btn primary sm" type="submit">Simpan kontak</button></div></form>
      <section class="panel"><h2>Tim admin per unit</h2><p class="psub">Unit menentukan filter awal papan admin dan nama yang tercatat di riwayat tindak lanjut.</p>
        <div class="tbl-wrap"><table><thead><tr><th>Admin</th><th>Unit</th></tr></thead><tbody><?php foreach ($adm as $a): ?><tr><td><b><?= e($a['nama']) ?></b><small><?= e($a['email']) ?></small></td>
          <td><?php if (is_owner()): ?><form method="post" class="btn-row"><?= csrf_field() ?><input type="hidden" name="aksi" value="unit_admin"><input type="hidden" name="id" value="<?= $a['id'] ?>"><select name="unit" aria-label="Unit <?= e($a['nama']) ?>"><option value="">Tim Verifikasi</option><?php foreach (UNITS as $u): ?><option <?= $a['unit'] === $u ? 'selected' : '' ?>><?= e($u) ?></option><?php endforeach; ?></select><button class="btn sm" type="submit">Simpan</button></form>
          <?php else: ?><?= e($a['unit'] ?: 'Tim Verifikasi') ?><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php if (!is_owner()): ?><span class="h">Hanya pemilik yang bisa mengubah unit admin.</span><?php endif; ?></section>
      <section class="panel"><h2>Ekspor rekap</h2><p class="psub">Unduh semua laporan sebagai CSV untuk laporan ke pimpinan fakultas.</p><a class="btn" href="admin.php?export=csv">Unduh rekap CSV</a></section>
      <section class="panel"><h2>Data contoh</h2><p class="psub">Untuk demo atau presentasi. Semua data contoh diberi label "Contoh" dan bisa dihapus sekaligus.</p>
        <div class="btn-row"><form method="post"><?= csrf_field() ?><input type="hidden" name="aksi" value="contoh_isi"><button class="btn" type="submit">Isi data contoh</button></form>
          <form method="post" data-confirm="Hapus semua data contoh?"><?= csrf_field() ?><input type="hidden" name="aksi" value="contoh_hapus"><button class="btn danger" type="submit" <?= $nContoh ? '' : 'disabled' ?>>Hapus <?= $nContoh ?> data contoh</button></form></div></section>
    </div>
  <?php elseif ($tab === 'akun' && is_owner()):
    $adm = q("SELECT id, nama, email, role, unit FROM users WHERE role IN ('owner','admin') ORDER BY role DESC, nama")->fetchAll();
    $nUser = (int)q("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn(); ?>
    <div class="grid2">
      <section class="panel"><h2>Akun admin</h2><p class="psub">Akun admin terpisah dari akun pengguna. Admin masuk lewat pilihan "Admin" di layar masuk dan harus memakai email staf atau dosen. <?= $nUser ?> akun pengguna terdaftar; akun pengguna tidak bisa diubah menjadi admin.</p>
        <div class="tbl-wrap"><table><thead><tr><th>Nama</th><th>Email</th><th>Peran</th><th></th></tr></thead><tbody><?php foreach ($adm as $a): ?><tr><td><b><?= e($a['nama']) ?></b><?php if ($a['unit']): ?><small><?= e($a['unit']) ?></small><?php endif; ?></td><td><?= e($a['email']) ?></td><td><span class="role-pill <?= $a['role'] === 'owner' ? 'admin' : 'user' ?>"><?= $a['role'] === 'owner' ? 'Pemilik' : 'Admin' ?></span></td>
          <td><?php if ($a['role'] === 'admin'): ?><form method="post" data-confirm="Hapus akun admin <?= e($a['nama']) ?>?"><?= csrf_field() ?><input type="hidden" name="aksi" value="hapus_admin"><input type="hidden" name="id" value="<?= $a['id'] ?>"><button class="btn sm danger" type="submit">Hapus admin</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></section>
      <form class="panel stack" method="post"><?= csrf_field() ?><input type="hidden" name="aksi" value="tambah_admin"><h2>Tambah akun admin</h2>
        <label for="nama">Nama<input type="text" id="nama" name="nama" maxlength="60" autocomplete="off"></label>
        <label for="email">Email staf kampus<input type="email" id="email" name="email" autocomplete="off" placeholder="nama@unsika.ac.id"></label>
        <label for="unit">Unit <span class="h">Opsional</span><select id="unit" name="unit"><option value="">Tim Verifikasi</option><?php foreach (UNITS as $u): ?><option><?= e($u) ?></option><?php endforeach; ?></select></label>
        <label for="password">Kata sandi awal <span class="h">Minimal 8 karakter. Berikan langsung ke admin tersebut.</span><input type="password" id="password" name="password" autocomplete="new-password"></label>
        <button class="btn primary" type="submit">Tambah admin</button></form>
    </div>
  <?php else:
    $w = ['aktif' => "l.status IN ('verifikasi','diproses')", 'semua' => "l.status NOT IN ('dibatalkan')", 'konfirmasi' => 'l.konfirmasi_baru = 1'][$tab] ?? "l.status = 'menunggu'";
    $rows = q("SELECT l.*, r.nama ruang, u.nama pelapor_nama, (SELECT COUNT(*) FROM dukungan d WHERE d.laporan_id = l.id) dukung
      FROM laporan l LEFT JOIN ruangan r ON r.id = l.ruangan_id LEFT JOIN users u ON u.id = l.user_id WHERE $w ORDER BY (l.prioritas = 'tinggi') DESC, l.dibuat " . ($tab === 'antrean' ? 'ASC' : 'DESC') . ' LIMIT 300')->fetchAll(); ?>
    <div class="list">
      <?php if (!$rows): ?><div class="empty"><b><?= $tab === 'antrean' ? 'Tidak ada laporan yang menunggu verifikasi.' : 'Tidak ada laporan di sini.' ?></b></div><?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <a class="row" href="admin.php?kelola=<?= urlencode($r['tiket']) ?>"><span class="stripe c-<?= e($r['status']) ?>"></span>
          <span style="min-width:0"><span class="meta"><span class="mono"><?= e($r['tiket']) ?></span><span><?= e($r['kategori']) ?></span><?php if ($r['prioritas'] === 'tinggi'): ?><span class="tag hi">Prioritas</span><?php endif; ?><?php if ($r['status'] === 'menunggu' && hari_sejak($r['dibuat']) > VERIF_DAYS): ?><span class="tag late">Menunggu &gt; <?= VERIF_DAYS ?> hari</span><?php endif; ?><?php if ($r['konfirmasi_baru']): ?><span class="tag late">Tanggapan pelapor</span><?php endif; ?><?php if ($r['tersembunyi']): ?><span class="tag hid">Disembunyikan</span><?php endif; ?><?php if ($r['contoh']): ?><span class="tag demo">Contoh</span><?php endif; ?></span>
            <h3><?= e($r['judul']) ?></h3><span class="meta"><?= e($r['ruang'] ?? '–') ?> · oleh <?= $r['anonim'] ? 'Anonim' : e($r['pelapor_nama'] ?? 'Pengguna') ?></span></span>
          <span class="right"><?= pill($r['status']) ?><span>▲ <?= (int)$r['dukung'] ?> · <?= e(tgl($r['dibuat'])) ?></span><span class="btn sm"><?= $r['status'] === 'menunggu' ? 'Verifikasi' : 'Kelola' ?> ›</span></span></a>
      <?php endforeach; ?>
    </div>
  <?php endif; endif; ?>
</main>
<?php page_foot();
