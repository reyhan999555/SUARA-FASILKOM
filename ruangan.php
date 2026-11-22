<?php
/* Daftar ruangan: jumlah laporan per ruangan dan jadwal terdekat. Admin bisa menambah, mengubah, menghapus ruangan. */
require __DIR__ . '/app.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_admin(); check_csrf();
  $aksi = $_POST['aksi'] ?? ''; $id = (int)($_POST['id'] ?? 0);
  if ($aksi === 'hapus') {
    $n = (int)q('SELECT COUNT(*) FROM laporan WHERE ruangan_id = ?', [$id])->fetchColumn();
    if ($n) flash("Ruangan tidak bisa dihapus karena dipakai $n laporan.", 'err');
    else { q('DELETE FROM ruangan WHERE id = ?', [$id]); flash('Ruangan dihapus.'); }
    go('ruangan.php');
  }
  $nama = strtoupper(trim($_POST['nama'] ?? '')); $gedung = trim($_POST['gedung'] ?? '') ?: null;
  if (mb_strlen($nama) < 3) { flash('Nama ruangan minimal 3 karakter.', 'err'); go('ruangan.php'); }
  try {
    if ($id) q('UPDATE ruangan SET nama = ?, gedung = ? WHERE id = ?', [$nama, $gedung, $id]);
    else q('INSERT INTO ruangan (nama, gedung) VALUES (?, ?)', [$nama, $gedung]);
    flash($id ? 'Ruangan diperbarui.' : 'Ruangan ditambahkan.');
  } catch (PDOException $e) { flash('Nama ruangan itu sudah ada.', 'err'); }
  go('ruangan.php');
}

$rows = q("SELECT r.*,
  (SELECT COUNT(*) FROM laporan l WHERE l.ruangan_id = r.id AND l.status IN ('verifikasi','diproses') AND l.tersembunyi = 0) aktif,
  (SELECT COUNT(*) FROM laporan l WHERE l.ruangan_id = r.id AND l.status = 'selesai' AND l.tersembunyi = 0) selesai,
  (SELECT MIN(j.mulai) FROM jadwal j WHERE j.ruangan_id = r.id AND j.status = 'terjadwal' AND COALESCE(j.sampai, j.mulai) >= CURDATE()) jadwal_berikut
  FROM ruangan r ORDER BY r.nama")->fetchAll();
$edit = is_admin() && isset($_GET['edit']) ? q('SELECT * FROM ruangan WHERE id = ?', [(int)$_GET['edit']])->fetch() : null;

page_head('Ruangan', 'ruangan');
?>
<main class="wrap">
  <div class="page-head"><div><span class="eyebrow">Ruangan</span><h1>Daftar ruangan</h1><p>Lokasi yang bisa dipilih saat mengajukan keluhan, beserta laporan yang sedang ditangani dan jadwal pemeliharaan terdekat.</p></div></div>
  <div class="grid2">
    <section class="panel">
      <div class="tbl-wrap"><table><thead><tr><th>Ruangan</th><th>Gedung</th><th>Sedang ditangani</th><th>Selesai</th><th>Pemeliharaan berikutnya</th><?php if (is_admin()): ?><th></th><?php endif; ?></tr></thead><tbody>
        <?php if (!$rows): ?><tr><td colspan="6"><div class="empty">Belum ada ruangan.</div></td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?><tr>
          <td><a href="index.php?<?= e(http_build_query(['ruang' => $r['id'], 'st' => 'semua'])) ?>#papan"><b><?= e($r['nama']) ?></b></a></td>
          <td><?= e($r['gedung'] ?: '–') ?></td>
          <td><?= $r['aktif'] ? '<span class="pill p-diproses">' . (int)$r['aktif'] . ' laporan</span>' : '–' ?></td>
          <td><?= (int)$r['selesai'] ?: '–' ?></td>
          <td><?= $r['jadwal_berikut'] ? '<a href="jadwal.php?bulan=' . substr($r['jadwal_berikut'], 0, 7) . '">' . e(tgl($r['jadwal_berikut'])) . '</a>' : '–' ?></td>
          <?php if (is_admin()): ?><td><div class="btn-row"><a class="btn sm" href="ruangan.php?edit=<?= $r['id'] ?>#form">Ubah</a>
            <form method="post" data-confirm="Hapus ruangan <?= e($r['nama']) ?>?"><?= csrf_field() ?><input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn sm danger" type="submit">Hapus</button></form></div></td><?php endif; ?>
        </tr><?php endforeach; ?>
      </tbody></table></div>
    </section>
    <aside style="display:grid;gap:16px">
      <?php if (is_admin()): $v = $edit ?: ['id' => 0, 'nama' => '', 'gedung' => '']; ?>
      <section class="panel" id="form"><h2><?= $v['id'] ? 'Ubah ruangan' : 'Tambah ruangan' ?></h2>
        <form class="stack" method="post" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
          <label for="nama">Nama ruangan<input type="text" id="nama" name="nama" maxlength="80" value="<?= e($v['nama']) ?>" placeholder="Contoh: FASILKOM 4.74-1" required></label>
          <label for="gedung">Gedung <span class="h">Opsional</span><input type="text" id="gedung" name="gedung" maxlength="80" value="<?= e($v['gedung'] ?? '') ?>"></label>
          <div class="btn-row"><button class="btn primary" type="submit">Simpan ruangan</button><?php if ($v['id']): ?><a class="btn" href="ruangan.php">Batal</a><?php endif; ?></div></form></section>
      <?php else: ?>
      <section class="panel"><h2>Ruangan tidak ada di daftar?</h2><p class="psub" style="margin-bottom:0">Hubungi admin fakultas agar ruangannya ditambahkan. Sementara itu, pilih ruangan terdekat dan jelaskan lokasi tepatnya di uraian laporan.</p></section>
      <?php endif; ?>
    </aside>
  </div>
</main>
<?php page_foot();
