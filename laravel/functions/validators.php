<?php
declare(strict_types=1);

function isValidId(string $id): bool {
  // E-mail or phone number（超簡易）
  if (filter_var($id, FILTER_VALIDATE_EMAIL)) return true;
  // 電話番号（数字と+とハイフン許可）
  return preg_match('/^\+?[0-9\-]{6,20}$/', $id) === 1;
}

function isValidPassword(string $pw): bool {
  // 8文字以上、英字と数字を含む、' ` $ を含まない
  if (strlen($pw) < 8) return false;
  if (!preg_match('/[A-Za-z]/', $pw)) return false;
  if (!preg_match('/[0-9]/', $pw)) return false;
  if (preg_match('/[\'`$]/', $pw)) return false;
  return true;
}

function isValidUsername(string $name): bool {
  if (mb_strlen($name) > 255) return false;
  if (preg_match('/[\'`$]/u', $name)) return false;
  return true;
}

function isValidGroupName(string $s): bool {
  if ($s === '') return false;
  if (mb_strlen($s, 'UTF-8') > 100) return false;
  if (preg_match('/\p{Cc}|\p{Cn}/u', $s)) return false; // 制御文字禁止
  return true;
}

function requireJson(): array {
  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true) ?? [];
  if (!is_array($data)) $data = [];
  return $data;
}