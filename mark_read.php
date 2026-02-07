<?php
session_start();
require "config.php";

try {
    $pdo = new PDO(
        $config['db']['dsn'],
        $config['db']['user'],
        $config['db']['pass'],
        $config['db']['options']
    );
} catch (Exception $e) {
    exit("DB error");
}

$user = $_SESSION['user_id'];

$pdo->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")
    ->execute([$user]);
