<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/payments.php';

$id = (int)($_GET['license'] ?? 0);
if (!current_user()) { $_SESSION['next'] = 'renew.php?license=' . $id; redirect('login.php'); }
$u = current_user();
$st = pdo()->prepare("SELECT * FROM licenses WHERE id=? AND user_id=?"); $st->execute([$id,(int)$u['id']]);
$lic = $st->fetch();
if (!$lic) redirect('dashboard.php');
$_SESSION['order'] = ['plan_id' => (int)$lic['plan_id'], 'renew' => (int)$lic['id']];
redirect('checkout.php');
