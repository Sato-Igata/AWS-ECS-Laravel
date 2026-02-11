<?php
// apps/server/laravel/config/config.php

// ==== JWT 秘密鍵（必ずトークン発行側と同じ値にする） ====
// .env に JWT_SECRET を書いているならそれを使う
$envJwtSecret = getenv('JWT_SECRET');

if (!defined('JWT_SECRET')) {
    // .env にない場合用のデフォルト（開発用） 
    define('JWT_SECRET', $envJwtSecret ?: 'change-me-dev-secret-key');
}

// 環境変数は Laravel の .env をそのまま使う想定
$host = getenv('DB_HOST')      ;
$db   = getenv('DB_DATABASE')  ;
$user = getenv('DB_USERNAME')  ;
$pass = getenv('DB_PASSWORD')  ;
$charset = 'utf8mb4';

$dsn = "mysql:host={$host};dbname={$db};charset={$charset}";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error'  => 'DB接続に失敗しました',
        'detail' => $e->getMessage(), // 必要ならコメントアウトしてもOK
    ], JSON_UNESCAPED_UNICODE);
    exit;
}