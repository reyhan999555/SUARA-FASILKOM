<?php
/* Halaman utama. Bagian (index.php?hal=…):
   beranda (default) · papan · progres · ringkasan · alur · notif
   index.php?tiket=FSK-… menampilkan detail satu laporan. */
require __DIR__ . '/app.php';
require_login();
$me = user();
$uid = (int)$me['id'];

/* =============================== Aksi (POST) =============================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  check_csrf();
  $aksi = $_POST['aksi'] ?? '';

  /* Program perbaikan (admin) */
  if ($aksi === 'proyek_simpan' || $aksi === 'proyek_hapus') {
    if (!is_admin()) { flash('Khusus admin.', 'err'); go('index.php?hal=progres'); }
    $id = (int)($_POST['id'] ?? 0);
    if ($aksi === 'proyek_hapus') { q('DELETE FROM proyek WHERE id = ?', [$id]); flash('Program dihapus.'); go('index.php?hal=progres'); }
    $nama = trim($_POST['nama'] ?? ''); $unit = $_POST['unit'] ?? ''; $prog = max(0, min(100, (int)($_POST['progres'] ?? 0)));
    $target = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['target'] ?? '') ? $_POST['target'] : null;
    $terkait = implode(', ', array_slice(array_filter(array_map(fn($t) => strtoupper(trim($t)), explode(',', $_POST['terkait'] ?? ''))), 0, 30));
    if (mb_strlen($nama) < 5 || !in_array($unit, UNITS, true)) { flash('Nama program minimal 5 karakter dan unit harus dipilih.', 'err'); go('index.php?hal=progres&proyek=' . $id . '#form'); }
    $p = [$nama, $unit, $prog, trim($_POST['anggaran'] ?? '') ?: null, $target, $terkait ?: null, trim($_POST['kabar'] ?? '') ?: null];
    if ($id) q('UPDATE proyek SET nama=?, unit=?, progres=?, anggaran=?, target=?, terkait=?, kabar=?, diperbarui=NOW() WHERE id=?', [...$p, $id]);
    else q('INSERT INTO proyek (nama, unit, progres, anggaran, target, terkait, kabar, diperbarui) VALUES (?,?,?,?,?,?,?,NOW())', $p);
    flash('Program tersimpan.'); go('index.php?hal=progres');
  }

  $l = q('SELECT * FROM laporan WHERE tiket = ?', [$_POST['tiket'] ?? ''])->fetch();
  if (!$l) { flash('Laporan tidak ditemukan.', 'err'); go('index.php'); }
  $kembali = safe_next($_POST['kembali'] ?? '') !== 'index.php' ? safe_next($_POST['kembali']) : 'index.php?tiket=' . urlencode($l['tiket']);

  if ($aksi === 'dukung') {
    if (laporan_milik($l, $uid) || !in_array($l['status'], ['verifikasi', 'diproses'], true)) { flash('Laporan ini tidak bisa didukung.', 'err'); go($kembali); }
    if (q('SELECT 1 FROM dukungan WHERE laporan_id = ? AND user_id = ?', [$l['id'], $uid])->fetchColumn()) {
      q('DELETE FROM dukungan WHERE laporan_id = ? AND user_id = ?', [$l['id'], $uid]); flash('Dukungan dibatalkan.');
    } else { q('INSERT INTO dukungan (laporan_id, user_id) VALUES (?, ?)', [$l['id'], $uid]); flash('Dukungan Anda tercatat.'); }
    go($kembali);
  }
  if ($aksi === 'konfirmasi' && laporan_milik($l, $uid) && $l['status'] === 'selesai' && !$l['konfirmasi']) {
    $beres = ($_POST['hasil'] ?? '') === 'beres';
    $rating = (int)($_POST['rating'] ?? 0); $catatan = trim($_POST['catatan'] ?? '') ?: null;
    if ($beres && ($rating < 1 || $rating > 5)) { flash('Pilih jumlah bintang dulu.', 'err'); go($kembali); }
    if ($beres) {
      q('UPDATE laporan SET konfirmasi = "beres", rating = ?, catatan_pelapor = ?, konfirmasi_baru = 1 WHERE id = ?', [$rating, $catatan, $l['id']]);
      tambah_riwayat($l['id'], 'Pelapor', "Pelapor mengonfirmasi masalah sudah beres ($rating/5).");
      flash('Terima kasih atas penilaiannya.');
    } else {
      q('UPDATE laporan SET status = "diproses", selesai_pada = NULL, catatan_pelapor = ?, konfirmasi_baru = 1 WHERE id = ?', [$catatan, $l['id']]);
      tambah_riwayat($l['id'], 'Pelapor', 'Dibuka kembali karena pelapor menyatakan masalah belum beres.');
      flash('Laporan dibuka kembali dan diteruskan lagi ke unit.');
    }
    go($kembali);
  }
  if ($aksi === 'batal' && laporan_milik($l, $uid) && $l['status'] === 'menunggu') {
    q('UPDATE laporan SET status = "dibatalkan" WHERE id = ?', [$l['id']]);
    tambah_riwayat($l['id'], 'Pelapor', 'Laporan dibatalkan oleh pelapor.');
    flash('Laporan dibatalkan.'); go($kembali);
  }
  go($kembali);
}

/* =============================== Data bantu =============================== */
$baseSel = 'SELECT l.*, r.nama AS ruang, u.nama AS pelapor_nama,
  (SELECT COUNT(*) FROM dukungan d WHERE d.laporan_id = l.id) AS dukung,
  (SELECT COUNT(*) FROM dukungan d WHERE d.laporan_id = l.id AND d.user_id = :me) AS saya_dukung
  FROM laporan l LEFT JOIN ruangan r ON r.id = l.ruangan_id LEFT JOIN users u ON u.id = l.user_id';
function laporan_cari(string $baseSel, array $where, array $p, string $order = 'l.dibuat DESC', int $limit = 200): array {
  $s = db()->prepare($baseSel . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY $order LIMIT $limit");
  $s->execute($p); return $s->fetchAll();
}
function link_laporan(array $r): string { return (is_admin() && $r['status'] === 'menunggu') ? 'admin.php?kelola=' . urlencode($r['tiket']) : 'index.php?tiket=' . urlencode($r['tiket']); }
function tags(array $r, int $uid): string {
  $t = '';
  if (laporan_milik($r, $uid) && !is_admin()) $t .= '<span class="tag mine">Milik saya</span>';
  if ($r['prioritas'] === 'tinggi' && in_array($r['status'], ['verifikasi', 'diproses'], true)) $t .= '<span class="tag hi">Prioritas</span>';
  if ($r['status'] === 'menunggu' && hari_sejak($r['dibuat']) > VERIF_DAYS) $t .= '<span class="tag late">Menunggu &gt; ' . VERIF_DAYS . ' hari</span>';
  if ($r['konfirmasi'] === 'beres') $t .= '<span class="tag ok">Dikonfirmasi</span>';
  if ($r['tersembunyi']) $t .= '<span class="tag hid">Disembunyikan</span>';
  if ($r['contoh']) $t .= '<span class="tag demo">Contoh</span>';
  return $t;
}
function dukung_btn(array $r, int $uid, string $kembali): string {
  if (laporan_milik($r, $uid) || is_admin() || !in_array($r['status'], ['verifikasi', 'diproses'], true)) return '';
  return '<form method="post">' . csrf_field() . '<input type="hidden" name="aksi" value="dukung"><input type="hidden" name="tiket" value="' . e($r['tiket']) . '"><input type="hidden" name="kembali" value="' . e($kembali) . '">'
    . '<button class="btn sm ' . ($r['saya_dukung'] ? 'on' : '') . '" type="submit">' . ($r['saya_dukung'] ? '✓ Didukung' : 'Saya juga mengalami') . '</button></form>';
}
function baris(array $r, int $uid, bool $vote = false, string $kembali = 'index.php'): string {
  ob_start(); ?>
  <div class="row"><span class="stripe c-<?= e($r['status']) ?>"></span>
    <span style="min-width:0"><span class="meta"><span class="mono"><?= e($r['tiket']) ?></span><span><?= e($r['kategori']) ?></span><?= tags($r, $uid) ?></span>
      <h3><a href="<?= e(link_laporan($r)) ?>"><?= e($r['judul']) ?></a></h3><span class="meta"><?= e($r['ruang'] ?? '–') ?> · <?= e($r['unit'] ?: 'Belum ada unit') ?></span></span>
    <span class="right"><?= pill($r['status']) ?><span>▲ <?= (int)$r['dukung'] ?> dukungan · <?= e(tgl($r['dibuat'])) ?></span><?= $vote ? dukung_btn($r, $uid, $kembali) : '' ?></span>
  </div>
  <?php return ob_get_clean();
}
function statistik(): array {
  $s = q("SELECT COUNT(*) total, SUM(status='selesai') selesai, SUM(status NOT IN ('ditolak')) valid, SUM(status IN ('verifikasi','diproses')) aktif,
    SUM(status='ditolak') ditolak, AVG(CASE WHEN status='selesai' AND selesai_pada IS NOT NULL THEN TIMESTAMPDIFF(HOUR, dibuat, selesai_pada) END) jam,
    AVG(rating) rating, SUM(rating IS NOT NULL) n_rating
    FROM laporan WHERE status IN ('verifikasi','diproses','selesai','ditolak') AND tersembunyi = 0")->fetch();
  $s['dukung'] = (int)q('SELECT COUNT(*) FROM dukungan')->fetchColumn();
  return $s;
}
$hal = $_GET['hal'] ?? 'beranda';
$bln = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

/* =============================== Detail laporan =============================== */
if (isset($_GET['tiket'])) {
  $l = laporan_cari($baseSel, ['l.tiket = :t'], [':me' => $uid, ':t' => $_GET['tiket']], 'l.id', 1)[0] ?? null;
  $milik = $l && laporan_milik($l, $uid);
  $boleh = $l && (is_admin() || $milik || (in_array($l['status'], ['verifikasi', 'diproses', 'selesai', 'ditolak', 'digabung'], true) && !$l['tersembunyi']));
  page_head($boleh ? $l['judul'] : 'Laporan tidak ditemukan', 'papan');
  echo '<main class="wrap">';
  if (!$boleh) {
    echo '<div class="panel empty"><b>Laporan tidak ditemukan.</b><span>Periksa nomor tiketnya, atau tunggu sampai laporan diverifikasi.</span><a class="btn" href="index.php?hal=papan">Kembali ke papan</a></div></main>';
    page_foot(); exit;
  }
  $riw = q('SELECT * FROM riwayat WHERE laporan_id = ? ORDER BY dibuat, id', [$l['id']])->fetchAll();
  $idx = array_search($l['status'], array_keys(TRACK), true);
  $terbuka = in_array($l['status'], ['verifikasi', 'diproses'], true);
  $tujuan = $l['digabung_ke'] ? q('SELECT tiket, judul FROM laporan WHERE id = ?', [$l['digabung_ke']])->fetch() : null;
  ?>
  <div class="page-head">
    <div><a href="index.php?hal=papan" class="h">‹ Kembali ke papan</a><h1 style="margin-top:6px"><?= e($l['judul']) ?></h1></div>
    <div class="btn-row"><?= pill($l['status']) ?><?= tags($l, $uid) ?><?php if ($l['rating']): ?><span class="stars-txt"><?= str_repeat('★', (int)$l['rating']) ?></span><?php endif; ?></div>
  </div>
  <?php if ($tujuan): ?><div class="box"><span>Laporan ini digabung ke <a class="btn sm" href="index.php?tiket=<?= urlencode($tujuan['tiket']) ?>"><?= e($tujuan['tiket']) ?> — <?= e($tujuan['judul']) ?></a> karena membahas masalah yang sama. Pantau perkembangannya di sana.</span></div><?php endif; ?>
  <div class="grid2">
    <section class="panel" style="display:grid;gap:18px">
      <?php if ($idx !== false): ?><div class="track" aria-label="Tahap penanganan"><?php $i = 0; foreach (TRACK as $k => $lbl): ?><div class="<?= $i <= $idx ? 'done' : '' ?>"><i></i><?= e($lbl) ?></div><?php $i++; endforeach; ?></div><?php endif; ?>
      <p style="white-space:pre-line"><?= e($l['isi']) ?></p>
      <?php if ($f = foto_url($l['foto'])): ?><a class="photo" href="<?= e($f) ?>"><img src="<?= e($f) ?>" alt="Foto pendukung laporan <?= e($l['tiket']) ?>"></a><?php endif; ?>
      <div><h2 style="font-size:17px;margin-bottom:12px">Riwayat penanganan</h2>
        <ol class="tl"><?php foreach ($riw as $r): ?><li><span><span class="w"><?= e(tgl($r['dibuat'], true)) ?></span><b><?= e($r['oleh']) ?></b> — <?= e($r['teks']) ?><?php if ($rf = foto_url($r['foto'])): ?><br><a class="photo" style="margin-top:8px;max-width:300px" href="<?= e($rf) ?>"><img src="<?= e($rf) ?>" alt="Foto tindak lanjut"></a><?php endif; ?></span></li><?php endforeach; ?></ol></div>
    </section>
    <aside style="display:grid;gap:16px">
      <section class="panel" style="display:grid;gap:14px"><dl class="kv">
        <dt>Nomor tiket</dt><dd class="mono"><?= e($l['tiket']) ?></dd>
        <dt>Kategori</dt><dd><?= e($l['kategori']) ?></dd>
        <dt>Lokasi</dt><dd><?= e($l['ruang'] ?? '–') ?></dd>
        <dt>Unit</dt><dd><?= e($l['unit'] ?: 'Belum ditentukan') ?></dd>
        <dt>Masuk</dt><dd><?= e(tgl($l['dibuat'])) ?></dd>
        <?php if ($l['selesai_pada']): ?><dt>Selesai</dt><dd><?= e(tgl($l['selesai_pada'])) ?></dd><?php endif; ?>
        <dt>Pelapor</dt><dd><?= $l['anonim'] ? 'Anonim — identitas tidak ditautkan' : e($l['pelapor_nama'] ?? 'Pengguna') ?></dd>
        <dt>Dukungan</dt><dd><?= (int)$l['dukung'] ?> orang<?= $l['dup_count'] ? ' · ' . (int)$l['dup_count'] . ' laporan serupa digabung' : '' ?></dd>
      </dl><button class="btn sm" type="button" data-copy="<?= e($l['tiket']) ?>">Salin nomor tiket</button></section>
      <?php if ($terbuka && !$milik && !is_admin()): ?>
        <form method="post" class="panel" style="display:grid;gap:10px"><?= csrf_field() ?><input type="hidden" name="tiket" value="<?= e($l['tiket']) ?>"><input type="hidden" name="aksi" value="dukung">
          <b>Mengalami masalah yang sama?</b><span class="h">Dukungan membantu admin menentukan prioritas. Satu akun satu dukungan.</span>
          <button class="btn <?= $l['saya_dukung'] ? 'on' : 'primary' ?>" type="submit"><?= $l['saya_dukung'] ? '✓ Anda mendukung (batalkan)' : 'Saya juga mengalami ini' ?></button></form>
      <?php endif; ?>
      <?php if ($milik && $l['status'] === 'selesai' && !$l['konfirmasi']): ?>
        <form method="post" class="box ok"><?= csrf_field() ?><input type="hidden" name="tiket" value="<?= e($l['tiket']) ?>"><input type="hidden" name="aksi" value="konfirmasi">
          <h3>Apakah masalahnya sudah beres?</h3>
          <div><div class="h" style="margin-bottom:4px">Beri nilai untuk penanganannya</div><div class="stars" role="radiogroup" aria-label="Penilaian"><?php for ($n = 5; $n >= 1; $n--): ?><input type="radio" name="rating" id="st<?= $n ?>" value="<?= $n ?>"><label for="st<?= $n ?>" title="<?= $n ?> bintang">★</label><?php endfor; ?></div></div>
          <label for="catatan">Catatan untuk admin <span class="h">Opsional. Hanya dibaca admin.</span><textarea id="catatan" name="catatan" style="min-height:70px"></textarea></label>
          <div class="btn-row"><button class="btn primary sm" name="hasil" value="beres" type="submit">Sudah beres</button><button class="btn sm" name="hasil" value="belum" type="submit">Belum beres, buka kembali</button></div></form>
      <?php elseif ($milik && $l['konfirmasi']): ?>
        <div class="box ok"><b>Terima kasih atas konfirmasinya.</b><span class="h">Penilaian Anda: <span class="stars-txt"><?= str_repeat('★', (int)$l['rating']) ?></span></span></div>
      <?php elseif ($milik && $l['status'] === 'menunggu'): ?>
        <form method="post" class="box" data-confirm="Batalkan laporan ini?"><?= csrf_field() ?><input type="hidden" name="tiket" value="<?= e($l['tiket']) ?>"><input type="hidden" name="aksi" value="batal">
          <h3>Menunggu verifikasi</h3><span class="h">Tim Verifikasi memeriksa laporan maksimal <?= VERIF_DAYS ?> hari kerja. Setelah itu laporan tampil di papan publik dan Anda mendapat notifikasi.</span>
          <div><button class="btn sm danger" type="submit">Batalkan laporan</button></div></form>
      <?php elseif ($milik && $l['status'] === 'ditolak'): ?>
        <div class="box warn"><b>Laporan ditolak</b><span class="h">Alasannya tercatat di riwayat. Anda bisa mengirim laporan baru dengan informasi yang lebih lengkap.</span><button class="btn sm" type="button" data-open-complaint>Kirim laporan baru</button></div>
      <?php endif; ?>
      <?php if (is_admin()): ?><a class="btn primary" href="admin.php?kelola=<?= urlencode($l['tiket']) ?>">Kelola laporan ini</a><?php endif; ?>
    </aside>
  </div>
  </main>
  <?php page_foot(); exit;
}

/* =============================== Notifikasi =============================== */
if ($hal === 'notif') {
  $ns = notifikasi($uid);
  $since = $me['notif_dibaca'] ?: '1970-01-01';
  q('UPDATE users SET notif_dibaca = NOW() WHERE id = ?', [$uid]);
  page_head('Notifikasi', '');
  ?>
  <main class="wrap">
    <div class="page-head"><div><span class="eyebrow">Notifikasi</span><h1>Kabar laporan Anda</h1><p>Setiap tanggapan admin atau unit pada laporan Anda muncul di sini.</p></div></div>
    <?php if (!$ns): ?><div class="panel empty"><b>Belum ada notifikasi.</b><span>Anda akan diberi tahu di sini setiap kali laporan Anda ditanggapi.</span></div>
    <?php else: ?><div class="notif-list"><?php foreach ($ns as $n): ?><a class="<?= $n['dibuat'] > $since ? 'unread' : '' ?>" href="index.php?tiket=<?= urlencode($n['tiket']) ?>"><small><?= e(tgl($n['dibuat'], true)) ?> · <?= e($n['tiket']) ?><?= $n['dibuat'] > $since ? ' · baru' : '' ?></small><b><?= e($n['judul']) ?></b><span><?= e($n['oleh']) ?>: <?= e($n['teks']) ?></span></a><?php endforeach; ?></div><?php endif; ?>
  </main>
  <?php page_foot(); exit;
}

/* =============================== Papan laporan =============================== */
if ($hal === 'papan') {
  $q = trim($_GET['q'] ?? ''); $kat = $_GET['kat'] ?? ''; $urut = ($_GET['urut'] ?? '') === 'dukungan' ? 'dukungan' : 'baru';
  $lingkup = ($_GET['lingkup'] ?? '') === 'saya' ? 'saya' : 'semua'; $tampil = ($_GET['tampil'] ?? '') === 'papan' ? 'papan' : 'daftar';
  $st = $_GET['st'] ?? ''; $antre = $_GET['antrean'] ?? '';
  $unit = is_admin() ? ($_GET['unit'] ?? ($me['unit'] ?: 'semua')) : 'semua';
  $w = []; $p = [':me' => $uid];
  if (is_admin()) $w[] = "l.status IN ('menunggu','verifikasi','diproses','selesai','ditolak')";
  else { $w[] = "((l.status IN ('verifikasi','diproses','selesai','ditolak') AND l.tersembunyi = 0) OR ((l.user_id = :me2 OR l.owner_hash = :hash2) AND l.status IN ('menunggu','ditolak')))"; $p[':me2'] = $uid; $p[':hash2'] = anon_owner_hash($uid); }
  if ($q !== '') { $w[] = '(l.judul LIKE :q1 OR l.tiket LIKE :q2 OR r.nama LIKE :q3)'; $p[':q1'] = $p[':q2'] = $p[':q3'] = "%$q%"; }
  if (isset(KATEGORI[$kat])) { $w[] = 'l.kategori = :kat'; $p[':kat'] = $kat; }
  if (is_admin() && in_array($unit, UNITS, true)) { $w[] = "(l.unit = :unit OR (l.status = 'menunggu' AND l.kategori IN ('" . implode("','", array_keys(array_filter(KATEGORI, fn($u) => $u === $unit)) ?: ['-']) . "')))"; $p[':unit'] = $unit; }
  if ($lingkup === 'saya') { $w[] = '((l.user_id = :me3 OR l.owner_hash = :hash3) OR l.id IN (SELECT digabung_ke FROM laporan WHERE (user_id = :me4 OR owner_hash = :hash4) AND digabung_ke IS NOT NULL))'; $p[':me3'] = $p[':me4'] = $uid; $p[':hash3'] = $p[':hash4'] = anon_owner_hash($uid); }
  $Q = ['baru' => ['Perlu diverifikasi', "l.status = 'menunggu'"], 'lama' => ['Menunggu verifikasi > ' . VERIF_DAYS . ' hari', "l.status = 'menunggu' AND l.dibuat < NOW() - INTERVAL " . VERIF_DAYS . " DAY"],
        'konfirmasi' => ['Tanggapan pelapor', 'l.konfirmasi_baru = 1'], 'tinggi' => ['Prioritas tinggi aktif', "l.prioritas = 'tinggi' AND l.status IN ('verifikasi','diproses')"],
        'populer' => ['Laporan populer (≥ 10 dukungan)', "l.status IN ('verifikasi','diproses') AND (SELECT COUNT(*) FROM dukungan d2 WHERE d2.laporan_id = l.id) >= 10"]];
  if (is_admin() && isset($Q[$antre])) $w[] = $Q[$antre][1];
  $rows = laporan_cari($baseSel, $w, $p, $urut === 'dukungan' ? 'dukung DESC, l.dibuat DESC' : 'l.dibuat DESC', 300);
  $qc = is_admin() ? array_map(fn($x) => (int)q("SELECT COUNT(*) FROM laporan l WHERE {$x[1]}")->fetchColumn(), $Q) : [];
  $cols = ['menunggu', 'verifikasi', 'diproses', 'selesai', 'ditolak'];
  $link = function (array $add) { $a = array_merge(array_intersect_key($_GET, array_flip(['q', 'kat', 'urut', 'lingkup', 'tampil', 'st', 'antrean', 'unit'])), $add, ['hal' => 'papan']);
    return 'index.php?' . http_build_query(array_filter($a, fn($v) => $v !== '' && $v !== null)); };
  page_head('Papan laporan', 'papan');
  ?>
  <main class="wrap">
    <div class="page-head"><div><span class="eyebrow"><?= is_admin() ? 'Admin' : 'Papan' ?></span><h1><?= is_admin() ? 'Kelola laporan' : 'Papan laporan' ?></h1><p><?= is_admin() ? 'Verifikasi laporan baru, teruskan ke unit, dan catat setiap tindak lanjut agar terlihat publik.' : 'Pantau setiap keluhan dari masuk sampai selesai. Buka laporan untuk melihat riwayatnya.' ?></p></div>
      <div class="btn-row"><?php if (is_admin()): ?><a class="btn" href="admin.php?tab=pengaturan">Pengaturan</a><?php else: ?><button class="btn primary" type="button" data-open-complaint>+ Ajukan keluhan</button><?php endif; ?></div></div>
    <?php if (is_admin()): ?><div class="queue"><?php foreach ($Q as $k => [$lbl]): $alert = $qc[$k] > 0 && in_array($k, ['baru', 'lama', 'konfirmasi'], true); ?>
      <a class="qbox <?= $alert ? 'alert' : '' ?> <?= $antre === $k ? 'on' : '' ?>" href="<?= e($link(['antrean' => $antre === $k ? '' : $k])) ?>"><span class="k"><?= e($lbl) ?></span><span class="v"><?= $qc[$k] ?></span></a><?php endforeach; ?></div><?php endif; ?>
    <form class="toolbar" method="get"><input type="hidden" name="hal" value="papan"><?php foreach (['urut', 'lingkup', 'tampil', 'st', 'antrean'] as $h): if (!empty($_GET[$h])): ?><input type="hidden" name="<?= $h ?>" value="<?= e($_GET[$h]) ?>"><?php endif; endforeach; ?>
      <div class="search"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg><input type="search" name="q" value="<?= e($q) ?>" placeholder="Cari judul, ruangan, atau nomor tiket" aria-label="Cari laporan"></div>
      <select name="kat" aria-label="Kategori"><option value="">Semua kategori</option><?php foreach (KATEGORI as $k => $u): ?><option <?= $kat === $k ? 'selected' : '' ?>><?= e($k) ?></option><?php endforeach; ?></select>
      <?php if (is_admin()): ?><select name="unit" aria-label="Unit"><option value="semua">Semua unit</option><?php foreach (UNITS as $u): ?><option <?= $unit === $u ? 'selected' : '' ?>><?= e($u) ?></option><?php endforeach; ?></select><?php endif; ?>
      <button class="btn" type="submit">Terapkan</button>
      <div class="seg" role="group" aria-label="Urutkan"><a href="<?= e($link(['urut' => ''])) ?>" class="<?= $urut === 'baru' ? 'on' : '' ?>">Terbaru</a><a href="<?= e($link(['urut' => 'dukungan'])) ?>" class="<?= $urut === 'dukungan' ? 'on' : '' ?>">Terpopuler</a></div>
      <?php if (!is_admin()): ?><div class="seg" role="group" aria-label="Lingkup"><a href="<?= e($link(['lingkup' => ''])) ?>" class="<?= $lingkup === 'semua' ? 'on' : '' ?>">Semua</a><a href="<?= e($link(['lingkup' => 'saya'])) ?>" class="<?= $lingkup === 'saya' ? 'on' : '' ?>">Laporan saya</a></div><?php endif; ?>
      <div class="seg" role="group" aria-label="Tampilan"><a href="<?= e($link(['tampil' => ''])) ?>" class="<?= $tampil === 'daftar' ? 'on' : '' ?>">Daftar</a><a href="<?= e($link(['tampil' => 'papan'])) ?>" class="<?= $tampil === 'papan' ? 'on' : '' ?>">Papan</a></div>
    </form>
    <?php if ((is_admin() && $antre && isset($Q[$antre])) || (is_admin() && $unit !== 'semua')): ?><div class="btn-row h"><?= $antre && isset($Q[$antre]) ? 'Filter antrean: <b>' . e($Q[$antre][0]) . '</b>' : '' ?><?= $unit !== 'semua' ? ' · Unit: <b>' . e($unit) . '</b>' : '' ?><a class="btn sm" href="index.php?hal=papan&amp;unit=semua">Hapus filter</a></div><?php endif; ?>
    <?php if ($tampil === 'papan'): ?>
      <div class="board"><?php foreach ($cols as $c): $items = array_filter($rows, fn($r) => $r['status'] === $c); ?>
        <div class="col"><div class="col-h"><span class="sd c-<?= $c ?>"></span><?= e(STATUS[$c]) ?><span class="n"><?= count($items) ?></span></div>
          <?php if (!$items): ?><div class="empty-col"><?= $c === 'menunggu' ? (is_admin() ? 'Tidak ada laporan baru' : 'Laporan Anda yang belum diverifikasi muncul di sini') : 'Tidak ada laporan' ?></div><?php endif; ?>
          <?php foreach ($items as $r): ?><a class="card" href="<?= e(link_laporan($r)) ?>"><span class="meta"><span class="mono"><?= e($r['tiket']) ?></span><?= tags($r, $uid) ?></span><h3><?= e($r['judul']) ?></h3><span class="meta"><?= e($r['ruang'] ?? '–') ?></span><span class="meta"><span class="tag"><?= e($r['kategori']) ?></span><span>▲ <?= (int)$r['dukung'] ?> · <?= in_array($c, ['selesai', 'ditolak'], true) ? e(tgl($r['dibuat'])) : hari_sejak($r['dibuat']) . ' hr' ?></span></span><?php if (is_admin() && in_array($c, ['menunggu', 'verifikasi', 'diproses'], true)): ?><b style="font-size:12px"><?= $c === 'menunggu' ? 'Verifikasi sekarang →' : 'Perbarui tindak lanjut →' ?></b><?php endif; ?></a><?php endforeach; ?>
        </div><?php endforeach; ?></div>
    <?php else: $st = in_array($st, $cols, true) ? $st : ''; $vis = $st ? array_filter($rows, fn($r) => $r['status'] === $st) : $rows; ?>
      <div class="chips"><a class="chip <?= !$st ? 'on' : '' ?>" href="<?= e($link(['st' => ''])) ?>">Semua<span class="n"><?= count($rows) ?></span></a><?php foreach ($cols as $c): ?><a class="chip <?= $st === $c ? 'on' : '' ?>" href="<?= e($link(['st' => $c])) ?>"><?= e(STATUS[$c]) ?><span class="n"><?= count(array_filter($rows, fn($r) => $r['status'] === $c)) ?></span></a><?php endforeach; ?></div>
      <div class="list"><?php if (!$vis): ?><div class="empty"><b>Tidak ada laporan dengan filter ini.</b><?php if (!is_admin()): ?><button class="btn primary sm" type="button" data-open-complaint>Ajukan keluhan</button><?php endif; ?></div><?php endif; ?>
        <?php foreach ($vis as $r) echo baris($r, $uid, true, $link([])); ?></div>
    <?php endif; ?>
  </main>
  <?php page_foot(); exit;
}

/* =============================== Progres & kinerja unit =============================== */
if ($hal === 'progres') {
  $ps = q('SELECT * FROM proyek ORDER BY (target IS NULL), target, id')->fetchAll();
  $edit = is_admin() && isset($_GET['proyek']) ? (q('SELECT * FROM proyek WHERE id = ?', [(int)$_GET['proyek']])->fetch() ?: ['id' => 0, 'nama' => '', 'unit' => UNITS[0], 'progres' => 0, 'anggaran' => '', 'target' => '', 'terkait' => '', 'kabar' => '']) : null;
  $unitRows = q("SELECT unit, COUNT(*) n, SUM(status='selesai') selesai, SUM(status='diproses') diproses, SUM(status='verifikasi') verifikasi, SUM(status='ditolak') ditolak,
    AVG(CASE WHEN status='selesai' AND selesai_pada IS NOT NULL THEN TIMESTAMPDIFF(HOUR, dibuat, selesai_pada) END) jam
    FROM laporan WHERE unit IS NOT NULL AND unit <> '' AND status IN ('verifikasi','diproses','selesai','ditolak') AND tersembunyi = 0 GROUP BY unit ORDER BY unit")->fetchAll();
  page_head('Progres & kinerja unit', 'progres');
  ?>
  <main class="wrap">
    <div class="page-head"><div><span class="eyebrow">Progres</span><h1>Progres &amp; kinerja unit</h1><p>Program perbaikan yang lahir dari keluhan, dan seberapa cepat tiap unit menindaklanjuti laporan.</p></div>
      <?php if (is_admin()): ?><a class="btn primary" href="index.php?hal=progres&amp;proyek=0#form">+ Tambah program</a><?php endif; ?></div>
    <div class="grid2">
      <section class="panel"><h2>Program perbaikan</h2><p class="psub">Diperbarui oleh unit penanggung jawab.</p>
        <?php if (!$ps): ?><div class="empty"><b>Belum ada program perbaikan.</b><span><?= is_admin() ? 'Tambahkan program saat beberapa keluhan perlu ditangani lewat satu pekerjaan besar.' : 'Program akan tampil di sini setelah unit menambahkannya.' ?></span></div>
        <?php else: ?><div class="tbl-wrap"><table><thead><tr><th>Program</th><th>Progres</th><th>Anggaran</th><th>Target</th><?php if (is_admin()): ?><th></th><?php endif; ?></tr></thead><tbody>
          <?php foreach ($ps as $pr): $late = $pr['target'] && $pr['progres'] < 100 && $pr['target'] < date('Y-m-d'); $nT = $pr['terkait'] ? count(explode(',', $pr['terkait'])) : 0; ?>
          <tr><td><b><?= e($pr['nama']) ?></b><small><?= e($pr['unit']) ?><?= $nT ? " · $nT laporan terkait" : '' ?><?= $pr['contoh'] ? ' · contoh' : '' ?></small><?php if ($pr['kabar']): ?><small><?= e($pr['kabar']) ?></small><?php endif; ?><?php if ($pr['terkait']): ?><small><?php foreach (explode(',', $pr['terkait']) as $t): $t = trim($t); ?><a href="index.php?tiket=<?= urlencode($t) ?>"><?= e($t) ?></a> <?php endforeach; ?></small><?php endif; ?></td>
            <td><div class="prog"><div class="track-bar"><span class="<?= $pr['progres'] >= 100 ? 'ok' : ($late ? 'late' : '') ?>" style="width:<?= (int)$pr['progres'] ?>%"></span></div><span class="mono"><?= (int)$pr['progres'] ?>%</span></div></td>
            <td><?= e($pr['anggaran'] ?: '–') ?></td>
            <td><?= $late ? '<span class="late-t">Terlambat</span><br>' : '' ?><small><?= e($pr['target'] ? tgl($pr['target']) : '–') ?></small></td>
            <?php if (is_admin()): ?><td><a class="btn sm" href="index.php?hal=progres&amp;proyek=<?= $pr['id'] ?>#form">Ubah</a></td><?php endif; ?></tr>
          <?php endforeach; ?></tbody></table></div><?php endif; ?>
      </section>
      <div style="display:grid;gap:20px">
        <section class="panel"><h2>Kinerja unit</h2><p class="psub">Sebaran status laporan terverifikasi per unit.</p>
          <?php if (!$unitRows): ?><div class="empty">Belum ada laporan yang diteruskan ke unit.</div><?php endif; ?>
          <div class="unit-list"><?php foreach ($unitRows as $u): ?><div class="unit"><div class="r"><?= e($u['unit']) ?><span class="h"><?= (int)$u['selesai'] ?>/<?= (int)$u['n'] ?> selesai</span></div>
            <div class="stack"><?php foreach (['selesai', 'diproses', 'verifikasi', 'ditolak'] as $s): if ($u[$s]): ?><i class="c-<?= $s ?>" style="width:<?= $u[$s] / $u['n'] * 100 ?>%" title="<?= e(STATUS[$s]) ?>: <?= (int)$u[$s] ?>"></i><?php endif; endforeach; ?></div>
            <small class="h"><?= $u['jam'] !== null ? 'Rata-rata selesai ' . str_replace('.', ',', round($u['jam'] / 24, 1)) . ' hari' : 'Belum ada yang selesai' ?></small></div><?php endforeach; ?></div>
          <div class="legend" style="margin-top:14px"><span><i class="c-selesai"></i>Selesai</span><span><i class="c-diproses"></i>Diproses</span><span><i class="c-verifikasi"></i>Diverifikasi</span><span><i class="c-ditolak"></i>Ditolak</span></div>
        </section>
        <?php if ($edit): ?>
        <section class="panel" id="form"><h2><?= $edit['id'] ? 'Ubah program' : 'Tambah program' ?></h2>
          <form class="stack" method="post" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="aksi" value="proyek_simpan"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
            <label for="nama">Nama program<input type="text" id="nama" name="nama" maxlength="100" value="<?= e($edit['nama']) ?>" placeholder="Contoh: Penggantian 6 unit AC laboratorium"></label>
            <div class="two"><label for="unit">Unit<select id="unit" name="unit"><?php foreach (UNITS as $u): ?><option <?= $edit['unit'] === $u ? 'selected' : '' ?>><?= e($u) ?></option><?php endforeach; ?></select></label>
              <label for="target">Target selesai<input type="date" id="target" name="target" value="<?= e($edit['target'] ?? '') ?>"></label></div>
            <label for="progres">Progres <span class="h" id="pv"><?= (int)$edit['progres'] ?>%</span><input type="range" id="progres" name="progres" min="0" max="100" step="5" value="<?= (int)$edit['progres'] ?>" oninput="document.getElementById('pv').textContent=this.value+'%'"></label>
            <label for="anggaran">Anggaran<input type="text" id="anggaran" name="anggaran" maxlength="60" value="<?= e($edit['anggaran'] ?? '') ?>" placeholder="Contoh: Rp 72 jt"></label>
            <label for="terkait">Laporan terkait <span class="h">Nomor tiket, pisahkan dengan koma.</span><input type="text" id="terkait" name="terkait" value="<?= e($edit['terkait'] ?? '') ?>"></label>
            <label for="kabar">Kabar terbaru <span class="h">Tampil di bawah nama program.</span><input type="text" id="kabar" name="kabar" maxlength="160" value="<?= e($edit['kabar'] ?? '') ?>"></label>
            <div class="btn-row"><button class="btn primary" type="submit">Simpan program</button></div></form>
          <?php if ($edit['id']): ?><form method="post" data-confirm="Hapus program ini?" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="aksi" value="proyek_hapus"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><button class="btn danger sm" type="submit">Hapus program</button></form><?php endif; ?>
        </section>
        <?php endif; ?>
      </div>
    </div>
  </main>
  <?php page_foot(); exit;
}

/* =============================== Ringkasan publik =============================== */
if ($hal === 'ringkasan') {
  $s = statistik();
  $perKat = q("SELECT kategori, COUNT(*) n, SUM(status='selesai') d FROM laporan WHERE status IN ('verifikasi','diproses','selesai','ditolak') AND tersembunyi = 0 GROUP BY kategori ORDER BY n DESC")->fetchAll();
  $max = max(1, ...array_map(fn($x) => (int)$x['n'], $perKat ?: [['n' => 1]]));
  $fix = q("SELECT l.*, (SELECT rw.foto FROM riwayat rw WHERE rw.laporan_id = l.id AND rw.foto IS NOT NULL ORDER BY rw.dibuat DESC LIMIT 1) foto_sesudah
    FROM laporan l WHERE l.status = 'selesai' AND l.tersembunyi = 0 ORDER BY COALESCE(l.selesai_pada, l.dibuat) DESC LIMIT 6")->fetchAll();
  page_head('Ringkasan publik', 'ringkasan');
  ?>
  <main class="wrap">
    <div class="page-head"><div><span class="eyebrow">Ringkasan</span><h1>Ringkasan publik</h1><p>Rekap terbuka untuk semua sivitas. Hanya laporan yang sudah diverifikasi yang dihitung.</p></div></div>
    <?php if (!(int)$s['total']): ?><div class="panel empty"><b>Belum ada data untuk direkap.</b><span>Ringkasan terisi otomatis setelah laporan pertama diverifikasi.</span></div><?php else: ?>
    <div class="feat" style="border-top:1px solid var(--ink)">
      <div><span class="k">Laporan terverifikasi</span><span class="v"><?= (int)$s['total'] ?></span><span class="d"><?= (int)$s['ditolak'] ?> ditolak dengan alasan terbuka</span></div>
      <div><span class="k">Tingkat penyelesaian</span><span class="v"><?= $s['valid'] ? round($s['selesai'] / $s['valid'] * 100) : 0 ?>%</span><span class="d"><?= (int)$s['selesai'] ?> dari <?= (int)$s['valid'] ?> laporan valid</span></div>
      <div><span class="k">Rata-rata waktu selesai</span><span class="v"><?= $s['jam'] !== null ? str_replace('.', ',', round($s['jam'] / 24, 1)) . ' hari' : '–' ?></span><span class="d"><?= (int)$s['selesai'] ? 'Dari ' . (int)$s['selesai'] . ' laporan yang sudah selesai' : 'Belum ada yang selesai' ?></span></div>
      <div><span class="k">Kepuasan pelapor</span><span class="v"><?= $s['rating'] !== null ? str_replace('.', ',', round($s['rating'], 1)) . ' <span class="stars-txt" style="font-size:20px">★</span>' : '–' ?></span><span class="d"><?= (int)$s['n_rating'] ?> penilaian dari pelapor</span></div>
    </div>
    <section class="panel"><h2>Laporan per kategori</h2><p class="psub">Batang abu-abu: masuk. Batang berwarna: selesai.</p>
      <div class="bars"><?php foreach ($perKat as $k): ?><div class="b"><span><?= e($k['kategori']) ?></span><span class="tr2"><i class="in" style="width:<?= $k['n'] / $max * 100 ?>%"></i><i class="done" style="width:<?= $k['d'] / $max * 100 ?>%"></i></span><span class="mono"><?= (int)$k['d'] ?>/<?= (int)$k['n'] ?></span></div><?php endforeach; ?></div></section>
    <section class="panel"><h2>Perbaikan terbaru yang selesai</h2><p class="psub">Bukti tindak lanjut yang bisa dilihat semua orang.</p>
      <?php if (!$fix): ?><div class="empty">Belum ada laporan yang selesai.</div><?php endif; ?>
      <div class="fixes"><?php foreach ($fix as $r): $img = foto_url($r['foto_sesudah']) ?: foto_url($r['foto']); ?><a class="fix" href="index.php?tiket=<?= urlencode($r['tiket']) ?>"><?php if ($img): ?><img src="<?= e($img) ?>" alt="Foto <?= e($r['judul']) ?>" loading="lazy"><?php endif; ?><div><b><?= e($r['judul']) ?></b><small class="h"><?= e($r['unit'] ?? '') ?> · selesai <?= e(tgl($r['selesai_pada'] ?: $r['dibuat'])) ?></small><?php if ($r['rating']): ?><small class="stars-txt"><?= str_repeat('★', (int)$r['rating']) ?></small><?php endif; ?></div></a><?php endforeach; ?></div></section>
    <?php endif; ?>
  </main>
  <?php page_foot(); exit;
}

/* =============================== Alur & bantuan =============================== */
if ($hal === 'alur') {
  $lacakErr = '';
  if (isset($_GET['lacak'])) {
    $t = strtoupper(trim($_GET['lacak']));
    $f = q('SELECT user_id, owner_hash, anonim, status, tersembunyi FROM laporan WHERE tiket = ?', [$t])->fetch();
    if ($f && (is_admin() || laporan_milik($f, $uid) || ($f['status'] !== 'menunggu' && $f['status'] !== 'dibatalkan' && !$f['tersembunyi']))) go('index.php?tiket=' . urlencode($t));
    $lacakErr = 'Tiket tidak ditemukan. Periksa penulisan nomornya, atau tunggu hingga laporan diverifikasi.';
  }
  $kontak = kontak_darurat();
  page_head('Alur & bantuan', 'alur');
  ?>
  <main class="wrap">
    <div class="page-head"><div><span class="eyebrow">Bantuan</span><h1>Alur pengaduan &amp; bantuan</h1><p>Apa yang terjadi setelah Anda mengirim laporan, siapa yang menangani, dan ke mana menghubungi untuk hal darurat.</p></div></div>
    <ol class="steps" id="langkah">
      <li><b>Kirim laporan</b><span>Isi keluhan, pilih kategori dan ruangan, tambahkan foto bila ada. Bisa anonim sepenuhnya atau memilih identitas publik.</span></li>
      <li><b>Verifikasi</b><span>Tim Verifikasi memeriksa maksimal <?= VERIF_DAYS ?> hari kerja, menyaring data pribadi atau kata kasar, lalu menggabungkan laporan ganda.</span></li>
      <li><b>Diteruskan &amp; diproses</b><span>Unit penanggung jawab menindaklanjuti laporan. Setiap tindak lanjut tercatat di riwayat publik.</span></li>
      <li><b>Selesai &amp; konfirmasi</b><span>Pelapor menilai hasilnya. Jika belum beres, laporan dibuka kembali.</span></li>
    </ol>
    <div class="grid2">
      <div style="display:grid;gap:20px">
        <section class="panel" id="unit"><h2>Unit penanggung jawab</h2><p class="psub">Ke unit mana laporan diteruskan setelah diverifikasi, sesuai kategorinya.</p>
          <div class="tbl-wrap"><table><thead><tr><th>Kategori</th><th>Unit awal</th></tr></thead><tbody><?php foreach (KATEGORI as $k => $u): ?><tr><td><?= e($k) ?></td><td><?= e($u) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
        <section class="panel" id="faq"><h2>Pertanyaan umum</h2>
          <details><summary>Siapa yang bisa melihat identitas saya?</summary><p>Jika Anda memilih anonim, identitas akun tidak ditautkan ke laporan. Admin maupun publik tidak dapat melihat akun pengirim dari data laporan.</p></details>
          <details><summary>Kenapa laporan saya belum muncul di papan?</summary><p>Laporan baru hanya terlihat oleh Anda dan admin sampai diverifikasi. Anda bisa memantaunya lewat "Laporan saya".</p></details>
          <details><summary>Bagaimana kalau laporan saya ditolak?</summary><p>Alasan penolakan dikirim ke notifikasi Anda dan dicatat di riwayat laporan. Anda bisa mengirim ulang dengan informasi yang lebih lengkap.</p></details>
          <details><summary>Saya mengalami masalah yang sama dengan laporan orang lain.</summary><p>Tekan "Saya juga mengalami ini" di laporan tersebut. Satu akun hanya bisa memberi satu dukungan per laporan, dan jumlah dukungan membantu menentukan prioritas.</p></details>
          <details><summary>Siapa yang bisa melihat foto yang saya unggah?</summary><p>Foto tampil bersama laporan setelah diverifikasi. Hindari foto yang memperlihatkan wajah orang lain.</p></details>
          <details><summary>Apakah saya mendapat pemberitahuan?</summary><p>Ya, lewat ikon lonceng di header setiap kali laporan Anda ditanggapi. Pemberitahuan lewat email atau WhatsApp belum tersedia.</p></details>
        </section>
      </div>
      <div style="display:grid;gap:20px">
        <section class="panel"><h2>Lacak tiket</h2><p class="psub">Masukkan nomor tiket, misalnya FSK-2611-AB12.</p>
          <form method="get" class="toolbar"><input type="hidden" name="hal" value="alur"><input type="text" name="lacak" value="<?= e($_GET['lacak'] ?? '') ?>" placeholder="FSK-…" aria-label="Nomor tiket" style="flex:1;text-transform:uppercase"><button class="btn primary" type="submit">Lacak</button></form>
          <div class="err" aria-live="polite" style="margin-top:8px"><?= e($lacakErr) ?></div></section>
        <section class="emerg" id="darurat"><h2>Untuk hal darurat, jangan lewat papan ini</h2>
          <p>Kekerasan, pelecehan seksual, ancaman keamanan, atau kondisi gawat darurat harus dilaporkan langsung ke saluran berikut agar ditangani rahasia dan cepat.</p>
          <?php if ($kontak): ?><ul><?php foreach ($kontak as [$n, $k]): ?><li><b><?= e($n) ?></b><span><?= e($k) ?></span></li><?php endforeach; ?></ul>
          <?php else: ?><p><b>Kontak resmi belum diisi admin.</b> <?= is_admin() ? 'Isi lewat Admin → Pengaturan.' : 'Sementara itu, hubungi pos keamanan gedung terdekat atau Satgas PPKS kampus.' ?></p><?php endif; ?></section>
        <section class="panel" id="tentang"><h2>Tentang Suara Fasilkom</h2><p class="psub">Sistem transparansi keluh kesah dan laporan progres perbaikan di Fakultas Ilmu Komputer UNSIKA. Tim Verifikasi memeriksa setiap laporan, meneruskannya ke unit yang bertanggung jawab, lalu mencatat setiap tindak lanjut di riwayat yang bisa dibaca semua orang.</p>
          <ul style="margin:0;padding-left:18px;display:grid;gap:6px"><li>Mengajukan keluhan dalam 4 langkah, dengan foto bila ada.</li><li>Mendukung laporan orang lain yang mengalami masalah sama.</li><li>Memantau status laporan dari diterima sampai selesai.</li><li>Melihat jadwal pemeliharaan dan program perbaikan fakultas.</li></ul></section>
        <section class="panel" id="privasi"><h2>Kebijakan privasi &amp; anonimitas</h2>
          <p class="psub"><b>Siapa yang melihat identitas Anda.</b> Jika Anda memilih anonim, ID akun tidak disimpan pada laporan. Sistem hanya menyimpan kunci kepemilikan acak satu arah agar Anda tetap dapat memantau laporan.</p>
          <p class="psub"><b>Data yang disimpan.</b> Nama, email kampus, isi laporan, foto yang Anda unggah, dan dukungan yang Anda berikan. Kata sandi disimpan dalam bentuk terenkripsi.</p>
          <p class="psub" style="margin-bottom:0"><b>Sebelum melapor.</b> Jangan cantumkan nomor HP, NIM, atau nama orang lain, dan hindari foto yang memperlihatkan wajah orang lain. Admin akan menyunting atau menyembunyikan laporan yang memuat data pribadi.</p></section>
      </div>
    </div>
  </main>
  <?php page_foot(); exit;
}

/* =============================== Beranda =============================== */
$st = $_GET['st'] ?? 'aktif'; $qstr = trim($_GET['q'] ?? ''); $kat = $_GET['kat'] ?? '';
$w = ["l.status IN ('verifikasi','diproses','selesai','ditolak')", 'l.tersembunyi = 0']; $p = [':me' => $uid];
if ($st === 'aktif') $w[] = "l.status IN ('verifikasi','diproses')"; elseif ($st === 'selesai') $w[] = "l.status = 'selesai'";
if ($qstr !== '') { $w[] = '(l.judul LIKE :q1 OR l.tiket LIKE :q2 OR r.nama LIKE :q3 OR l.kategori LIKE :q4)'; $p[':q1'] = $p[':q2'] = $p[':q3'] = $p[':q4'] = '%' . $qstr . '%'; }
if (isset(KATEGORI[$kat])) { $w[] = 'l.kategori = :kat'; $p[':kat'] = $kat; }
$limit = min(200, max(8, (int)($_GET['n'] ?? 8)));
$rows = laporan_cari($baseSel, $w, $p, 'dukung DESC, l.dibuat DESC', $limit + 1);
$more = count($rows) > $limit; $rows = array_slice($rows, 0, $limit);
$openMatch = $qstr !== '' ? count(array_filter($rows, fn($r) => in_array($r['status'], ['verifikasi', 'diproses'], true))) : 0;
$s = statistik();
$mine = laporan_cari($baseSel, ['(l.user_id = :uid OR l.owner_hash = :owner_hash)'], [':me' => $uid, ':uid' => $uid, ':owner_hash' => anon_owner_hash($uid)], 'l.dibuat DESC', 10);
$up = q("SELECT j.*, r.nama ruang FROM jadwal j LEFT JOIN ruangan r ON r.id = j.ruangan_id WHERE j.status <> 'batal' AND COALESCE(j.sampai, j.mulai) >= CURDATE() AND j.mulai <= CURDATE() + INTERVAL 30 DAY ORDER BY j.mulai LIMIT 4")->fetchAll();
$fin = q("SELECT tiket, judul, unit, selesai_pada, dibuat, rating FROM laporan WHERE status = 'selesai' AND tersembunyi = 0 ORDER BY COALESCE(selesai_pada, dibuat) DESC LIMIT 4")->fetchAll();
$url = function (array $add) { $a = array_merge(array_intersect_key($_GET, array_flip(['q', 'kat', 'st', 'n'])), $add); return 'index.php?' . http_build_query(array_filter($a, fn($v) => $v !== '' && $v !== null)) . '#papan'; };

page_head('Beranda', 'beranda', true);
?>
<section class="hero" aria-labelledby="heroTitle">
  <div class="hero-in">
    <div class="hero-layout">
      <div class="hero-copy">
        <p class="welcome">Fakultas Ilmu Komputer · UNSIKA</p>
        <h1 id="heroTitle"><span>Transparansi Keluh Kesah</span><span>&amp; Progres Fasilkom UNSIKA</span></h1>
        <div class="title-rule" aria-hidden="true"></div>
        <div class="lines"><b>Pengaduan Terbuka</b><span>Laporan Progres Perbaikan</span></div>
        <div class="cta"><?php if (!is_admin()): ?><button class="hbtn solid" type="button" data-open-complaint>Ajukan Keluhan <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="m9 6 6 6-6 6"/></svg></button><?php else: ?><a class="hbtn solid" href="index.php?hal=papan">Lihat Papan Laporan</a><?php endif; ?><a class="hbtn ghost" href="index.php?hal=papan">Lihat Papan Laporan</a></div>
      </div>
      <aside class="dean-feature" aria-label="Profil Dr. Oman Komarudin">
        <img src="brand/dekan-fasilkom.jpg" alt="Potret Dr. Oman Komarudin mengenakan toga akademik">
        <div class="dean-caption"><small>Fakultas Ilmu Komputer UNSIKA</small><strong>Dr. Oman Komarudin, S.Si., M.Kom.</strong></div>
      </aside>
    </div>
  </div>
  <a class="hero-down" href="#data">Lihat data transparansi<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg></a>
</section>

<main class="wrap" id="data">
  <div class="sec-head reveal-on-scroll"><span class="eyebrow">Data transparansi</span><h2>Data Transparansi Layanan</h2><p>Statistik dan informasi terkini dari sistem pengaduan Fakultas Ilmu Komputer UNSIKA. Hanya laporan yang sudah diverifikasi yang dihitung.</p></div>
  <div class="feat reveal-on-scroll">
    <div><span class="k">Laporan terverifikasi</span><span class="v"><?= (int)$s['total'] ?></span><span class="d"><?= (int)$s['aktif'] ?> sedang ditangani</span></div>
    <div><span class="k">Tingkat penyelesaian</span><span class="v"><?= $s['valid'] ? round($s['selesai'] / $s['valid'] * 100) . '%' : '–' ?></span><span class="d"><?= (int)$s['selesai'] ?> dari <?= (int)$s['valid'] ?> laporan valid</span></div>
    <div><span class="k">Rata-rata waktu selesai</span><span class="v"><?= $s['jam'] !== null ? str_replace('.', ',', round($s['jam'] / 24, 1)) . ' hr' : '–' ?></span><span class="d">Dihitung dari laporan yang sudah selesai</span></div>
    <div><span class="k">Total dukungan</span><span class="v"><?= (int)$s['dukung'] ?></span><span class="d">Sivitas yang mengalami masalah serupa</span></div>
  </div>

  <section id="papan" style="display:grid;gap:14px">
    <div class="page-head"><div><h2 style="font-size:24px">Papan progres laporan</h2><p>Sebelum melapor, cek dulu apakah keluhan Anda sudah dilaporkan orang lain. Jika sudah, cukup beri dukungan.</p></div></div>
    <form class="toolbar" method="get" action="index.php#papan">
      <div class="search"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg><input type="search" name="q" value="<?= e($qstr) ?>" placeholder="Ketik keluhan Anda, misalnya: AC lab, wifi lantai 4, proyektor" aria-label="Cek keluhan yang sudah dilaporkan"></div>
      <select name="kat" aria-label="Kategori"><option value="">Semua kategori</option><?php foreach (KATEGORI as $k => $u): ?><option <?= $kat === $k ? 'selected' : '' ?>><?= e($k) ?></option><?php endforeach; ?></select>
      <input type="hidden" name="st" value="<?= e($st) ?>"><button class="btn" type="submit">Cari</button>
      <div class="seg" role="group" aria-label="Status"><?php foreach (['aktif' => 'Sedang ditangani', 'selesai' => 'Selesai', 'semua' => 'Semua'] as $k => $lbl): ?><a class="<?= $st === $k ? 'on' : '' ?>" href="<?= e($url(['st' => $k, 'n' => ''])) ?>"><?= e($lbl) ?></a><?php endforeach; ?></div>
    </form>
    <?php if (mb_strlen($qstr) >= 3): ?>
      <?= $openMatch ? '<div class="hint found"><span><b>Keluhan serupa sudah dilaporkan.</b> Beri dukungan pada laporan di bawah agar penanganannya diprioritaskan.</span></div>'
        : '<div class="hint none"><span><b>Belum ada laporan yang sedang ditangani untuk kata kunci ini.</b> Anda bisa menjadi yang pertama melapor.</span>' . (!is_admin() ? '<button class="btn sm primary" type="button" data-open-complaint data-prefill="' . e($qstr) . '">Laporkan sekarang</button>' : '') . '</div>' ?>
    <?php endif; ?>
    <div class="list">
      <?php if (!$rows): ?><div class="empty"><b><?= $qstr !== '' ? 'Tidak ada laporan yang cocok.' : 'Belum ada laporan pada filter ini.' ?></b></div><?php endif; ?>
      <?php foreach ($rows as $r) echo baris($r, $uid, true, 'index.php?' . http_build_query(array_filter(['q' => $qstr, 'kat' => $kat, 'st' => $st]))); ?>
    </div>
    <?php if ($more): ?><div class="btn-row" style="justify-content:center"><a class="btn sm" href="<?= e($url(['n' => $limit + 8])) ?>">Tampilkan 8 laporan lagi</a></div><?php endif; ?>
  </section>

  <?php if ($mine): ?>
  <section id="saya" style="display:grid;gap:14px">
    <div class="page-head"><h2 style="font-size:22px">Laporan saya</h2><a class="btn sm" href="index.php?hal=papan&amp;lingkup=saya">Lihat semua</a></div>
    <div class="list"><?php foreach ($mine as $r) echo baris($r, $uid); ?></div>
  </section>
  <?php endif; ?>

  <div class="grid-even">
    <section class="panel"><h2>Pemeliharaan terdekat</h2><p class="psub">Fasilitas yang akan dirawat atau diperbaiki dalam 30 hari ke depan.</p>
      <?php if (!$up): ?><div class="empty">Tidak ada pemeliharaan terjadwal.</div><?php endif; ?>
      <div style="display:grid;gap:8px"><?php foreach ($up as $j): ?><a class="row" style="grid-template-columns:56px minmax(0,1fr);padding:10px;border:1px solid var(--line);border-radius:12px" href="jadwal.php?bulan=<?= substr($j['mulai'], 0, 7) ?>&amp;lihat=<?= $j['id'] ?>#detail">
        <span style="text-align:center;background:var(--panel-2);border-radius:10px;padding:6px 0"><b style="font-size:18px;display:block"><?= (int)date('j', strtotime($j['mulai'])) ?></b><span class="h"><?= $bln[(int)date('n', strtotime($j['mulai']))] ?></span></span>
        <span style="min-width:0"><h3><?= e($j['judul']) ?></h3><span class="meta"><?= e($j['ruang'] ?: '–') ?><?= $j['jam'] ? ' · ' . e($j['jam']) : '' ?></span></span></a><?php endforeach; ?></div></section>
    <section class="panel"><h2>Baru diselesaikan</h2><p class="psub">Keluhan yang sudah ditindaklanjuti sampai tuntas.</p>
      <?php if (!$fin): ?><div class="empty">Belum ada laporan yang selesai.</div><?php endif; ?>
      <div style="display:grid;gap:8px"><?php foreach ($fin as $r): ?><a class="row" style="grid-template-columns:minmax(0,1fr);padding:10px 12px;border:1px solid var(--line);border-radius:12px" href="index.php?tiket=<?= urlencode($r['tiket']) ?>"><span style="min-width:0"><h3><?= e($r['judul']) ?></h3><span class="meta"><?= e($r['unit'] ?? '') ?> · selesai <?= e(tgl($r['selesai_pada'] ?: $r['dibuat'])) ?><?php if ($r['rating']): ?> · <span class="stars-txt"><?= str_repeat('★', (int)$r['rating']) ?></span><?php endif; ?></span></span></a><?php endforeach; ?></div></section>
  </div>

  <div class="grid-even" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr))">
    <a class="panel" style="text-decoration:none;display:grid;gap:6px" href="index.php?hal=papan"><b>Papan laporan →</b><span class="h">Pantau setiap keluhan dari masuk sampai selesai.</span></a>
    <a class="panel" style="text-decoration:none;display:grid;gap:6px" href="index.php?hal=progres"><b>Progres perbaikan →</b><span class="h">Program perbaikan dan kinerja tiap unit.</span></a>
    <a class="panel" style="text-decoration:none;display:grid;gap:6px" href="index.php?hal=alur"><b>Alur &amp; kontak darurat →</b><span class="h">Cara melapor, pertanyaan umum, dan saluran untuk hal darurat.</span></a>
  </div>
</main>
<?php page_foot();
