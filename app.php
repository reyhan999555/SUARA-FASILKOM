<?php
/* =========================================================================
   Suara Fasilkom — inti aplikasi (dipakai semua halaman)
   Berisi: konfigurasi database MySQL XAMPP, pembuatan & pembaruan tabel,
   sesi & akun, keamanan (CSRF), fungsi bantu, dan tampilan bersama
   (header, menu profil, notifikasi, footer, CSS, JS).
   Jalankan "php app.php setup" (lewat setup.bat) untuk membuat/memperbarui database.
   ========================================================================= */

date_default_timezone_set('Asia/Jakarta');

/* ---------- Konfigurasi (ubah bila MySQL Anda memakai kata sandi) ---------- */
define('DB_HOST', getenv('SF_DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('SF_DB_PORT') ?: '3306');
define('DB_NAME', getenv('SF_DB') ?: 'suara_fasilkom');
define('DB_USER', getenv('SF_DB_USER') ?: 'root');
define('DB_PASS', getenv('SF_DB_PASS') !== false ? getenv('SF_DB_PASS') : '');
define('UPLOAD_DIR', __DIR__ . '/uploads');
define('VERIF_DAYS', 2);

const KATEGORI = [
  'Fasilitas & Ruang Kelas' => 'Sarpras',
  'Laboratorium'            => 'Unit TIK',
  'Jaringan & Wi-Fi'        => 'Unit TIK',
  'Akademik & Perkuliahan'  => 'Bagian Akademik',
  'Layanan Administrasi'    => 'Bagian Umum',
  'Kebersihan & Keamanan'   => 'Sarpras',
  'Lainnya'                 => 'Bagian Umum',
];
const UNITS = ['Sarpras', 'Unit TIK', 'Bagian Akademik', 'Bagian Umum'];
const STATUS = [
  'menunggu' => 'Menunggu verifikasi', 'verifikasi' => 'Diverifikasi', 'diproses' => 'Diproses',
  'selesai' => 'Selesai', 'ditolak' => 'Ditolak', 'digabung' => 'Digabung', 'dibatalkan' => 'Dibatalkan',
];
const TRACK = ['menunggu' => 'Diterima', 'verifikasi' => 'Diverifikasi', 'diproses' => 'Diproses', 'selesai' => 'Selesai'];
const DAMPAK = ['ringan' => 'Gangguan ringan', 'sedang' => 'Sebagian tidak bisa dipakai', 'berat' => 'Ditutup total'];
const RUANGAN_AWAL = ['FASILKOM 4.78-4', 'FASILKOM 4.77-2', 'FASILKOM 4.76-2', 'FASILKOM 4.75-2', 'RUANG LAB DASAR 1', 'RUANG LAB DASAR 2'];
const EMAIL_KAMPUS = '/^[a-z0-9._%+-]+@([a-z0-9-]+\.)*unsika\.ac\.id$/';
const EMAIL_MAHASISWA = '/@student\.unsika\.ac\.id$/';

/* ---------- Database ---------- */
function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false,
    ]);
  } catch (PDOException $e) {
    http_response_code(503);
    echo '<!doctype html><meta charset="utf-8"><title>Database belum siap</title><body style="font-family:system-ui;padding:40px;max-width:640px">'
       . '<h1>Database belum siap</h1><p>Nyalakan <b>MySQL</b> di XAMPP Control Panel, lalu jalankan <b>setup.bat</b> sekali untuk membuat database <code>'
       . htmlspecialchars(DB_NAME) . '</code>.</p></body>';
    exit;
  }
  return $pdo;
}
function q(string $sql, array $p = []): PDOStatement { $s = db()->prepare($sql); $s->execute($p); return $s; }

/* Identitas pemilik anonim dipetakan dengan HMAC; nilai hash tidak mengungkap ID akun kepada admin. */
function anon_key(): string {
  static $key = null;
  if ($key !== null) return $key;
  $file = __DIR__ . '/.sf-anon-key.php';
  if (!is_file($file)) {
    $existingHashes = 0;
    try { $existingHashes = (int)q('SELECT COUNT(*) FROM laporan WHERE owner_hash IS NOT NULL')->fetchColumn(); } catch (Throwable $e) {}
    if ($existingHashes > 0) throw new RuntimeException('File .sf-anon-key hilang. Pulihkan file tersebut dari backup; jangan membuat kunci baru.');
    @file_put_contents($file, "<?php\nreturn '" . bin2hex(random_bytes(32)) . "';\n", LOCK_EX);
    @chmod($file, 0600);
  }
  $key = trim((string)@require $file);
  if (strlen($key) < 32) throw new RuntimeException('Kunci anonimitas tidak tersedia. Jalankan setup dan pulihkan .sf-anon-key.php dari backup.');
  return $key;
}
function anon_owner_hash(int $uid): string { return hash_hmac('sha256', (string)$uid, anon_key()); }
function laporan_milik(array $laporan, int $uid): bool {
  if (!empty($laporan['user_id']) && (int)$laporan['user_id'] === $uid) return true;
  return !empty($laporan['anonim']) && !empty($laporan['owner_hash'])
    && hash_equals((string)$laporan['owner_hash'], anon_owner_hash($uid));
}

/* ---------- Setup & pembaruan database (CLI: php app.php setup) ---------- */
function run_setup(): void {
  $pdo = null;
  for ($i = 0; $i < 20 && !$pdo; $i++) {
    try { $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); }
    catch (PDOException $e) { if ($i === 0) echo "Menunggu MySQL menyala...\n"; sleep(1); }
  }
  if (!$pdo) { fwrite(STDERR, "Gagal terhubung ke MySQL. Nyalakan MySQL di XAMPP Control Panel lalu coba lagi.\n"); exit(1); }
  $db = str_replace('`', '', DB_NAME);
  $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
  $pdo->exec("USE `$db`");
  $pdo->exec("CREATE TABLE IF NOT EXISTS users (
      id INT AUTO_INCREMENT PRIMARY KEY, nama VARCHAR(60) NOT NULL, email VARCHAR(120) NOT NULL UNIQUE,
      password_hash VARCHAR(255) NOT NULL, role ENUM('user','admin','owner') NOT NULL DEFAULT 'user', dibuat DATETIME NOT NULL
    ) ENGINE=InnoDB");
  $pdo->exec("CREATE TABLE IF NOT EXISTS ruangan (
      id INT AUTO_INCREMENT PRIMARY KEY, nama VARCHAR(80) NOT NULL UNIQUE, gedung VARCHAR(80) NULL, keterangan VARCHAR(200) NULL
    ) ENGINE=InnoDB");
  $pdo->exec("CREATE TABLE IF NOT EXISTS laporan (
      id INT AUTO_INCREMENT PRIMARY KEY, tiket VARCHAR(20) NOT NULL UNIQUE, user_id INT NOT NULL,
      judul VARCHAR(90) NOT NULL, isi TEXT NOT NULL, kategori VARCHAR(40) NOT NULL, ruangan_id INT NULL,
      foto VARCHAR(80) NULL, anonim TINYINT(1) NOT NULL DEFAULT 1,
      status ENUM('menunggu','verifikasi','diproses','selesai','ditolak') NOT NULL DEFAULT 'menunggu',
      unit VARCHAR(40) NULL, prioritas ENUM('rendah','sedang','tinggi') NOT NULL DEFAULT 'sedang',
      tersembunyi TINYINT(1) NOT NULL DEFAULT 0, konfirmasi ENUM('beres','belum') NULL, rating TINYINT NULL,
      dibuat DATETIME NOT NULL, selesai_pada DATETIME NULL,
      INDEX (status), INDEX (user_id), INDEX (ruangan_id),
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      FOREIGN KEY (ruangan_id) REFERENCES ruangan(id) ON DELETE SET NULL
    ) ENGINE=InnoDB");
  $pdo->exec("CREATE TABLE IF NOT EXISTS riwayat (
      id INT AUTO_INCREMENT PRIMARY KEY, laporan_id INT NOT NULL, oleh VARCHAR(60) NOT NULL, teks TEXT NOT NULL,
      foto VARCHAR(80) NULL, dibuat DATETIME NOT NULL,
      FOREIGN KEY (laporan_id) REFERENCES laporan(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
  $pdo->exec("CREATE TABLE IF NOT EXISTS dukungan (
      laporan_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY (laporan_id, user_id),
      FOREIGN KEY (laporan_id) REFERENCES laporan(id) ON DELETE CASCADE,
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
  $pdo->exec("CREATE TABLE IF NOT EXISTS jadwal (
      id INT AUTO_INCREMENT PRIMARY KEY, judul VARCHAR(90) NOT NULL, ruangan_id INT NULL, unit VARCHAR(40) NOT NULL,
      mulai DATE NOT NULL, sampai DATE NULL, jam VARCHAR(40) NULL,
      dampak ENUM('ringan','sedang','berat') NOT NULL DEFAULT 'sedang', keterangan TEXT NULL,
      status ENUM('terjadwal','selesai','batal') NOT NULL DEFAULT 'terjadwal',
      FOREIGN KEY (ruangan_id) REFERENCES ruangan(id) ON DELETE SET NULL
    ) ENGINE=InnoDB");
  /* Tabel tambahan: program perbaikan, catatan internal admin, pengaturan */
  $pdo->exec("CREATE TABLE IF NOT EXISTS proyek (
      id INT AUTO_INCREMENT PRIMARY KEY, nama VARCHAR(100) NOT NULL, unit VARCHAR(40) NOT NULL, progres TINYINT NOT NULL DEFAULT 0,
      anggaran VARCHAR(60) NULL, target DATE NULL, terkait VARCHAR(400) NULL, kabar VARCHAR(160) NULL,
      contoh TINYINT(1) NOT NULL DEFAULT 0, diperbarui DATETIME NOT NULL
    ) ENGINE=InnoDB");
  $pdo->exec("CREATE TABLE IF NOT EXISTS catatan (
      id INT AUTO_INCREMENT PRIMARY KEY, laporan_id INT NOT NULL, user_id INT NOT NULL, teks TEXT NOT NULL, dibuat DATETIME NOT NULL,
      FOREIGN KEY (laporan_id) REFERENCES laporan(id) ON DELETE CASCADE,
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
  $pdo->exec("CREATE TABLE IF NOT EXISTS pengaturan (kunci VARCHAR(40) PRIMARY KEY, nilai TEXT NULL) ENGINE=InnoDB");
  /* Kolom tambahan (aman dijalankan berulang) */
  $add = function (string $t, string $c, string $def) use ($pdo, $db) {
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $s->execute([$db, $t, $c]);
    if (!$s->fetchColumn()) $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def");
  };
  $add('users', 'unit', 'VARCHAR(40) NULL');
  $add('users', 'notif_dibaca', 'DATETIME NULL');
  $add('laporan', 'digabung_ke', 'INT NULL');
  $add('laporan', 'dup_count', 'INT NOT NULL DEFAULT 0');
  $add('laporan', 'catatan_pelapor', 'TEXT NULL');
  $add('laporan', 'konfirmasi_baru', 'TINYINT(1) NOT NULL DEFAULT 0');
  $add('laporan', 'contoh', 'TINYINT(1) NOT NULL DEFAULT 0');
  $add('laporan', 'owner_hash', 'CHAR(64) NULL');
  /* user_id boleh NULL untuk laporan anonim: relasi akun diputus agar admin tidak dapat menelusuri identitas. */
  $pdo->exec("ALTER TABLE laporan MODIFY user_id INT NULL");
  $idx = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?');
  $idx->execute([$db, 'laporan', 'idx_laporan_owner_hash']);
  if (!(int)$idx->fetchColumn()) $pdo->exec('CREATE INDEX idx_laporan_owner_hash ON laporan(owner_hash)');
  $anonKeyFile = __DIR__ . '/.sf-anon-key.php';
  if (!is_file($anonKeyFile)) {
    $existingHashes = (int)$pdo->query('SELECT COUNT(*) FROM laporan WHERE owner_hash IS NOT NULL')->fetchColumn();
    if ($existingHashes > 0) throw new RuntimeException('File .sf-anon-key hilang. Pulihkan dari backup sebelum menjalankan migrasi agar laporan anonim lama tetap dapat diakses pemiliknya.');
    file_put_contents($anonKeyFile, "<?php\nreturn '" . bin2hex(random_bytes(32)) . "';\n", LOCK_EX);
    @chmod($anonKeyFile, 0600);
  }
  $anonKey = trim((string)require $anonKeyFile);
  if (strlen($anonKey) < 32) throw new RuntimeException('Kunci anonimitas tidak valid. Pulihkan file .sf-anon-key.php dari backup atau buat ulang sebelum migrasi.');
  $legacyAnon = $pdo->query('SELECT id, user_id FROM laporan WHERE anonim = 1 AND user_id IS NOT NULL AND owner_hash IS NULL')->fetchAll(PDO::FETCH_ASSOC);
  $anonUpdate = $pdo->prepare('UPDATE laporan SET owner_hash = ?, user_id = NULL WHERE id = ?');
  foreach ($legacyAnon as $oldAnon) {
    $anonUpdate->execute([hash_hmac('sha256', (string)$oldAnon['user_id'], $anonKey), $oldAnon['id']]);
  }
  $add('jadwal', 'contoh', 'TINYINT(1) NOT NULL DEFAULT 0');
  $pdo->exec("ALTER TABLE laporan MODIFY status ENUM('menunggu','verifikasi','diproses','selesai','ditolak','digabung','dibatalkan') NOT NULL DEFAULT 'menunggu'");
  if (!(int)$pdo->query('SELECT COUNT(*) FROM ruangan')->fetchColumn()) {
    $s = $pdo->prepare('INSERT INTO ruangan (nama, gedung) VALUES (?, ?)');
    foreach (RUANGAN_AWAL as $r) $s->execute([$r, str_starts_with($r, 'FASILKOM') ? 'Gedung Fasilkom' : 'Laboratorium Dasar']);
  }
  if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0775, true);
  echo "Database '" . DB_NAME . "' siap.\n";
}
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
  if (($argv[1] ?? '') === 'setup') { run_setup(); exit(0); }
  echo "Pemakaian: php app.php setup\n"; exit(0);
}

/* ---------- Sesi, keamanan, dan akun ---------- */
if (session_status() === PHP_SESSION_NONE) {
  session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
  session_name('sf_sesi');
  session_start();
}
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(16)); }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . csrf() . '">'; }
function check_csrf(): void {
  if (!hash_equals(csrf(), (string)($_POST['csrf'] ?? ''))) { http_response_code(400); flash('Sesi formulir kedaluwarsa. Silakan coba lagi.', 'err'); back(); }
}
function flash(?string $msg = null, string $type = 'ok') {
  if ($msg !== null) { $_SESSION['flash'] = [$msg, $type]; return null; }
  $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f;
}
function go(string $url): never {
  if (($_GET['embed'] ?? '') === 'report' && preg_match('/^(?:index|admin)\.php(?:\?|$)/', $url) && !str_contains($url, 'embed=')) {
    $url .= (str_contains($url, '?') ? '&' : '?') . 'embed=report';
  }
  header('Location: ' . $url); exit;
}
function back(string $fallback = 'index.php'): never {
  $ref = $_SERVER['HTTP_REFERER'] ?? '';
  $host = $_SERVER['HTTP_HOST'] ?? '';
  go($ref && parse_url($ref, PHP_URL_HOST) === parse_url('http://' . $host, PHP_URL_HOST) ? $ref : $fallback);
}
function safe_next(?string $n): string {
  return ($n && preg_match('#^[a-z]+\.php(\?[A-Za-z0-9_=&%.+-]*)?$#', $n)) ? $n : 'index.php';
}

function user(): ?array {
  static $u = false;
  if ($u === false) {
    $u = null;
    if (!empty($_SESSION['uid'])) $u = q('SELECT id, nama, email, role, unit, notif_dibaca FROM users WHERE id = ?', [$_SESSION['uid']])->fetch() ?: null;
  }
  return $u;
}
function is_admin(): bool { $u = user(); return $u && in_array($u['role'], ['admin', 'owner'], true); }
function is_owner(): bool { $u = user(); return $u && $u['role'] === 'owner'; }
function require_login(): void {
  if (!user()) go('login.php?next=' . urlencode(basename($_SERVER['SCRIPT_NAME']) . (($_SERVER['QUERY_STRING'] ?? '') ? '?' . $_SERVER['QUERY_STRING'] : '')));
}
function require_admin(): void {
  require_login();
  if (!is_admin()) { flash('Halaman itu khusus admin.', 'err'); go('index.php'); }
}
/* Nama yang tercatat di riwayat untuk tindakan admin: unit admin bila ada, selain itu Tim Verifikasi. */
function actor(): string { $u = user(); return ($u && $u['unit']) ? $u['unit'] : 'Tim Verifikasi'; }

/* ---------- Data bantu ---------- */
function ruangan_list(): array { return q('SELECT id, nama, gedung FROM ruangan ORDER BY nama')->fetchAll(); }
function pengaturan(string $k, string $default = ''): string { $v = q('SELECT nilai FROM pengaturan WHERE kunci = ?', [$k])->fetchColumn(); return $v === false ? $default : (string)$v; }
function set_pengaturan(string $k, string $v): void { q('INSERT INTO pengaturan (kunci, nilai) VALUES (?, ?) ON DUPLICATE KEY UPDATE nilai = VALUES(nilai)', [$k, $v]); }
function kontak_darurat(): array {
  $out = [];
  foreach (preg_split('/\r?\n/', pengaturan('kontak_darurat')) as $l) { $p = array_map('trim', explode('|', $l, 2)); if (count($p) === 2 && $p[0] !== '' && $p[1] !== '') $out[] = $p; }
  return $out;
}
function tiket_baru(): string {
  $a = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $s = '';
  for ($i = 0; $i < 4; $i++) $s .= $a[random_int(0, strlen($a) - 1)];
  return 'FSK-' . date('ym') . '-' . $s;
}
function tgl($d, bool $jam = false): string {
  if (!$d) return '–';
  $bln = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
  $t = strtotime($d);
  return (int)date('j', $t) . ' ' . $bln[(int)date('n', $t)] . ' ' . date('Y', $t) . ($jam ? ', ' . date('H.i', $t) . ' WIB' : '');
}
function hari_sejak($d): int { return max(0, (int)floor((time() - strtotime($d)) / 86400)); }
function pill(string $st): string { return '<span class="pill p-' . e($st) . '">' . e(STATUS[$st] ?? $st) . '</span>'; }
function tambah_riwayat(int $laporan_id, string $oleh, string $teks, ?string $foto = null): void {
  q('INSERT INTO riwayat (laporan_id, oleh, teks, foto, dibuat) VALUES (?, ?, ?, ?, NOW())', [$laporan_id, $oleh, $teks, $foto]);
}
/* Kata kunci untuk mencari laporan serupa */
function toks(string $s): array {
  static $stop = ['yang', 'dan', 'di', 'ke', 'dari', 'tidak', 'sudah', 'belum', 'ada', 'untuk', 'dengan', 'pada', 'sejak', 'sangat', 'sering', 'masih', 'bisa', 'ini', 'itu'];
  return array_values(array_unique(array_filter(preg_split('/\s+/', preg_replace('/[^a-z0-9 ]/', ' ', strtolower($s))), fn($t) => strlen($t) > 2 && !in_array($t, $stop, true))));
}
function laporan_mirip(string $teks, string $kat, int $kecuali = 0): array {
  $a = toks($teks); if (count($a) < 2) return [];
  $rows = q("SELECT l.id, l.tiket, l.judul, l.status, l.kategori, r.nama ruang FROM laporan l LEFT JOIN ruangan r ON r.id = l.ruangan_id
             WHERE l.status IN ('verifikasi','diproses') AND l.id <> ? ORDER BY l.dibuat DESC LIMIT 300", [$kecuali])->fetchAll();
  $hit = [];
  foreach ($rows as $r) {
    $b = toks($r['judul'] . ' ' . $r['ruang']); $n = count(array_intersect($a, $b));
    $sc = $n / max(1, min(count($a), count($b))) + ($r['kategori'] === $kat ? .15 : 0);
    if ($n >= 2 && $sc >= .45) { $r['sc'] = $sc; $hit[] = $r; }
  }
  usort($hit, fn($x, $y) => $y['sc'] <=> $x['sc']);
  return array_slice($hit, 0, 3);
}
/* Notifikasi pengguna: tanggapan pada laporan miliknya (atau laporan tempat laporannya digabung). */
function notifikasi(int $uid, int $limit = 50): array {
  $h = anon_owner_hash($uid);
  return q("SELECT rw.*, l.tiket, l.judul FROM riwayat rw JOIN laporan l ON l.id = rw.laporan_id
    WHERE rw.oleh NOT IN ('Sistem','Pelapor')
      AND (l.user_id = :u1 OR l.owner_hash = :h1 OR l.id IN
        (SELECT digabung_ke FROM laporan WHERE (user_id = :u2 OR owner_hash = :h2) AND digabung_ke IS NOT NULL))
      AND rw.dibuat >= (SELECT MIN(dibuat) FROM laporan WHERE user_id = :u3 OR owner_hash = :h3)
    ORDER BY rw.dibuat DESC, rw.id DESC LIMIT $limit",
    [':u1' => $uid, ':h1' => $h, ':u2' => $uid, ':h2' => $h, ':u3' => $uid, ':h3' => $h])->fetchAll();
}
function notif_belum_dibaca(): int {
  $u = user(); if (!$u || is_admin()) return 0;
  $since = $u['notif_dibaca'] ?: '1970-01-01';
  return count(array_filter(notifikasi((int)$u['id'], 99), fn($n) => $n['dibuat'] > $since));
}
/* Simpan foto unggahan (JPG/PNG/WebP, maks 5 MB). Mengembalikan nama file atau null; error ditulis ke $err. */
function simpan_foto(string $field, ?string &$err): ?string {
  $f = $_FILES[$field] ?? null;
  if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
  if ($f['error'] !== UPLOAD_ERR_OK) { $err = 'Foto gagal diunggah. Coba lagi.'; return null; }
  if ($f['size'] > 5 * 1024 * 1024) { $err = 'Ukuran foto melebihi 5 MB.'; return null; }
  $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
  $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
  if (!$ext) { $err = 'File harus berupa foto JPG, PNG, atau WebP.'; return null; }
  if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0775, true);
  $nama = bin2hex(random_bytes(12)) . '.' . $ext;
  if (!move_uploaded_file($f['tmp_name'], UPLOAD_DIR . '/' . $nama)) { $err = 'Foto tidak bisa disimpan.'; return null; }
  return $nama;
}
function foto_url(?string $f): ?string { return ($f && preg_match('/^[a-f0-9]{24}\.(jpg|png|webp)$/', $f)) ? 'uploads/' . $f : null; }

/* ---------- Tampilan bersama ---------- */
function page_head(string $title, string $active = '', bool $home = false): void {
  $u = user();
  $nav = ['beranda' => ['index.php', 'Beranda'], 'papan' => ['index.php?hal=papan', 'Papan'], 'progres' => ['index.php?hal=progres', 'Progres'],
          'kalender' => ['jadwal.php', 'Kalender'], 'ringkasan' => ['index.php?hal=ringkasan', 'Ringkasan'], 'ruangan' => ['ruangan.php', 'Ruangan'],
          'alur' => ['index.php?hal=alur', 'Alur &amp; bantuan']];
  if (is_admin()) $nav['admin'] = ['admin.php', 'Admin'];
  $ini = $u ? strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim($u['nama'])), 0, 2)))) : '';
  $role = $u ? ($u['role'] === 'owner' ? 'Admin · Pemilik' : ($u['role'] === 'admin' ? 'Admin' . ($u['unit'] ? ' · ' . $u['unit'] : '') : 'Pengguna')) : '';
  $notif = $u ? notif_belum_dibaca() : 0;
  ?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($title) ?> · Suara Fasilkom</title>
<link rel="icon" href="brand/logo.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<script>(function(){var t=null;try{t=localStorage.getItem('sf-theme')}catch(e){}if(t!=='dark'&&t!=='light'){t=window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'}document.documentElement.dataset.theme=t;})();</script>
<style><?php css(); ?></style>
</head>
<body class="<?= $home ? 'is-home' : '' ?> <?= is_admin() ? 'role-admin' : ($u ? 'role-user' : '') ?> <?= isset($_GET['embed']) && $_GET['embed'] === '1' ? 'is-embedded' : '' ?> <?= isset($_GET['embed']) && $_GET['embed'] === 'report' ? 'is-report-embedded' : '' ?>">
<?php if ($u): ?>
<header class="top" id="top">
  <div class="bar">
    <a class="brand" href="index.php"><img src="brand/logo.png" alt="Logo Fasilkom UNSIKA"><span><b>Suara Fasilkom</b><small>Fakultas Ilmu Komputer · Universitas Singaperbangsa Karawang</small></span></a>
    <nav aria-label="Navigasi utama"><?php foreach ($nav as $k => [$href, $label]): ?><a href="<?= $href ?>" class="<?= $active === $k ? 'on' : '' ?>" <?= $active === $k ? 'aria-current="page"' : '' ?>><?= $label ?></a><?php endforeach; ?></nav>
    <div class="tools">
      <?php if (!is_admin()): ?><a class="bell" href="index.php?hal=notif" aria-label="Notifikasi<?= $notif ? " ($notif belum dibaca)" : '' ?>"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 8a6 6 0 1 1 12 0c0 7 3 8 3 8H3s3-1 3-8M10.3 21a2 2 0 0 0 3.4 0"/></svg><?php if ($notif): ?><span class="dot"><?= $notif > 9 ? '9+' : $notif ?></span><?php endif; ?></a><?php endif; ?>
      <div class="umenu">
        <button type="button" class="um-btn" aria-haspopup="menu" aria-expanded="false" aria-controls="umPop"><span class="um-av" aria-hidden="true"><?= e($ini) ?></span><span class="um-name"><?= e($u['nama']) ?></span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></button>
        <div class="um-pop" id="umPop" role="menu" hidden>
          <div class="um-hi"><b>Selamat datang !</b><span class="role-pill <?= is_admin() ? 'admin' : 'user' ?>"><?= e($role) ?></span><small><?= e($u['email']) ?></small></div>
          <a role="menuitem" href="<?= e(basename($_SERVER['REQUEST_URI'] ?? 'index.php')) ?>"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12a9 9 0 0 1-15.5 6.2M3 12a9 9 0 0 1 15.5-6.2M21 4v5h-5M3 20v-5h5"/></svg>Muat Ulang</a>
          <form method="post" action="logout.php"><?= csrf_field() ?><button role="menuitem" type="submit"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 17l-5-5 5-5M5 12h11"/></svg>Keluar</button></form>
        </div>
      </div>
      <button type="button" class="theme-btn" id="themeBtn" aria-label="Ganti mode tampilan"></button>
    </div>
  </div>
</header>
<?php endif; ?>
<?php if ($f = flash()): ?><div class="toast <?= e($f[1]) ?>" role="status"><?= e($f[0]) ?></div><?php endif; ?>
<?php
}

function page_foot(): void {
  ?>
<footer class="foot">
  <div class="foot-in">
    <div class="foot-top">
      <div class="foot-brand">
        <a class="foot-id" href="index.php"><img src="brand/logo.png" alt=""><span><b>Suara Fasilkom</b><small>Transparansi Keluh Kesah &amp; Progres Fasilkom UNSIKA</small></span></a>
        <p>Satu tempat untuk menyampaikan keluhan fasilitas, akademik, dan layanan, lalu memantau perbaikannya secara terbuka sampai selesai.</p>
      </div>
      <nav class="foot-col" aria-labelledby="fJ"><h2 id="fJ">Jelajahi</h2><a href="index.php?hal=papan">Papan laporan</a><a href="index.php?hal=progres">Progres perbaikan</a><a href="jadwal.php">Kalender pemeliharaan</a><a href="index.php?hal=ringkasan">Ringkasan publik</a></nav>
      <nav class="foot-col" aria-labelledby="fB"><h2 id="fB">Bantuan</h2><a href="index.php?hal=alur#langkah">Cara mengajukan keluhan</a><a href="index.php?hal=alur#unit">Unit penanggung jawab</a><a href="index.php?hal=alur#faq">Pertanyaan umum</a><a href="index.php?hal=alur#darurat">Kontak darurat</a></nav>
      <nav class="foot-col" aria-labelledby="fI"><h2 id="fI">Informasi</h2><a href="index.php?hal=alur#tentang">Tentang Suara Fasilkom</a><a href="index.php?hal=alur#privasi">Kebijakan privasi &amp; anonimitas</a><?php if (is_admin()): ?><a href="admin.php?tab=pengaturan">Halaman admin</a><?php endif; ?></nav>
    </div>
    <div class="foot-bot"><span>Suara Fasilkom · Fakultas Ilmu Komputer UNSIKA · © <?= date('Y') ?></span><span>Diperbarui otomatis setiap ada tindak lanjut.</span></div>
  </div>
</footer>
<?php if (user() && !is_admin() && !(isset($_GET['embed']) && $_GET['embed'] === '1') && !(isset($_GET['embed']) && $_GET['embed'] === 'report')): ?>
<div class="complaint-modal" id="complaintModal" hidden aria-hidden="true">
  <div class="complaint-backdrop" data-close-complaint></div>
  <section class="complaint-dialog" role="dialog" aria-modal="true" aria-labelledby="complaintTitle">
    <header class="complaint-modal-head">
      <h2 id="complaintTitle">Ajukan keluhan</h2>
      <button class="complaint-close" type="button" aria-label="Tutup formulir" data-close-complaint>&times;</button>
    </header>
    <iframe class="complaint-frame" id="complaintFrame" title="Formulir ajukan keluhan" src="lapor.php?embed=1"></iframe>
  </section>
</div>
<script>
(function(){
  var modal=document.getElementById('complaintModal'), opener=null, last=null;
  if(!modal)return;
  function openModal(trigger){
    last=document.activeElement;
    var frame=document.getElementById('complaintFrame');
    var prefill=trigger.getAttribute('data-prefill');
    frame.src='lapor.php?embed=1'+(prefill?'&judul='+encodeURIComponent(prefill):'');
    modal.hidden=false; modal.setAttribute('aria-hidden','false');
    document.body.classList.add('modal-open');
    requestAnimationFrame(function(){requestAnimationFrame(function(){modal.classList.add('is-open');});});
    var close=modal.querySelector('.complaint-close'); if(close)close.focus();
  }
  function closeModal(){
    if(modal.hidden)return;
    modal.classList.remove('is-open'); modal.setAttribute('aria-hidden','true');
    document.body.classList.remove('modal-open');
    window.setTimeout(function(){modal.hidden=true;},260);
    if(last&&last.focus)last.focus();
  }
  document.addEventListener('click',function(e){
    var target=e.target;
    if(!target || !target.closest)return;
    var trigger=target.closest('[data-open-complaint]');
    if(trigger){e.preventDefault();openModal(trigger);return;}
    if(target.closest('[data-close-complaint]'))closeModal();
  });
  document.addEventListener('keydown',function(e){if(e.key==='Escape'&&!modal.hidden)closeModal();});
  window.addEventListener('message',function(e){
    if(e.data==='sf-complaint-success'){closeModal();return;}
    if(e.data && e.data.type==='sf-complaint-resize'){
      var frame=document.getElementById('complaintFrame'), dialog=modal.querySelector('.complaint-dialog');
      var max=Math.max(220,Math.floor(window.innerHeight*.92)-66), wanted=Math.max(210,Math.min(Number(e.data.height)||420,max));
      frame.style.height=wanted+'px'; dialog.style.height='auto';
    }
  });
})();
</script>
<?php endif; ?>
<?php if (user() && !(isset($_GET['embed']) && $_GET['embed'] === 'report')): ?>
<div class="report-modal" id="reportModal" hidden aria-hidden="true">
  <div class="report-backdrop" data-close-report></div>
  <section class="report-dialog" role="dialog" aria-modal="true" aria-labelledby="reportModalTitle">
    <header class="report-modal-head"><h2 id="reportModalTitle">Detail laporan</h2><button class="complaint-close" type="button" aria-label="Tutup detail laporan" data-close-report>&times;</button></header>
    <iframe class="report-frame" id="reportFrame" title="Detail laporan" src="about:blank"></iframe>
  </section>
</div>
<script>
(function(){
  var modal=document.getElementById('reportModal'), frame=document.getElementById('reportFrame'), last=null, closeTimer=null;
  if(!modal||!frame)return;
  function isReportUrl(href){return /(?:index|admin)\.php\?(?:[^#]*&)?(?:tiket|kelola)=/.test(href||'');}
  function openReport(href){
    last=document.activeElement; clearTimeout(closeTimer);
    var url=new URL(href,window.location.href);
    url.searchParams.set('embed','report');
    frame.src=url.toString();
    modal.hidden=false; modal.setAttribute('aria-hidden','false'); document.body.classList.add('report-open');
    requestAnimationFrame(function(){requestAnimationFrame(function(){modal.classList.add('is-open');});});
    var c=modal.querySelector('[data-close-report]'); if(c)c.focus();
  }
  function closeReport(){
    if(modal.hidden)return;
    modal.classList.remove('is-open'); modal.setAttribute('aria-hidden','true'); document.body.classList.remove('report-open');
    closeTimer=setTimeout(function(){modal.hidden=true;frame.src='about:blank';},260);
    if(last&&last.focus)last.focus();
  }
  document.addEventListener('click',function(e){
    var a=e.target.closest&&e.target.closest('a[href]');
    if(a && isReportUrl(a.getAttribute('href'))){e.preventDefault();openReport(a.href);return;}
    if(e.target.closest&&e.target.closest('[data-close-report]'))closeReport();
  });
  document.addEventListener('keydown',function(e){if(e.key==='Escape'&&!modal.hidden)closeReport();});
  window.addEventListener('message',function(e){
    if(e.origin!==window.location.origin||!e.data)return;
    if(e.data.type==='sf-open-report'&&isReportUrl(e.data.url)){openReport(e.data.url);}
  });
})();
</script>
<?php endif; ?>
<script><?php js(); ?></script>
</body>
</html>
<?php
}

function css(): void { ?>
:root{
  --bg:#ffffff; --panel:#ffffff; --panel-2:#f5f5f5; --ink:#000000; --muted:#000000; --line:#d6d6d6;
  --brand:#808080; --brand-ink:#ffffff; --soft:#f0f0f0;
  --ok:#17845a; --ok-soft:#dcf2e8; --warn:#b26100; --warn-soft:#fdebd3; --info:#1f63c2; --info-soft:#dde8f8; --crit:#c8261e; --crit-soft:#fbe0de; --gray:#5e5e5e; --gray-soft:#ececec;
  --star:#e3a600; --f:"Inter","Segoe UI",system-ui,sans-serif; color-scheme:light;
}
@media (prefers-color-scheme:dark){ :root:not([data-theme="light"]){
  --bg:#141414; --panel:#1e1e1e; --panel-2:#262626; --ink:#ffffff; --muted:#ffffff; --line:#3a3a3a; --soft:#2c2c2c;
  --ok:#53d39b; --ok-soft:#143527; --warn:#f3ab55; --warn-soft:#3a2914; --info:#7eaef2; --info-soft:#18273f; --crit:#f2867f; --crit-soft:#3e1b1a; --gray:#c4c4c4; --gray-soft:#2c2c2c; --star:#f5c04a; color-scheme:dark }}
:root[data-theme="dark"]{
  --bg:#141414; --panel:#1e1e1e; --panel-2:#262626; --ink:#ffffff; --muted:#ffffff; --line:#3a3a3a; --soft:#2c2c2c;
  --ok:#53d39b; --ok-soft:#143527; --warn:#f3ab55; --warn-soft:#3a2914; --info:#7eaef2; --info-soft:#18273f; --crit:#f2867f; --crit-soft:#3e1b1a; --gray:#c4c4c4; --gray-soft:#2c2c2c; --star:#f5c04a; color-scheme:dark }
*{box-sizing:border-box}
html{scroll-padding-top:96px}
body{margin:0; background:var(--bg); color:var(--ink); font:15px/1.55 var(--f); display:flex; flex-direction:column; min-height:100vh; min-height:100svh}
h1,h2,h3{margin:0; line-height:1.2; text-wrap:balance; font-weight:800; letter-spacing:-.01em}
p{margin:0} a{color:inherit} img{max-width:100%}
button,input,select,textarea{font:inherit; color:inherit}
:focus-visible{outline:2px solid var(--brand); outline-offset:2px}
[hidden]{display:none!important}
.wrap{width:100%; max-width:1520px; margin:0 auto; padding-left:max(16px,env(safe-area-inset-left,0px)); padding-right:max(16px,env(safe-area-inset-right,0px))}
main.wrap{flex:1 0 auto; padding-top:28px; display:grid; gap:24px; align-content:start}

/* Header */
.top{position:sticky; top:0; z-index:20; background:var(--brand); color:#fff; box-shadow:0 2px 12px -6px rgba(0,0,0,.4)}
body.is-home .top{position:absolute; left:0; right:0; background:linear-gradient(rgba(128,128,128,.55),rgba(128,128,128,0)); box-shadow:none}
body.role-admin .top{border-bottom:4px solid #fff}
.top .bar{max-width:1520px; margin:0 auto; display:flex; align-items:center; gap:14px; padding:10px max(16px,env(safe-area-inset-right,0px)) 10px max(16px,env(safe-area-inset-left,0px))}
.brand{display:flex; align-items:center; gap:10px; text-decoration:none; color:#fff; min-width:0; flex:0 1 auto}
.brand img{width:46px; height:46px; object-fit:contain; flex:none}
.brand span{min-width:0; overflow:hidden}
.brand b{display:block; font-size:16px; white-space:nowrap}
.brand small{display:block; font-size:11.5px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis}
.top nav{display:flex; gap:2px; margin-left:auto; flex:none}
.top nav a{color:#fff; text-decoration:none; font-weight:600; font-size:14px; padding:8px 11px; border-radius:8px; opacity:.92; white-space:nowrap}
.top nav a:hover{background:rgba(255,255,255,.12); opacity:1}
.top nav a.on{background:rgba(255,255,255,.2); opacity:1}
.tools{display:flex; align-items:center; gap:10px; flex:none; padding-left:12px; border-left:1px solid rgba(255,255,255,.35)}
.bell{position:relative; display:grid; place-items:center; width:38px; height:38px; border-radius:10px; color:#fff}
.bell:hover{background:rgba(255,255,255,.12)}
.bell .dot{position:absolute; top:-3px; right:-4px; min-width:19px; height:19px; padding:0 5px; border-radius:99px; background:#c8261e; color:#fff; font-size:11px; font-weight:800; display:grid; place-items:center}
.umenu{position:relative}
.um-btn{display:inline-flex; align-items:center; gap:9px; background:none; border:0; color:#fff; cursor:pointer; padding:4px 6px; border-radius:10px; font-weight:700; font-size:13.5px; letter-spacing:.03em; text-transform:uppercase; max-width:240px}
.um-btn:hover{background:rgba(255,255,255,.12)}
.um-av{width:34px; height:34px; border-radius:50%; background:#fff; color:#808080; display:grid; place-items:center; font-size:12.5px; font-weight:800; flex:none}
.um-name{white-space:nowrap; overflow:hidden; text-overflow:ellipsis}
.um-btn svg{transition:transform .2s}
.um-btn[aria-expanded="true"] svg{transform:rotate(180deg)}
.um-pop{position:absolute; right:0; top:calc(100% + 10px); min-width:240px; background:var(--panel); color:var(--ink); border:1px solid var(--line); border-radius:12px; box-shadow:0 18px 40px -16px rgba(0,0,0,.45); padding:8px; z-index:40; display:grid; gap:2px}
.um-hi{display:grid; gap:6px; justify-items:start; padding:10px 12px 12px; border-bottom:1px solid var(--line); margin-bottom:4px}
.um-hi small{font-size:12px; opacity:.8; word-break:break-all}
.um-pop [role=menuitem]{display:flex; align-items:center; gap:12px; width:100%; background:none; border:0; text-align:left; padding:10px 12px; border-radius:8px; font-size:15px; color:var(--ink); cursor:pointer; text-decoration:none}
.um-pop [role=menuitem]:hover{background:var(--panel-2)}
.um-pop form{margin:0}
.role-pill{display:inline-flex; align-items:center; gap:6px; border-radius:999px; padding:3px 10px; font-size:11px; font-weight:800; letter-spacing:.06em; text-transform:uppercase}
.role-pill::before{content:""; width:7px; height:7px; border-radius:50%; background:currentColor}
.role-pill.admin{background:#808080; color:#fff} .role-pill.user{border:1px solid #808080}
.theme-btn{display:inline-flex; align-items:center; gap:6px; border:1px solid rgba(255,255,255,.4); background:rgba(255,255,255,.08); color:#fff; border-radius:999px; padding:6px 12px; font-size:12.5px; font-weight:600; cursor:pointer; white-space:nowrap; flex:none}
.theme-btn:hover{background:rgba(255,255,255,.18)}
@media (max-width:1440px){ .brand small{display:none} .top nav a{padding:8px 8px; font-size:13.5px} .theme-btn .tlabel{position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0 0 0 0)} .theme-btn{padding:7px 9px} .um-name{max-width:120px} }
@media (max-width:1180px){
  .top .bar{flex-wrap:wrap; gap:8px 10px}
  .top nav{order:3; width:100%; margin-left:0; overflow-x:auto}
  .tools{margin-left:auto}
}
@media (max-width:560px){ .tools{border-left:0; padding-left:0} .um-name{display:none} }

/* Hero beranda — editorial premium dengan animasi intro dan profil */
.hero{position:relative;isolation:isolate;min-height:100vh;min-height:100svh;display:grid;place-items:center;text-align:left;color:#fff;padding:132px 24px 104px;overflow:hidden;
  background:#17191d center 35%/cover no-repeat;background-image:linear-gradient(105deg,rgba(8,13,23,.88),rgba(12,18,29,.73) 54%,rgba(12,18,29,.58)),url("brand/gedung.jpg")}
.hero::before,.hero::after{content:"";position:absolute;z-index:-1;pointer-events:none;border-radius:50%;filter:blur(2px)}
.hero::before{width:520px;height:520px;right:-120px;top:-180px;background:radial-gradient(circle,rgba(213,177,112,.22),transparent 68%);animation:heroAura 9s ease-in-out infinite alternate}
.hero::after{width:420px;height:420px;left:-180px;bottom:-240px;background:radial-gradient(circle,rgba(80,130,190,.18),transparent 70%);animation:heroAura 11s ease-in-out infinite alternate-reverse}
.hero-in{width:100%;max-width:1280px;display:block}
.hero-layout{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(300px,.72fr);gap:clamp(32px,6vw,88px);align-items:center}
.hero-copy{display:grid;gap:18px;justify-items:start;max-width:760px}
.hero .welcome{font-size:clamp(12px,1.1vw,15px);font-weight:800;letter-spacing:.24em;text-transform:uppercase;color:#e7c990;text-shadow:0 1px 12px rgba(0,0,0,.4);animation:heroRise .8s .08s both}
.hero h1{font-size:clamp(36px,5.1vw,68px);line-height:1.08;max-width:16ch;text-shadow:0 5px 24px rgba(0,0,0,.4);letter-spacing:-.045em}
.hero h1 span{display:block;overflow:hidden;animation:heroTitle .95s cubic-bezier(.16,1,.3,1) both}
.hero h1 span:nth-child(2){animation-delay:.16s;color:#f0d6a5}
.hero .title-rule{width:112px;height:3px;border-radius:4px;background:linear-gradient(90deg,#e7c990,rgba(231,201,144,0));transform-origin:left;animation:ruleGrow 1s .55s both}
.hero .lines{display:grid;gap:5px;font-size:clamp(15px,1.45vw,18px);color:rgba(255,255,255,.88);text-shadow:0 1px 8px rgba(0,0,0,.35);animation:heroRise .8s .42s both}
.hero .lines b{font-weight:750;color:#fff}
.hero .cta{display:flex;gap:12px;flex-wrap:wrap;justify-content:flex-start;margin-top:8px;animation:heroRise .8s .56s both}
.hbtn{display:inline-flex;align-items:center;gap:10px;padding:14px 22px;border-radius:9px;font-weight:750;font-size:15px;text-decoration:none;border:1px solid transparent;transition:transform .25s,box-shadow .25s,background .25s}
.hbtn:hover{transform:translateY(-3px);box-shadow:0 12px 30px rgba(0,0,0,.24)}
.hbtn.solid{background:linear-gradient(135deg,#fff,#f1e8d8);color:#24211d}
.hbtn.ghost{color:#fff;border-color:rgba(255,255,255,.58);background:rgba(255,255,255,.07);backdrop-filter:blur(8px)}
.dean-feature{position:relative;justify-self:center;width:min(100%,390px);padding:10px 10px 0;border-radius:190px 190px 22px 22px;background:linear-gradient(145deg,#fffdf8,#e9dfce);box-shadow:0 28px 85px rgba(0,0,0,.42),0 0 0 1px rgba(239,214,169,.48);overflow:hidden;animation:portraitArrive 1.1s .25s cubic-bezier(.16,1,.3,1) both}
.dean-feature::before{content:"";position:absolute;inset:9px 9px 0;border:1px solid rgba(170,133,75,.45);border-radius:182px 182px 15px 15px;pointer-events:none;z-index:2}
.dean-feature img{display:block;width:100%;height:clamp(340px,49vh,510px);object-fit:contain;object-position:center bottom;mix-blend-mode:multiply}
.dean-caption{position:relative;z-index:3;margin:-2px 0 0;padding:16px 18px 18px;background:linear-gradient(120deg,#211f1b,#383025);color:#fff;text-align:center;border-top:1px solid rgba(224,194,139,.65)}
.dean-caption small{display:block;color:#e5c78e;font-size:10px;letter-spacing:.2em;font-weight:800;text-transform:uppercase;margin-bottom:5px}
.dean-caption strong{display:block;font-family:Georgia,serif;font-size:clamp(15px,1.5vw,19px);font-weight:600;line-height:1.3}
.hero-down{position:absolute;left:50%;bottom:calc(22px + env(safe-area-inset-bottom,0px));transform:translateX(-50%);color:#fff;text-decoration:none;display:grid;justify-items:center;gap:6px;font-size:11px;letter-spacing:.2em;text-transform:uppercase;font-weight:700;opacity:.82;animation:heroRise .9s .9s both}
.hero-down svg{animation:downFloat 1.8s ease-in-out infinite}
@keyframes heroRise{from{opacity:0;transform:translateY(22px)}to{opacity:1;transform:translateY(0)}}
@keyframes heroTitle{from{opacity:0;transform:translateY(105%);filter:blur(8px)}to{opacity:1;transform:translateY(0);filter:blur(0)}}
@keyframes ruleGrow{from{transform:scaleX(0);opacity:0}to{transform:scaleX(1);opacity:1}}
@keyframes portraitArrive{from{opacity:0;transform:translateY(30px) scale(.96);filter:blur(8px)}to{opacity:1;transform:translateY(0) scale(1);filter:blur(0)}}
@keyframes heroAura{from{transform:translate3d(0,0,0) scale(.9)}to{transform:translate3d(-24px,22px,0) scale(1.12)}}
@keyframes downFloat{0%,100%{transform:translateY(0)}50%{transform:translateY(6px)}}
@media(max-width:850px){.hero{padding:118px 20px 92px}.hero-layout{grid-template-columns:1fr;gap:30px}.hero-copy{justify-items:center;text-align:center;margin:0 auto}.hero h1{max-width:18ch}.hero .title-rule{transform-origin:center}.hero .cta{justify-content:center}.dean-feature{width:min(76vw,320px)}.dean-feature img{height:330px}}
@media(max-width:520px){.hero{padding:112px 16px 90px}.hero h1{font-size:clamp(34px,10vw,48px)}.dean-feature{width:min(82vw,290px)}.dean-feature img{height:300px}.dean-caption{padding:13px 12px 15px}.hero-down{font-size:9px;white-space:nowrap}}
@media(prefers-reduced-motion:reduce){.hero::before,.hero::after,.hero .welcome,.hero h1 span,.hero .title-rule,.hero .lines,.hero .cta,.dean-feature,.hero-down,.hero-down svg{animation:none!important;filter:none!important;transform:none!important;opacity:1!important}}

/* Komponen umum */
.page-head{display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; justify-content:space-between}
.page-head h1{font-size:28px} .page-head p{margin-top:4px; max-width:70ch}
.eyebrow{font-size:12px; letter-spacing:.18em; text-transform:uppercase; font-weight:700}
.panel{background:var(--panel); border:1px solid var(--line); border-radius:16px; padding:20px; min-width:0}
.panel > h2{font-size:18px} .psub{margin:4px 0 14px; font-size:13.5px}
.grid2{display:grid; grid-template-columns:minmax(0,1.5fr) minmax(0,1fr); gap:20px; align-items:start}
.grid-even{display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:20px; align-items:start}
@media (max-width:900px){ .grid2, .grid-even{grid-template-columns:minmax(0,1fr)} }
.btn{display:inline-flex; align-items:center; justify-content:center; gap:8px; padding:10px 16px; border-radius:12px; border:1px solid var(--line); background:var(--panel); cursor:pointer; font-weight:700; color:var(--ink); text-decoration:none; white-space:nowrap; position:relative; overflow:hidden}
.btn:hover{border-color:var(--ink)}
.btn.primary{background:var(--brand); border-color:var(--brand); color:#fff}
.btn.danger{color:var(--crit); border-color:var(--crit)}
.btn.sm{padding:6px 12px; font-size:13px; border-radius:10px}
.btn.on{background:var(--soft); border-color:var(--brand)}
.btn:disabled{opacity:.55; cursor:not-allowed}
.btn-row{display:flex; gap:8px; flex-wrap:wrap; align-items:center}
.pill{font-size:12px; font-weight:700; padding:3px 10px; border-radius:99px; display:inline-block; white-space:nowrap}
.p-menunggu{background:var(--gray-soft); color:var(--gray)} .p-verifikasi{background:var(--info-soft); color:var(--info)} .p-diproses{background:var(--warn-soft); color:var(--warn)} .p-selesai{background:var(--ok-soft); color:var(--ok)} .p-ditolak{background:var(--crit-soft); color:var(--crit)} .p-digabung, .p-dibatalkan{background:var(--gray-soft); color:var(--gray)}
.tag{font-size:11px; font-weight:700; padding:2px 8px; border-radius:6px; background:var(--soft); white-space:nowrap}
.tag.hi{background:var(--crit-soft); color:var(--crit)} .tag.late{background:var(--warn-soft); color:var(--warn)} .tag.mine{background:var(--soft); border:1px solid var(--brand)} .tag.hid{border:1px dashed var(--ink)} .tag.ok{background:var(--ok-soft); color:var(--ok)} .tag.demo{background:var(--gray-soft); color:var(--gray)}
.empty{padding:26px 10px; text-align:center; display:grid; gap:8px; justify-items:center}
.muted{opacity:.75}
.mono{font-variant-numeric:tabular-nums}
.stars-txt{color:var(--star); letter-spacing:1px}

/* Statistik & daftar */
.sec-head{display:grid; gap:8px; padding-bottom:16px; border-bottom:3px double var(--ink)}
.sec-head h2{font-size:clamp(26px,3.4vw,40px)}
.feat{display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); border-bottom:1px solid var(--ink)}
.feat > div{padding:8px 22px 22px; display:grid; gap:4px; align-content:start}
.feat > div + div{border-left:1px solid var(--line)}
.feat > div:first-child{padding-left:0}
.feat .k{font-size:12px; text-transform:uppercase; letter-spacing:.1em; font-weight:700}
.feat .v{font-size:clamp(34px,4vw,52px); font-weight:800; line-height:1.08}
.feat .d{font-size:13px}
@media (max-width:900px){ .feat{grid-template-columns:repeat(2,minmax(0,1fr))} .feat > div:nth-child(3){border-left:0; padding-left:0} .feat > div:nth-child(n+3){border-top:1px solid var(--line); padding-top:16px} }
.queue{display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:10px}
.qbox{display:grid; gap:2px; background:var(--panel); border:1px solid var(--line); border-radius:14px; padding:12px 14px; text-decoration:none}
.qbox .k{font-size:12.5px} .qbox .v{font-size:24px; font-weight:800}
.qbox.alert{border-color:var(--warn); background:var(--warn-soft)} .qbox.alert .v{color:var(--warn)}
.qbox.on{outline:2px solid var(--brand); outline-offset:1px}
@media (max-width:1100px){ .queue{grid-template-columns:repeat(3,minmax(0,1fr))} }
@media (max-width:640px){ .queue{grid-template-columns:repeat(2,minmax(0,1fr))} }
.toolbar{display:flex; gap:10px; flex-wrap:wrap; align-items:center}
.search{position:relative; flex:1 1 260px; min-width:0}
.search input{width:100%; padding:10px 14px 10px 40px; border-radius:12px; border:1px solid var(--line); background:var(--panel)}
.search input[type="search"]{padding-left:42px!important;padding-right:14px!important;min-width:0;box-sizing:border-box;line-height:1.4}
.search svg{z-index:2;width:16px;height:16px}
.search svg{position:absolute; left:14px; top:50%; transform:translateY(-50%); pointer-events:none}
select, input[type=text], input[type=email], input[type=password], input[type=date], input[type=search], input[type=number], textarea{padding:10px 12px; border-radius:12px; border:1px solid var(--line); background:var(--panel); max-width:100%}
textarea{min-height:110px; resize:vertical; width:100%}
.chips{display:flex; gap:6px; flex-wrap:wrap}
.chip{padding:6px 12px; border-radius:99px; border:1px solid var(--line); text-decoration:none; font-size:13px; font-weight:600}
.chip .n{margin-left:4px; font-size:11.5px; opacity:.8}
.chip.on{background:var(--ink); color:var(--bg); border-color:var(--ink)}
.seg{display:inline-flex; border:1px solid var(--line); border-radius:12px; padding:3px; background:var(--panel)}
.seg a{padding:6px 12px; border-radius:9px; text-decoration:none; font-weight:600; font-size:13px}
.seg a.on{background:var(--soft); box-shadow:inset 0 0 0 1px var(--brand)}
.list{border:1px solid var(--line); border-radius:16px; overflow:hidden; background:var(--panel)}
.row{display:grid; grid-template-columns:5px minmax(0,1fr) auto; gap:14px; padding:14px 16px 14px 0; border-bottom:1px solid var(--line); text-decoration:none; align-items:center; position:relative}
.row:last-child{border-bottom:0}
.row:hover{background:var(--panel-2)}
.row .stripe{align-self:stretch; border-radius:0 3px 3px 0}
.row h3{font-size:15.5px; font-weight:700; overflow-wrap:anywhere; letter-spacing:0}
.row h3 a{text-decoration:none} .row h3 a::after{content:""; position:absolute; inset:0}
.row .meta{display:flex; gap:8px; flex-wrap:wrap; font-size:12.5px; align-items:center; margin-top:3px}
.row .right{display:grid; gap:6px; justify-items:end; font-size:12.5px; position:relative; z-index:1}
.row form{margin:0}
@media (max-width:640px){ .row{grid-template-columns:5px minmax(0,1fr)} .row .right{grid-column:2; justify-items:start; grid-auto-flow:column} }
.c-menunggu{background:var(--gray)} .c-verifikasi{background:var(--info)} .c-diproses{background:var(--warn)} .c-selesai{background:var(--ok)} .c-ditolak{background:var(--crit)} .c-digabung, .c-dibatalkan{background:var(--line)}
.board{display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:12px}
.col{background:var(--panel-2); border:1px solid var(--line); border-radius:16px; padding:10px; display:flex; flex-direction:column; gap:10px; min-width:0}
.col-h{display:flex; align-items:center; gap:8px; font-weight:700; font-size:13.5px; padding:2px 4px}
.col-h .sd{width:9px; height:9px; border-radius:50%}
.col-h .n{margin-left:auto; font-size:12px; background:var(--panel); border:1px solid var(--line); padding:0 8px; border-radius:99px}
.card{background:var(--panel); border:1px solid var(--line); border-radius:12px; padding:12px; display:grid; gap:7px; text-decoration:none}
.card:hover{border-color:var(--ink)}
.card h3{font-size:14px; font-weight:700; overflow-wrap:anywhere; letter-spacing:0}
.card .meta{font-size:12px; display:flex; gap:6px; flex-wrap:wrap; align-items:center}
.empty-col{font-size:12.5px; text-align:center; padding:18px 8px; border:1px dashed var(--line); border-radius:12px}
@media (max-width:1000px){ .board{grid-template-columns:minmax(0,1fr)} }
.hint{display:flex; justify-content:space-between; gap:12px; align-items:center; flex-wrap:wrap; border-radius:10px; padding:10px 14px; font-size:13.5px; border-left:3px solid currentColor}
.hint.found{background:var(--ok-soft); color:var(--ok)} .hint.none{background:var(--warn-soft); color:var(--warn)}

/* Progres, unit, ringkasan */
.prog{display:flex; align-items:center; gap:10px; min-width:150px}
.track-bar{flex:1; height:8px; background:var(--panel-2); border:1px solid var(--line); border-radius:99px; overflow:hidden}
.track-bar span{display:block; height:100%; background:var(--brand)}
.track-bar span.late{background:var(--warn)} .track-bar span.ok{background:var(--ok)}
.late-t{color:var(--warn); font-weight:700}
.unit-list{display:grid; gap:16px}
.unit .r{display:flex; justify-content:space-between; gap:8px; font-weight:700}
.stack{display:flex; height:10px; border-radius:99px; overflow:hidden; background:var(--panel-2); margin:6px 0}
.stack i{display:block; height:100%}
.legend{display:flex; gap:14px; flex-wrap:wrap; font-size:12.5px}
.legend i{display:inline-block; width:10px; height:10px; border-radius:50%; margin-right:6px}
.bars{display:grid; gap:12px}
.bars .b{display:grid; grid-template-columns:minmax(0,210px) minmax(0,1fr) auto; gap:12px; align-items:center; font-size:13.5px}
.bars .tr2{height:16px; background:var(--panel-2); border-radius:6px; position:relative; overflow:hidden}
.bars .tr2 i{position:absolute; left:0; top:0; bottom:0; border-radius:6px}
.bars .tr2 i.in{background:var(--line)} .bars .tr2 i.done{background:var(--brand)}
@media (max-width:640px){ .bars .b{grid-template-columns:minmax(0,1fr) auto} .bars .tr2{grid-column:1/-1; grid-row:2} }
.fixes{display:grid; grid-template-columns:repeat(auto-fill,minmax(230px,1fr)); gap:14px}
.fix{border:1px solid var(--line); border-radius:14px; overflow:hidden; background:var(--panel-2); text-decoration:none; display:grid}
.fix img{width:100%; height:140px; object-fit:cover; display:block}
.fix div{padding:10px 12px; display:grid; gap:3px}

/* Detail laporan */
.track{display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:6px}
.track div{font-size:12px; font-weight:600}
.track i{display:block; height:7px; border-radius:99px; background:var(--panel-2); border:1px solid var(--line); margin-bottom:6px}
.track .done i{background:var(--brand); border-color:var(--brand)}
.kv{display:grid; grid-template-columns:auto minmax(0,1fr); gap:8px 18px; margin:0; font-size:14px}
.kv dt{font-weight:600} .kv dd{margin:0; overflow-wrap:anywhere}
.tl{list-style:none; margin:0; padding:0}
.tl li{display:grid; grid-template-columns:18px minmax(0,1fr); gap:10px; padding-bottom:16px; position:relative}
.tl li::before{content:""; width:10px; height:10px; border-radius:50%; border:2px solid var(--brand); background:var(--panel); margin-top:5px; z-index:1}
.tl li::after{content:""; position:absolute; left:5px; top:16px; bottom:0; width:2px; background:var(--line)}
.tl li:last-child::after{display:none}
.tl .w{font-size:12px; display:block}
.photo{display:block; border-radius:12px; overflow:hidden; border:1px solid var(--line); max-width:560px; cursor:zoom-in; padding:0; background:none}
.photo img{display:block; width:100%; max-height:360px; object-fit:cover}
.lightbox{position:fixed; inset:0; z-index:80; background:rgba(8,9,20,.9); display:grid; place-items:center; padding:16px; cursor:zoom-out}
.lightbox img{max-width:100%; max-height:100%; border-radius:10px}
.box{border:1px solid var(--line); background:var(--panel-2); border-radius:14px; padding:16px; display:grid; gap:12px}
.box.warn{border-color:var(--warn); background:var(--warn-soft)} .box.ok{border-color:var(--ok); background:var(--ok-soft)}
.box h3{font-size:15px}
.note{background:var(--panel); border:1px solid var(--line); border-radius:10px; padding:10px 12px; font-size:13.5px}
.note small{display:block; font-size:11.5px; opacity:.8}
.stars{display:flex; gap:2px; flex-direction:row-reverse; justify-content:flex-end}
.stars input{position:absolute; opacity:0; pointer-events:none}
.stars label{font-size:28px; line-height:1; color:var(--line); cursor:pointer; font-weight:400}
.stars input:checked ~ label, .stars label:hover, .stars label:hover ~ label{color:var(--star)}
.stars input:focus-visible + label{outline:2px solid var(--brand); border-radius:4px}

/* Formulir */
form.stack{display:grid; gap:14px; width:100%; min-width:0; height:auto; min-height:0; overflow:visible}
/* Pastikan panel formulir wizard tidak terpotong oleh tinggi/overflow elemen induk. */
.complaint-panel{height:auto; min-height:0; max-height:none; overflow:visible; align-self:start}
#lapor .wz{display:grid; min-width:0; min-height:0; height:auto; overflow:visible}
#lapor .wz[hidden]{display:none !important}
#lapor .wz:not([hidden]){display:grid}
#lapor .wz-nav{min-width:0; flex-wrap:wrap}
label{display:grid; gap:6px; font-size:13.5px; font-weight:700}
label .h, .h{font-weight:500; font-size:12.5px}
.two{display:grid; grid-template-columns:1fr 1fr; gap:12px}
@media (max-width:640px){ .two{grid-template-columns:1fr} }
.chk{display:flex; gap:8px; font-weight:500; align-items:flex-start}
.err{color:var(--crit); font-size:13px; font-weight:600; min-height:1em}
.cat-grid{display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px}
@media (max-width:640px){ .cat-grid{grid-template-columns:1fr} }
.cat-grid input{position:absolute; opacity:0; pointer-events:none}
.cat-grid label{font-weight:600; padding:12px; border:1px solid var(--line); border-radius:12px; cursor:pointer; background:var(--panel-2); display:block}
.cat-grid label small{display:block; font-weight:500; font-size:12px}
.cat-grid input:checked + label{border-color:var(--brand); background:var(--soft); box-shadow:inset 0 0 0 1px var(--brand)}
.cat-grid input:focus-visible + label{outline:2px solid var(--brand); outline-offset:2px}
.wz-steps{list-style:none; margin:0 0 8px; padding:0; display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:6px}
.wz-steps li{display:grid; justify-items:center; gap:6px; font-size:12.5px; font-weight:600; text-align:center; position:relative}
.wz-steps li span{width:30px; height:30px; border-radius:50%; display:grid; place-items:center; border:2px solid var(--line); background:var(--panel); font-weight:800; z-index:1; transition:background-color .25s, border-color .25s}
.wz-steps li::before{content:""; position:absolute; top:15px; right:50%; width:100%; height:2px; background:var(--line)}
.wz-steps li:first-child::before{display:none}
.wz-steps li.done span, .wz-steps li.on span{background:var(--brand); border-color:var(--brand); color:#fff}
.wz-steps li.done::before, .wz-steps li.on::before{background:var(--brand)}
.wz{display:grid; gap:14px}
.wz-nav{display:flex; gap:10px; border-top:1px solid var(--line); padding-top:14px}
.wz-nav .grow{flex:1}
.review{display:grid; grid-template-columns:auto minmax(0,1fr); gap:8px 16px; margin:0; background:var(--panel-2); border:1px solid var(--line); border-radius:12px; padding:14px}
.review dt{font-weight:600} .review dd{margin:0; overflow-wrap:anywhere}
.drop{position:relative; display:flex; align-items:center; gap:12px; padding:14px; border:1.5px dashed var(--line); border-radius:12px; background:var(--panel-2); cursor:pointer; font-weight:600; font-size:13.5px}
.drop:hover, .drop.over{border-color:var(--brand); background:var(--soft)}
.drop input{position:absolute; inset:0; opacity:0; cursor:pointer; width:100%}
.drop small{display:block; font-weight:500; font-size:12px}
.preview{position:relative; border-radius:12px; overflow:hidden; border:1px solid var(--line); max-width:420px}
.preview img{display:block; width:100%; max-height:220px; object-fit:cover}
.preview button{position:absolute; top:8px; right:8px; border:0; background:rgba(15,17,35,.78); color:#fff; padding:6px 10px; border-radius:8px; cursor:pointer; font-weight:700; font-size:12px}
.dup{display:grid; gap:8px}
.dup-item{display:flex; justify-content:space-between; gap:10px; align-items:center; padding:10px 12px; border:1px solid var(--line); border-radius:10px; background:var(--panel)}
.dup-item a{text-decoration:none} .dup-item form{margin:0}

/* Kalender */
.cal-scroll{overflow-x:auto}
.cal{display:grid; grid-template-columns:repeat(7,minmax(0,1fr)); gap:1px; background:var(--line); border:1px solid var(--line); border-radius:14px; overflow:hidden; min-width:640px}
.cal .dow{background:var(--panel-2); font-size:11.5px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; padding:8px}
.cal .day{background:var(--panel); min-height:100px; padding:6px; display:flex; flex-direction:column; gap:4px; min-width:0}
.cal .day.out{background:var(--panel-2)} .cal .day.out .dn{opacity:.45}
.cal .dn{font-size:12px; font-weight:600}
.cal .today .dn{background:var(--brand); color:#fff; border-radius:6px; padding:0 6px; align-self:flex-start}
.ev{display:block; font-size:11.5px; font-weight:700; padding:3px 6px; border-radius:6px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; text-decoration:none}
.ev-ringan{background:var(--info-soft); color:var(--info)} .ev-sedang{background:var(--warn-soft); color:var(--warn)} .ev-berat{background:var(--crit-soft); color:var(--crit)} .ev-selesai{background:var(--ok-soft); color:var(--ok)} .ev-batal{background:var(--gray-soft); text-decoration:line-through}

table{width:100%; border-collapse:collapse}
.tbl-wrap{overflow-x:auto}
th{font-size:11.5px; text-transform:uppercase; letter-spacing:.06em; text-align:left; padding:0 10px 10px 0; border-bottom:1px solid var(--line)}
td{padding:12px 10px 12px 0; border-bottom:1px solid var(--line); vertical-align:middle; font-size:14px}
td small{display:block; font-size:12.5px}
tr:last-child td{border-bottom:0}
details{border-bottom:1px solid var(--line); padding:12px 0}
summary{cursor:pointer; font-weight:700}
details p{margin-top:8px; max-width:70ch}
.steps{list-style:none; margin:0; padding:0; display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; counter-reset:s}
.steps li{background:var(--panel); border:1px solid var(--line); border-radius:16px; padding:16px; counter-increment:s; display:grid; gap:6px; align-content:start}
.steps li::before{content:counter(s); width:28px; height:28px; border-radius:50%; background:var(--soft); display:grid; place-items:center; font-weight:800}
@media (max-width:900px){ .steps{grid-template-columns:repeat(2,minmax(0,1fr))} }
@media (max-width:560px){ .steps{grid-template-columns:1fr} }
.emerg{border:1px solid var(--crit); background:var(--crit-soft); border-radius:16px; padding:18px; display:grid; gap:10px}
.emerg h2{color:var(--crit); font-size:17px}
.emerg ul{margin:0; padding:0; list-style:none; display:grid; gap:6px}
.emerg li{display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; background:var(--panel); border-radius:10px; padding:8px 12px; font-size:14px}
.emerg li span{user-select:all}
.notif-list{display:grid; gap:8px}
.notif-list a{display:grid; gap:2px; border:1px solid var(--line); background:var(--panel); border-radius:12px; padding:12px 14px; text-decoration:none}
.notif-list a.unread{border-color:var(--brand); background:var(--soft)}
.notif-list small{font-size:12px}

/* Footer */
.foot{background:var(--brand); color:#fff; margin-top:clamp(72px,9vw,128px); flex-shrink:0}
.foot-in{max-width:1520px; margin:0 auto; padding:44px max(16px,env(safe-area-inset-right,0px)) calc(20px + env(safe-area-inset-bottom,0px)) max(16px,env(safe-area-inset-left,0px))}
.foot-top{display:grid; grid-template-columns:minmax(0,1.6fr) repeat(3,minmax(0,1fr)); gap:32px; padding-bottom:32px}
.foot-id{display:flex; align-items:center; gap:12px; color:#fff; text-decoration:none}
.foot-id img{width:46px; height:46px; object-fit:contain}
.foot-id b{display:block; font-size:18px} .foot-id small{display:block; font-size:12px}
.foot-brand p{margin-top:16px; max-width:44ch; font-size:14.5px}
.foot-col{display:grid; gap:12px; align-content:start}
.foot-col h2{font-size:14.5px; margin-bottom:4px}
.foot-col a{color:#fff; text-decoration:none; font-size:14.5px; opacity:.92; justify-self:start}
.foot-col a:hover{opacity:1; text-decoration:underline}
.foot-bot{border-top:1px solid rgba(255,255,255,.35); padding-top:18px; display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; font-size:13px}
@media (max-width:900px){ .foot-top{grid-template-columns:repeat(3,minmax(0,1fr))} .foot-brand{grid-column:1/-1} }
@media (max-width:560px){ .foot-top{grid-template-columns:repeat(2,minmax(0,1fr))} }

/* Login */
.auth{flex:1 0 auto; display:grid; place-items:center; padding:24px 16px; background:#808080 center 35%/cover; background-image:linear-gradient(rgba(128,128,128,.82),rgba(128,128,128,.9)), url("brand/gedung.jpg")}
.auth-card{width:min(440px,100%); background:var(--panel); border-radius:18px; padding:26px; display:grid; gap:14px; box-shadow:0 30px 80px -30px rgba(0,0,0,.6)}
.auth-card.admin{border-top:6px solid #808080}
.auth .seg{display:grid; grid-template-columns:1fr 1fr}
.auth .seg a{text-align:center; font-size:13.5px; font-weight:700}
.auth .seg a.on{background:#808080; color:#fff; box-shadow:none}

/* Notifikasi singkat */
.toast{position:fixed; left:50%; transform:translateX(-50%); bottom:calc(22px + env(safe-area-inset-bottom,0px)); background:var(--ink); color:var(--bg); padding:11px 18px; border-radius:12px; z-index:60; font-weight:600; max-width:calc(100% - 32px); animation:toast 4.5s forwards}
.toast.err{background:var(--crit); color:#fff}
@keyframes toast{0%{opacity:0; transform:translate(-50%,10px)} 8%,85%{opacity:1; transform:translate(-50%,0)} 100%{opacity:0; visibility:hidden}}

/* Animasi semua yang bisa diklik */
.rp{position:absolute; border-radius:50%; transform:scale(0); background:currentColor; opacity:.22; pointer-events:none; animation:rp .55s ease-out forwards}
@keyframes rp{to{transform:scale(2.4); opacity:0}}
@media (prefers-reduced-motion:no-preference){
  :where(a, button, .chip, .row, label, .card, .qbox){transition:transform .2s cubic-bezier(.2,.8,.2,1), box-shadow .2s, background-color .2s, color .2s, border-color .2s, opacity .2s}
  :where(.btn, .hbtn, .row, .card, .qbox, .fix, .cat-grid label, .dup-item, .notif-list a, .drop):hover{transform:translateY(-2px); box-shadow:0 10px 20px -12px rgba(0,0,0,.5)}
  :where(.chip, .seg a, .top nav a, .foot-col a, .ev, .bell):hover{transform:translateY(-1px)}
  :where(.btn, .hbtn, .row, .card, .qbox, .chip, .seg a, .cat-grid label, .top nav a, .um-btn, .ev, .bell):active{transform:scale(.96); box-shadow:none; transition-duration:.07s}
  .stars label:hover{transform:scale(1.2)}
  main.wrap > *{animation:rise .35s ease-out both}
  @keyframes rise{from{opacity:0; transform:translateY(8px)}}
  .um-pop:not([hidden]){animation:rise .16s ease-out}
  details[open] p{animation:rise .25s ease-out}
}

/* Animasi scroll dan popup formulir pengaduan pada beranda */
html{scroll-behavior:smooth}
@media(prefers-reduced-motion:reduce){html{scroll-behavior:auto}}
body.modal-open{overflow:hidden}
.complaint-modal{position:fixed;inset:0;z-index:1000;display:grid;place-items:center;padding:16px}
.complaint-modal[hidden]{display:none}
.complaint-backdrop{position:absolute;inset:0;background:rgba(9,12,22,.66);backdrop-filter:blur(1.5px);opacity:0;transition:opacity .24s ease}
.complaint-dialog{position:relative;z-index:1;width:min(760px,calc(100vw - 36px));height:auto;max-height:92dvh;min-height:0;background:linear-gradient(145deg,#242424,#171717 72%);color:#fff;border:1px solid rgba(255,255,255,.16);border-radius:20px;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 36px 100px rgba(0,0,0,.58),0 0 0 1px rgba(255,255,255,.035),0 0 50px rgba(196,157,93,.10);opacity:0;transform:translateY(24px) scale(.94);transition:opacity .34s ease,transform .48s cubic-bezier(.16,1,.3,1)}
.complaint-modal.is-open .complaint-backdrop{opacity:1;backdrop-filter:blur(5px)}
.complaint-modal.is-open .complaint-dialog{opacity:1;transform:translateY(0) scale(1)}
.complaint-dialog::before{content:"";position:absolute;z-index:3;left:0;right:0;top:0;height:2px;background:linear-gradient(90deg,transparent,#d7b77a 22%,#fff0c6 50%,#b78a4c 78%,transparent);pointer-events:none}
.complaint-dialog{animation:modalGlow 5s ease-in-out infinite alternate}
@keyframes modalGlow{from{box-shadow:0 36px 100px rgba(0,0,0,.54),0 0 26px rgba(196,157,93,.07)}to{box-shadow:0 36px 100px rgba(0,0,0,.62),0 0 44px rgba(196,157,93,.16)}}
.complaint-modal-head{flex:0 0 66px;padding:14px 22px;display:flex;align-items:center;justify-content:space-between;gap:12px;border-bottom:1px solid rgba(255,255,255,.12);background:linear-gradient(110deg,#252525,#1c1c1c)}
.complaint-modal-head h2{font-size:18px;letter-spacing:-.02em}
.complaint-modal-head h2{font-size:16px;margin:0;color:#fff}
.complaint-close{flex:0 0 36px;width:36px;height:36px;border:1px solid rgba(255,255,255,.08);border-radius:11px;background:#303030;color:#fff;font-size:22px;line-height:1;cursor:pointer;transition:background .2s,transform .2s}
.complaint-close:hover{background:#494038;transform:rotate(90deg)}
.complaint-close:hover{background:#383838}
.complaint-frame{width:100%;height:470px;min-height:180px;max-height:calc(92dvh - 66px);flex:0 0 auto;border:0;background:#1f1f1f;display:block;transition:height .22s ease}
body.modal-open{overflow:hidden}
.is-embedded{background:#1f1f1f!important;color:#fff!important;height:auto!important;min-height:0!important;display:block!important}
.is-embedded body{height:auto!important;min-height:0!important;min-height:0!important;display:block!important}
.is-embedded .top,.is-embedded .foot,.is-embedded .page-head{display:none!important}
.is-embedded main.wrap{width:100%;max-width:none;min-height:0;flex:1 1 auto;padding:0 18px 14px;display:block}
.is-embedded .grid2{display:block}
.is-embedded .grid2 aside{display:none!important}
.is-embedded .complaint-panel{padding:14px 0 0;border:0;border-radius:0;background:transparent;color:#fff}
.is-embedded main.wrap{padding-bottom:12px}
.is-embedded .complaint-panel{margin:0}
.is-embedded .wz-nav{margin-top:2px}
.is-embedded .sender-choice{display:grid;gap:9px}
.is-embedded .sender-option{display:flex;align-items:flex-start;gap:10px;padding:12px;border:1px solid #414141;border-radius:12px;background:#252525}
.is-embedded .sender-option input{margin-top:3px}
.is-embedded .sender-option span{display:grid;gap:2px}
.is-embedded .sender-option small{font-size:12px;font-weight:500;color:#c7c7c7}
.is-embedded .complaint-panel .wz-steps{margin-bottom:2px}
.is-embedded .complaint-panel .wz-steps li{color:#e7e7e7}
.is-embedded .complaint-panel .wz-steps li span{background:#202020;border-color:#484848;color:#fff}
.is-embedded .complaint-panel .wz-steps li.on span,.is-embedded .complaint-panel .wz-steps li.done span{background:#888;border-color:#888;color:#fff}
.is-embedded .complaint-panel .wz-steps li::before{background:#484848}
.is-embedded .complaint-panel .wz-steps li.on::before,.is-embedded .complaint-panel .wz-steps li.done::before{background:#888}
.is-embedded .complaint-panel .wz h2{color:#fff}
.is-embedded label{color:#fff}
.is-embedded input:not([type=checkbox]):not([type=radio]),.is-embedded textarea,.is-embedded select{background:#202020;color:#fff;border-color:#454545}
.is-embedded .wz-nav{border-color:#414141}
.reveal-on-scroll.will-reveal{opacity:0;transform:translateY(22px);transition:opacity .7s ease,transform .7s cubic-bezier(.2,.8,.2,1)}
.reveal-on-scroll.is-visible{opacity:1;transform:translateY(0)}
.feat.reveal-on-scroll.will-reveal>div{opacity:0;transform:translateY(14px)}
.feat.reveal-on-scroll.is-visible>div{animation:statReveal .55s cubic-bezier(.2,.8,.2,1) both}
.feat.reveal-on-scroll.is-visible>div:nth-child(2){animation-delay:.08s}.feat.reveal-on-scroll.is-visible>div:nth-child(3){animation-delay:.16s}.feat.reveal-on-scroll.is-visible>div:nth-child(4){animation-delay:.24s}
@keyframes statReveal{to{opacity:1;transform:translateY(0)}}
@keyframes viewIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
@media(prefers-reduced-motion:no-preference){.wz:not([hidden]){animation:viewIn .3s ease-out}}
@media(max-width:700px){.complaint-modal{padding:0}.complaint-dialog{width:100%;max-height:100dvh;min-height:0;border-radius:0}.complaint-modal-head{padding:13px 16px}.complaint-frame{max-height:calc(100dvh - 66px)}.is-embedded main.wrap{padding:0 14px 12px}.is-embedded .complaint-panel{padding-top:12px}}
/* Konsistensi tipografi khusus ruang kerja admin: semua kontrol mengikuti Inter/Segoe UI, ukuran dan ritme seragam. */
body.role-admin main,body.role-admin main :where(h1,h2,h3,p,label,small,span,a,button,input,select,textarea,th,td){font-family:Inter,"Segoe UI",Arial,sans-serif!important}
body.role-admin main .panel,body.role-admin main .box{font-family:Inter,"Segoe UI",Arial,sans-serif}
body.role-admin main .panel :where(h2,h3),body.role-admin main .box :where(h2,h3){font-size:17px;line-height:1.3;letter-spacing:-.015em}
body.role-admin main .h,body.role-admin main .panel .h,body.role-admin main .box .h{font-size:12.5px;line-height:1.45}
body.role-admin main label{font-size:13.5px;line-height:1.45;font-weight:700}
body.role-admin main input,body.role-admin main select,body.role-admin main textarea{font-size:14px;line-height:1.5;letter-spacing:0;font-weight:500}
body.role-admin main button,body.role-admin main .btn{font-size:13.5px;line-height:1.4;font-weight:700;letter-spacing:0}
.sender-choice{display:grid;gap:9px}
.sender-option{display:flex;align-items:flex-start;gap:10px;padding:12px;border:1px solid var(--line);border-radius:12px;background:var(--panel-2);cursor:pointer}
.sender-option input{margin-top:3px;flex:none}
.sender-option span{display:grid;gap:2px}
.sender-option small{font-size:12px;font-weight:500;color:var(--muted)}
#kategoriLainnyaWrap[hidden]{display:none!important}
@media(prefers-reduced-motion:reduce){.complaint-backdrop,.complaint-dialog,.reveal-on-scroll.will-reveal{transition:none!important;animation:none!important;transform:none!important}.reveal-on-scroll.will-reveal{opacity:1!important}.wz:not([hidden]){animation:none!important}}


/* Detail laporan dibuka sebagai modal pada papan untuk akun pengguna maupun admin. */
body.report-open{overflow:hidden}
.report-modal{position:fixed;inset:0;z-index:1100;display:grid;place-items:center;padding:clamp(10px,2vw,24px)}
.report-modal[hidden]{display:none!important}
.report-backdrop{position:absolute;inset:0;background:rgba(8,11,18,.72);backdrop-filter:blur(6px);opacity:0;transition:opacity .25s ease}
.report-dialog{position:relative;z-index:1;width:min(1120px,calc(100vw - 28px));height:min(90dvh,900px);background:var(--panel);color:var(--ink);border:1px solid var(--line);border-radius:20px;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 30px 90px rgba(0,0,0,.48);opacity:0;transform:translateY(18px) scale(.98);transition:opacity .25s ease,transform .35s cubic-bezier(.2,.8,.2,1)}
.report-modal.is-open .report-backdrop{opacity:1}
.report-modal.is-open .report-dialog{opacity:1;transform:translateY(0) scale(1)}
.report-modal-head{height:58px;flex:0 0 58px;display:flex;align-items:center;justify-content:space-between;padding:10px 18px;border-bottom:1px solid var(--line);background:var(--panel-2)}
.report-modal-head h2{font-size:16px}
.report-frame{width:100%;height:calc(100% - 58px);flex:1;border:0;background:var(--bg);display:block}
.is-report-embedded{background:var(--bg)!important;color:var(--ink)!important;min-height:0!important;display:block!important}
.is-report-embedded .top,.is-report-embedded .foot{display:none!important}
.is-report-embedded main.wrap{width:100%;max-width:none;min-height:0;padding:18px 20px 28px;display:block}
.is-report-embedded .page-head{display:flex!important}
.is-report-embedded .page-head h1{font-size:clamp(20px,3vw,28px)}
.is-report-embedded .panel,.is-report-embedded .box{background:var(--panel)!important;color:var(--ink)!important}
.is-report-embedded .panel :where(p,dd,dt,span,label),.is-report-embedded .box :where(p,span){color:inherit}
.is-report-embedded .btn{white-space:normal}
@media(max-width:700px){.report-modal{padding:0}.report-dialog{width:100%;height:100dvh;max-height:100dvh;border-radius:0}.report-modal-head{height:54px;flex-basis:54px}.is-report-embedded main.wrap{padding:14px 12px 24px}}
@media(prefers-reduced-motion:reduce){.report-backdrop,.report-dialog{transition:none!important;transform:none!important}}

/* Tema eksplisit: seluruh komponen admin mengikuti token tema, tanpa panel putih/teks putih yang bercampur. */
:root[data-theme="light"]{color-scheme:light}
:root[data-theme="dark"]{color-scheme:dark}
body.role-admin main .panel,body.role-admin main .box,body.role-admin main form.panel{
  background:var(--panel)!important;color:var(--ink)!important;border-color:var(--line)!important;border-radius:16px!important;
  overflow:hidden;isolation:isolate;box-shadow:0 8px 24px rgba(0,0,0,.06)
}
body.role-admin main .panel::before,body.role-admin main .panel::after,body.role-admin main .box::before,body.role-admin main .box::after{content:none!important;display:none!important}
body.role-admin main :where(input:not([type=checkbox]):not([type=radio]),select,textarea){
  background:var(--panel-2)!important;color:var(--ink)!important;border:1px solid var(--line)!important;
  border-radius:10px;font-family:var(--f)!important;opacity:1
}
body.role-admin main :where(input,select,textarea)::placeholder{color:var(--muted);opacity:.68}
body.role-admin main label,body.role-admin main .h,body.role-admin main .psub{color:var(--muted)}
body.role-admin main .two{align-items:start;gap:12px}
body.role-admin main .stack{min-width:0}
@media(prefers-reduced-motion:reduce){.report-backdrop,.report-dialog{transition:none!important;transform:none!important}}

<?php }

function js(): void { ?>
(function(){
  /* Animasi masuk bertahap untuk bagian transparansi saat masuk viewport, mengikuti gerak viewIn dari HTML acuan. */
  var revealItems=document.querySelectorAll('.reveal-on-scroll');
  if(revealItems.length){
    if('IntersectionObserver' in window && !matchMedia('(prefers-reduced-motion: reduce)').matches){
      revealItems.forEach(function(el){el.classList.add('will-reveal');});
      var revealObserver=new IntersectionObserver(function(entries,observer){
        entries.forEach(function(entry){
          if(entry.isIntersecting){entry.target.classList.add('is-visible');observer.unobserve(entry.target);}
        });
      },{threshold:.14,rootMargin:'0px 0px -36px 0px'});
      revealItems.forEach(function(el){revealObserver.observe(el);});
    }else{revealItems.forEach(function(el){el.classList.add('is-visible');});}
  }
  /* Mode gelap/terang */
  var b=document.getElementById('themeBtn');
  function cur(){ return document.documentElement.dataset.theme || (matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'); }
  function paint(){ if(!b) return; var d=cur()==='dark';
    b.innerHTML=(d?'<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg><span class="tlabel">Mode terang</span>'
      :'<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg><span class="tlabel">Mode gelap</span>');
    b.title=d?'Ganti ke mode terang':'Ganti ke mode gelap'; }
  paint();
  if(b) b.addEventListener('click',function(){ var n=cur()==='dark'?'light':'dark'; document.documentElement.dataset.theme=n; try{localStorage.setItem('sf-theme',n);}catch(e){} paint(); });
  window.addEventListener('storage',function(e){if(e.key==='sf-theme'&&(e.newValue==='dark'||e.newValue==='light')){document.documentElement.dataset.theme=e.newValue;paint();}});
  /* Menu profil */
  var mb=document.querySelector('.um-btn'), mp=document.getElementById('umPop');
  function setMenu(o){ if(!mb) return; mb.setAttribute('aria-expanded',o); mp.hidden=!o; if(o){ var f=mp.querySelector('[role=menuitem]'); f&&f.focus(); } }
  if(mb){ mb.addEventListener('click',function(e){ e.stopPropagation(); setMenu(mp.hidden); });
    document.addEventListener('click',function(e){ if(!mp.hidden && !e.target.closest('.umenu')) setMenu(false); });
    document.addEventListener('keydown',function(e){ if(e.key==='Escape' && !mp.hidden){ setMenu(false); mb.focus(); } }); }
  /* Konfirmasi tombol berbahaya */
  document.addEventListener('submit',function(e){ var m=e.target.getAttribute('data-confirm'); if(m && !confirm(m)) e.preventDefault(); });
  /* Perbesar foto */
  document.addEventListener('click',function(e){
    var p=e.target.closest('.photo'); if(p){ e.preventDefault(); var src=p.getAttribute('href')||p.querySelector('img').src; var lb=document.createElement('div'); lb.className='lightbox'; lb.innerHTML='<img alt="Foto ukuran penuh">'; lb.firstChild.src=src; lb.onclick=function(){lb.remove();}; document.body.appendChild(lb); return; }
    var c=e.target.closest('[data-copy]'); if(c){ var t=c.getAttribute('data-copy'); (navigator.clipboard?navigator.clipboard.writeText(t):Promise.reject()).then(function(){ c.textContent='✓ Tersalin'; },function(){ prompt('Salin nomor tiket:',t); }); }
  });
  document.addEventListener('keydown',function(e){ if(e.key==='Escape'){ var lb=document.querySelector('.lightbox'); lb&&lb.remove(); } });
  /* Jika detail laporan dibuka dari dalam iframe detail, minta parent mengganti konten modal. */
  if(window.parent!==window && document.body.classList.contains('is-report-embedded')){
    document.addEventListener('click',function(e){
      var a=e.target.closest&&e.target.closest('a[href]');
      if(a && /(?:index|admin)\.php\?(?:[^#]*&)?(?:tiket|kelola)=/.test(a.getAttribute('href')||'')){
        e.preventDefault(); window.parent.postMessage({type:'sf-open-report',url:a.href},window.location.origin);
      }
    });
  }
  /* Efek gelombang saat tombol/kartu ditekan */
  document.addEventListener('pointerdown',function(e){
    if(matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    var el=e.target.closest('.btn, .hbtn, .card, .qbox, .chip, .fix'); if(!el || el.disabled) return;
    var cs=getComputedStyle(el); if(cs.position==='static') el.style.position='relative'; if(cs.overflow!=='hidden') el.style.overflow='hidden';
    var r=el.getBoundingClientRect(), s=Math.max(r.width,r.height), sp=document.createElement('span'); sp.className='rp';
    sp.style.cssText='width:'+s+'px;height:'+s+'px;left:'+(e.clientX-r.left-s/2)+'px;top:'+(e.clientY-r.top-s/2)+'px';
    el.appendChild(sp); sp.addEventListener('animationend',function(){ sp.remove(); });
  });
})();
<?php }
