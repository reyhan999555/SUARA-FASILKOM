<?php
/* Kalender pemeliharaan: kalender bulanan + daftar 30 hari ke depan. Admin bisa menambah, mengubah, menghapus jadwal. */
require __DIR__ . '/app.php';
require_login();
$rooms = ruangan_list();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_admin(); check_csrf();
  $id = (int)($_POST['id'] ?? 0);
  if (($_POST['aksi'] ?? '') === 'hapus') { q('DELETE FROM jadwal WHERE id = ?', [$id]); flash('Jadwal dihapus.'); go('jadwal.php'); }
  $d = ['judul' => trim($_POST['judul'] ?? ''), 'ruangan_id' => (int)($_POST['ruangan_id'] ?? 0) ?: null, 'unit' => $_POST['unit'] ?? '',
        'mulai' => $_POST['mulai'] ?? '', 'sampai' => ($_POST['sampai'] ?? '') ?: null, 'jam' => trim($_POST['jam'] ?? '') ?: null,
        'dampak' => $_POST['dampak'] ?? 'sedang', 'keterangan' => trim($_POST['keterangan'] ?? '') ?: null, 'status' => $_POST['status'] ?? 'terjadwal'];
  $ok = mb_strlen($d['judul']) >= 5 && in_array($d['unit'], UNITS, true) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['mulai'])
     && (!$d['sampai'] || (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['sampai']) && $d['sampai'] >= $d['mulai']))
     && isset(DAMPAK[$d['dampak']]) && in_array($d['status'], ['terjadwal', 'selesai', 'batal'], true);
  if (!$ok) { flash('Periksa lagi isian jadwal: nama pekerjaan minimal 5 karakter, tanggal valid, dan tanggal selesai tidak sebelum tanggal mulai.', 'err'); back('jadwal.php'); }
  $p = [$d['judul'], $d['ruangan_id'], $d['unit'], $d['mulai'], $d['sampai'], $d['jam'], $d['dampak'], $d['keterangan'], $d['status']];
  if ($id) { q('UPDATE jadwal SET judul=?, ruangan_id=?, unit=?, mulai=?, sampai=?, jam=?, dampak=?, keterangan=?, status=? WHERE id=?', [...$p, $id]); flash('Jadwal diperbarui.'); }
  else { q('INSERT INTO jadwal (judul, ruangan_id, unit, mulai, sampai, jam, dampak, keterangan, status) VALUES (?,?,?,?,?,?,?,?,?)', $p); flash('Jadwal ditambahkan.'); }
  go('jadwal.php?bulan=' . substr($d['mulai'], 0, 7));
}

$bulan = preg_match('/^\d{4}-\d{2}$/', $_GET['bulan'] ?? '') ? $_GET['bulan'] : date('Y-m');
$first = new DateTimeImmutable($bulan . '-01');
$start = $first->modify('-' . ((int)$first->format('N') - 1) . ' days');
$end = $start->modify('+41 days');
$evs = q('SELECT j.*, r.nama ruang FROM jadwal j LEFT JOIN ruangan r ON r.id = j.ruangan_id WHERE j.mulai <= ? AND COALESCE(j.sampai, j.mulai) >= ? ORDER BY j.mulai',
  [$end->format('Y-m-d'), $start->format('Y-m-d')])->fetchAll();
$up = q("SELECT j.*, r.nama ruang FROM jadwal j LEFT JOIN ruangan r ON r.id = j.ruangan_id WHERE j.status <> 'batal' AND COALESCE(j.sampai, j.mulai) >= CURDATE() AND j.mulai <= CURDATE() + INTERVAL 30 DAY ORDER BY j.mulai LIMIT 12")->fetchAll();
$edit = null;
if (is_admin() && isset($_GET['edit'])) $edit = q('SELECT * FROM jadwal WHERE id = ?', [(int)$_GET['edit']])->fetch() ?: null;
$detail = isset($_GET['lihat']) ? q('SELECT j.*, r.nama ruang FROM jadwal j LEFT JOIN ruangan r ON r.id = j.ruangan_id WHERE j.id = ?', [(int)$_GET['lihat']])->fetch() : null;
$bln = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$cls = fn($j) => $j['status'] === 'batal' ? 'ev-batal' : ($j['status'] === 'selesai' ? 'ev-selesai' : 'ev-' . $j['dampak']);

page_head('Kalender pemeliharaan', 'kalender');
?>
<main class="wrap">
  <div class="page-head"><div><span class="eyebrow">Kalender</span><h1>Kalender pemeliharaan</h1><p>Jadwal perawatan dan perbaikan fasilitas, supaya Anda tahu kapan ruang, lab, atau jaringan tidak bisa dipakai.</p></div>
    <?php if (is_admin()): ?><a class="btn primary" href="jadwal.php?edit=0#form">+ Tambah jadwal</a><?php endif; ?></div>
  <div class="grid2">
    <section class="panel" style="display:grid;gap:14px">
      <div class="btn-row"><a class="btn sm" href="jadwal.php?bulan=<?= $first->modify('-1 month')->format('Y-m') ?>" aria-label="Bulan sebelumnya">‹</a><h2 style="font-size:20px;min-width:180px"><?= $bln[(int)$first->format('n')] . ' ' . $first->format('Y') ?></h2><a class="btn sm" href="jadwal.php?bulan=<?= $first->modify('+1 month')->format('Y-m') ?>" aria-label="Bulan berikutnya">›</a><a class="btn sm" href="jadwal.php">Bulan ini</a></div>
      <div class="legend"><span><i style="background:var(--info)"></i>Gangguan ringan</span><span><i style="background:var(--warn)"></i>Sebagian tidak bisa dipakai</span><span><i style="background:var(--crit)"></i>Ditutup total</span><span><i style="background:var(--ok)"></i>Selesai</span></div>
      <div class="cal-scroll"><div class="cal">
        <?php foreach (['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'] as $d): ?><div class="dow"><?= $d ?></div><?php endforeach; ?>
        <?php for ($i = 0; $i < 42; $i++): $day = $start->modify("+$i days"); $k = $day->format('Y-m-d');
          if ($i === 35 && $day->format('m') !== $first->format('m')) break;
          $today = $k === date('Y-m-d'); $list = array_filter($evs, fn($j) => $j['mulai'] <= $k && ($j['sampai'] ?: $j['mulai']) >= $k); ?>
          <div class="day <?= $day->format('m') !== $first->format('m') ? 'out' : '' ?> <?= $today ? 'today' : '' ?>"><span class="dn"><?= (int)$day->format('j') ?></span>
            <?php foreach (array_slice($list, 0, 3) as $j): ?><a class="ev <?= $cls($j) ?>" href="jadwal.php?bulan=<?= $bulan ?>&amp;lihat=<?= $j['id'] ?>#detail" title="<?= e($j['judul']) ?>"><?= e($j['judul']) ?></a><?php endforeach; ?>
            <?php if (count($list) > 3): ?><span class="h">+<?= count($list) - 3 ?> lainnya</span><?php endif; ?></div>
        <?php endfor; ?>
      </div></div>
    </section>
    <aside style="display:grid;gap:16px">
      <?php if ($detail): ?>
      <section class="panel" id="detail" style="display:grid;gap:12px"><h2><?= e($detail['judul']) ?></h2>
        <dl class="kv"><dt>Tanggal</dt><dd><?= e(tgl($detail['mulai'])) ?><?= $detail['sampai'] && $detail['sampai'] !== $detail['mulai'] ? ' – ' . e(tgl($detail['sampai'])) : '' ?></dd><dt>Jam</dt><dd><?= e($detail['jam'] ?: 'Sepanjang hari') ?></dd><dt>Lokasi</dt><dd><?= e($detail['ruang'] ?: '–') ?></dd><dt>Unit</dt><dd><?= e($detail['unit']) ?></dd><dt>Dampak</dt><dd><?= e(DAMPAK[$detail['dampak']]) ?></dd><dt>Status</dt><dd><?= e(ucfirst($detail['status'])) ?></dd></dl>
        <?php if ($detail['keterangan']): ?><p style="white-space:pre-line"><?= e($detail['keterangan']) ?></p><?php endif; ?>
        <?php if (is_admin()): ?><div class="btn-row"><a class="btn sm" href="jadwal.php?edit=<?= $detail['id'] ?>#form">Ubah</a>
          <form method="post" data-confirm="Hapus jadwal ini?"><?= csrf_field() ?><input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= $detail['id'] ?>"><button class="btn sm danger" type="submit">Hapus</button></form></div><?php endif; ?>
      </section>
      <?php endif; ?>
      <section class="panel"><h2>Akan datang</h2><p class="psub">Jadwal 30 hari ke depan.</p>
        <?php if (!$up): ?><div class="empty"><b>Tidak ada pemeliharaan terjadwal.</b></div><?php endif; ?>
        <div style="display:grid;gap:8px"><?php foreach ($up as $j): ?>
          <a class="row" style="grid-template-columns:56px minmax(0,1fr);padding:10px;border:1px solid var(--line);border-radius:12px" href="jadwal.php?bulan=<?= substr($j['mulai'], 0, 7) ?>&amp;lihat=<?= $j['id'] ?>#detail">
            <span style="text-align:center;background:var(--panel-2);border-radius:10px;padding:6px 0"><b style="font-size:18px;display:block"><?= (int)date('j', strtotime($j['mulai'])) ?></b><span class="h"><?= substr($bln[(int)date('n', strtotime($j['mulai']))], 0, 3) ?></span></span>
            <span style="min-width:0"><h3><?= e($j['judul']) ?></h3><span class="meta"><?= e($j['ruang'] ?: '–') ?><?= $j['jam'] ? ' · ' . e($j['jam']) : '' ?> · <span class="ev <?= $cls($j) ?>" style="display:inline-block"><?= e(DAMPAK[$j['dampak']]) ?></span></span></span></a>
        <?php endforeach; ?></div></section>
      <?php if (is_admin() && isset($_GET['edit'])): $v = $edit ?: ['id' => 0, 'judul' => '', 'ruangan_id' => null, 'unit' => UNITS[0], 'mulai' => date('Y-m-d'), 'sampai' => '', 'jam' => '', 'dampak' => 'sedang', 'keterangan' => '', 'status' => 'terjadwal']; ?>
      <section class="panel" id="form"><h2><?= $v['id'] ? 'Ubah jadwal' : 'Tambah jadwal' ?></h2>
        <form class="stack" method="post" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
          <label for="judul">Pekerjaan<input type="text" id="judul" name="judul" maxlength="90" value="<?= e($v['judul']) ?>" placeholder="Contoh: Servis rutin AC lab" required></label>
          <div class="two"><label for="ruangan_id">Lokasi<select id="ruangan_id" name="ruangan_id"><option value="">Tidak spesifik</option><?php foreach ($rooms as $r): ?><option value="<?= $r['id'] ?>" <?= $v['ruangan_id'] == $r['id'] ? 'selected' : '' ?>><?= e($r['nama']) ?></option><?php endforeach; ?></select></label>
            <label for="unit">Unit<select id="unit" name="unit"><?php foreach (UNITS as $u): ?><option <?= $v['unit'] === $u ? 'selected' : '' ?>><?= e($u) ?></option><?php endforeach; ?></select></label></div>
          <div class="two"><label for="mulai">Mulai<input type="date" id="mulai" name="mulai" value="<?= e($v['mulai']) ?>" required></label><label for="sampai">Sampai <span class="h">Kosongkan jika 1 hari</span><input type="date" id="sampai" name="sampai" value="<?= e($v['sampai'] ?? '') ?>"></label></div>
          <div class="two"><label for="jam">Jam<input type="text" id="jam" name="jam" maxlength="40" value="<?= e($v['jam'] ?? '') ?>" placeholder="08.00–12.00"></label>
            <label for="dampak">Dampak<select id="dampak" name="dampak"><?php foreach (DAMPAK as $k => $l): ?><option value="<?= $k ?>" <?= $v['dampak'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label></div>
          <?php if ($v['id']): ?><label for="status">Status<select id="status" name="status"><?php foreach (['terjadwal' => 'Terjadwal', 'selesai' => 'Selesai', 'batal' => 'Dibatalkan'] as $k => $l): ?><option value="<?= $k ?>" <?= $v['status'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label><?php endif; ?>
          <label for="keterangan">Keterangan untuk sivitas<textarea id="keterangan" name="keterangan" placeholder="Contoh: Praktikum dipindah ke Lab Dasar 2."><?= e($v['keterangan'] ?? '') ?></textarea></label>
          <button class="btn primary" type="submit">Simpan jadwal</button></form></section>
      <?php endif; ?>
    </aside>
  </div>
</main>
<?php page_foot();
