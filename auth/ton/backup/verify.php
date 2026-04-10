<?php
require_once $_SERVER['DOCUMENT_ROOT'].'/rwa/inc/core/bootstrap.php';

header('Content-Type: application/json');

$raw = file_get_contents('php://input');
file_put_contents('/tmp/rwa-ton-verify.log', date('c') . "\n" . $raw . "\n\n", FILE_APPEND);
$data = json_decode($raw, true);

$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    echo json_encode([
        "ok"=>false,
        "error"=>"Invalid JSON"
    ]);
    exit;
}

$proof = $data['proof'] ?? null;
$account = $data['account'] ?? null;

if (!$proof || !$account) {
    echo json_encode([
        "ok"=>false,
        "error"=>"Missing proof"
    ]);
    exit;
}

$address = $account['address'] ?? '';

if (!$address) {
    echo json_encode([
        "ok"=>false,
        "error"=>"No wallet address"
    ]);
    exit;
}

$pdo = rwa_db();

$stmt = $pdo->prepare("
SELECT * FROM users
WHERE ton_address = ?
LIMIT 1
");
$stmt->execute([$address]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {

    $stmt = $pdo->prepare("
    INSERT INTO users
    (ton_address, created_at)
    VALUES (?, NOW())
    ");

    $stmt->execute([$address]);

    $uid = $pdo->lastInsertId();

    $stmt = $pdo->prepare("
    SELECT * FROM users WHERE id=?
    ");

    $stmt->execute([$uid]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
}

session_start();
$_SESSION['user'] = $user;

echo json_encode([
    "ok"=>true,
    "address"=>$address
]);