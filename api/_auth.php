<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
$_SESSION['role'] = $_SESSION['role'] ?? 'admin';
