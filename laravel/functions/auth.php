<?php
declare(strict_types=1);

/** HTTPS判定 */
function isHttps(): bool {
  if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
  if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') return true;
  return (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
}

/** 安全にセッション開始（毎APIの先頭で呼ぶ） */
function startSessionSecure(): void {
  if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
      'lifetime' => 0,
      'path'     => '/',
      'domain'   => '',                  // 同一ホストのみ
      'secure'   => isHttps(),           // ローカルHTTPなら false、本番は true
      'httponly' => true,
      'samesite' => 'Lax',               // 同一サイト内POSTで送信される
    ]);
    session_start();
  }
}

function loginUser(array $user): void {
  // セッション固定化対策
  session_regenerate_id(true);
  $_SESSION['uid'] = $user['id'];
  $_SESSION['username'] = $user['username'];
}

function requireLogin(): int {
  if (empty($_SESSION['uid'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>'unauthorized']);
    exit;
  }
  return (int)$_SESSION['uid'];
}
