<?php
/* Keluar dari akun (hanya lewat POST dengan token CSRF dari menu profil). */
require __DIR__ . '/app.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  check_csrf();
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
  }
  session_destroy();
  session_start();
  flash('Anda sudah keluar.');
}
go('login.php');
