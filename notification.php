<?php
session_start();
header("Content-Type: application/json");

require "config.php";

try {
    $pdo = new PDO(
        $config['db']['dsn'],
        $config['db']['user'],
        $config['db']['pass'],
        $config['db']['options']
    );
} catch (PDOException $e) {
    echo json_encode([]);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode([]);
    exit;
}

$userId = $_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT id, message, link, created_at
    FROM notifications
    WHERE user_id = ?
    ORDER BY id DESC
    LIMIT 20
");
$stmt->execute([$userId]);

echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));