<?php
declare(strict_types=1);
$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Location: /dashboard/register.php' . ($qs !== '' ? ('?' . $qs) : ''), true, 302);
exit;



