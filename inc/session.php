<?php
declare(strict_types=1);



// nur konfigurieren + starten, wenn noch KEINE Session läuft
if (session_status() === PHP_SESSION_NONE) {

  // session_name / cookie params dürfen NUR vor session_start() gesetzt werden
  session_name('lkw_sess'); // dein Name, falls du einen nutzt

 session_set_cookie_params([
  'lifetime' => 0,
  'path' => '/',
  'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
  'httponly' => true,
  'samesite' => 'Lax',
]);
session_start();
}
