<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';

use App\Auth\Auth;

$destino = 'login.php';
if (Auth::user()) {
    $destino = Auth::homePage();
}
header('Location: ' . $destino);
exit;
