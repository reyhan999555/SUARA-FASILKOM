<?php
/* Masuk & buat akun. Dua pintu: Pengguna (daftar sendiri) dan Admin (dibuat pemilik).
   Hanya email kampus (…unsika.ac.id). Akun admin tidak boleh memakai email mahasiswa. */
require __DIR__ . '/app.php';
if (user()) go('index.php');

$as = ($_GET['as'] ?? 'user') === 'admin' ? 'admin' : 'user';
$owner = q("SELECT email, dibuat FROM users WHERE role = 'owner' LIMIT 1")->fetch();
$mode = $as === 'admin' ? ($owner ? 'masuk' : 'pemilik') : (($_GET['mode'] ?? '') === 'daftar' ? 'daftar' : 'masuk');
$next = safe_next($_GET['next'] ?? '');
$err = ''; $email = ''; $nama = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  check_csrf();
  $email = strtolower(trim($_POST['email'] ?? '')); $pw = (string)($_POST['password'] ?? ''); $nama = trim($_POST['nama'] ?? '');
  if (!preg_match(EMAIL_KAMPUS, $email)) $err = 'Gunakan email kampus UNSIKA, misalnya nama@student.unsika.ac.id atau nama@staff.unsika.ac.id.';
  elseif ($mode === 'masuk') {
    $u = q('SELECT * FROM users WHERE email = ?', [$email])->fetch();
    if (!$u || !password_verify($pw, $u['password_hash'])) { usleep(500000); $err = 'Email atau kata sandi salah.'; }
    elseif ($as === 'admin' && $u['role'] === 'user') $err = 'Akun ini terdaftar sebagai pengguna. Pilih "Pengguna" di atas untuk masuk.';
    elseif ($as === 'user' && $u['role'] !== 'user') $err = 'Akun ini adalah akun admin. Pilih "Admin" di atas untuk masuk.';
    else { session_regenerate_id(true); $_SESSION['uid'] = $u['id']; flash('Selamat datang, ' . $u['nama'] . '!'); go($next); }
  } else {
    if (mb_strlen($nama) < 2) $err = 'Isi nama lengkap minimal 2 huruf.';
    elseif ($mode === 'pemilik' && preg_match(EMAIL_MAHASISWA, $email)) $err = 'Akun admin harus memakai email staf atau dosen, bukan email mahasiswa (@student).';
    elseif (strlen($pw) < 8) $err = 'Kata sandi minimal 8 karakter.';
    elseif ($pw !== (string)($_POST['password2'] ?? '')) $err = 'Kedua kata sandi tidak sama.';
    elseif (q('SELECT 1 FROM users WHERE email = ?', [$email])->fetchColumn()) $err = 'Email ini sudah terdaftar. Silakan masuk.';
    elseif ($mode === 'pemilik' && q("SELECT 1 FROM users WHERE role = 'owner'")->fetchColumn()) $err = 'Akun pemilik sudah ada.';
    else {
      q('INSERT INTO users (nama, email, password_hash, role, dibuat) VALUES (?, ?, ?, ?, NOW())',
        [mb_substr($nama, 0, 60), $email, password_hash($pw, PASSWORD_DEFAULT), $mode === 'pemilik' ? 'owner' : 'user']);
      session_regenerate_id(true); $_SESSION['uid'] = (int)db()->lastInsertId();
      flash($mode === 'pemilik' ? 'Akun pemilik dibuat. Anda masuk sebagai admin utama.' : 'Akun dibuat. Selamat datang!');
      go($next);
    }
  }
}
$reg = $mode !== 'masuk';
$title = $as === 'admin' ? ($mode === 'pemilik' ? 'Buat akun pemilik (admin utama)' : 'Masuk sebagai admin') : ($reg ? 'Buat akun pengguna' : 'Masuk sebagai pengguna');
$mask = fn($m) => substr(explode('@', $m)[0], 0, 2) . '•••@' . explode('@', $m)[1];
page_head('Masuk');
?>
<main class="auth">
  <form class="auth-card <?= $as === 'admin' ? 'admin' : '' ?>" method="post" novalidate>
    <?= csrf_field() ?>
    <div style="display:flex;gap:12px;align-items:center"><img src="brand/logo.png" alt="Logo Fasilkom UNSIKA" style="width:52px;height:52px;object-fit:contain"><div><b style="font-size:18px;display:block">Suara Fasilkom</b><small>Transparansi keluh kesah &amp; progres</small></div></div>
    <div><div class="h" style="margin-bottom:6px;font-weight:700">Masuk sebagai</div>
      <div class="seg"><a href="login.php?<?= e(http_build_query(['next' => $next])) ?>" class="<?= $as === 'user' ? 'on' : '' ?>">Pengguna</a><a href="login.php?<?= e(http_build_query(['as' => 'admin', 'next' => $next])) ?>" class="<?= $as === 'admin' ? 'on' : '' ?>">Admin</a></div></div>
    <h1 style="font-size:20px"><?= e($title) ?></h1>
    <p style="font-size:13.5px"><?= $as === 'admin'
      ? ($mode === 'pemilik' ? 'Belum ada admin. Akun ini menjadi pemilik yang bisa menambah admin lain. Gunakan email staf atau dosen, bukan email mahasiswa.' : 'Khusus Tim Verifikasi dan unit layanan. Akun admin dibuat oleh pemilik, tidak bisa didaftarkan sendiri.')
      : ($reg ? 'Untuk mahasiswa dan sivitas yang ingin melapor dan memantau keluhan.' : 'Gunakan email kampus dan kata sandi yang sudah Anda daftarkan.') ?></p>
    <?php if ($as === 'admin' && $owner): ?><div class="note">Akun pemilik sudah dibuat pada <?= e(tgl($owner['dibuat'])) ?> dengan email <b><?= e($mask($owner['email'])) ?></b>. Masuk dengan email dan kata sandi itu.</div><?php endif; ?>
    <?php if ($reg): ?><label for="nama">Nama lengkap<input type="text" id="nama" name="nama" maxlength="60" autocomplete="name" value="<?= e($nama) ?>" required></label><?php endif; ?>
    <label for="email"><?= $as === 'admin' ? 'Email staf kampus' : 'Email kampus' ?><input type="email" id="email" name="email" autocomplete="username" autocapitalize="none" spellcheck="false" value="<?= e($email) ?>" placeholder="<?= $as === 'admin' ? 'nama@unsika.ac.id' : 'nama@student.unsika.ac.id' ?>" required></label>
    <label for="password">Kata sandi<?= $reg ? ' <span class="h">Minimal 8 karakter.</span>' : '' ?><input type="password" id="password" name="password" autocomplete="<?= $reg ? 'new-password' : 'current-password' ?>" required></label>
    <?php if ($reg): ?><label for="password2">Ulangi kata sandi<input type="password" id="password2" name="password2" autocomplete="new-password" required></label><?php endif; ?>
    <div class="err" aria-live="polite"><?= e($err) ?></div>
    <button class="btn primary" type="submit" style="padding:12px"><?= $reg ? 'Buat akun' : ($as === 'admin' ? 'Masuk sebagai admin' : 'Masuk') ?></button>
    <?php if ($as === 'user'): ?><a class="btn" style="border:0;background:none;text-decoration:underline" href="login.php?<?= e(http_build_query(['mode' => $reg ? '' : 'daftar', 'next' => $next])) ?>"><?= $reg ? 'Sudah punya akun? Masuk' : 'Belum punya akun? Buat akun' ?></a><?php endif; ?>
    <span class="h">Hanya email berakhiran unsika.ac.id yang bisa dipakai.</span>
  </form>
</main>
<script><?php js(); ?></script>
</body></html>
