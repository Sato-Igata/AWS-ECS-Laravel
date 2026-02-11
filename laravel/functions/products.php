<?php
//デバイス
function selectDataDevice(PDO $pdo, int $userId): ?array {
    $stmt = $pdo->prepare("SELECT model_number, model_name, id
                           FROM products
                           WHERE user_id = :userid AND is_deleted = 0
                           ORDER BY id
    ");
    $stmt->execute([':userid' => $userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getDevice(PDO $pdo, int $userId, string $device): ?array {
    $stmt = $pdo->prepare("SELECT model_number, model_name, id
                           FROM products 
                           WHERE user_id = :userid AND model_number = :device AND is_deleted = 0");
    $stmt->execute([
        ':userid' => $userId,
        ':device' => $device
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function getDeviceDeleteCheck(PDO $pdo, string $device): ?array {
    $stmt = $pdo->prepare("SELECT user_id
                           FROM products 
                           WHERE model_number = :device AND is_deleted = 1");
    $stmt->execute([
        ':device' => $device
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row !== false ? $row : null;
}

function getDeviceUserCheck(PDO $pdo, string $device): ?array {
    $stmt = $pdo->prepare("SELECT user_id
                           FROM products 
                           WHERE model_number = :device AND is_deleted = 0");
    $stmt->execute([
        ':device' => $device
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row !== false ? $row : null;
}

function insertUserDevice(PDO $pdo, string $devicenum, string $devicename, int $userId): bool {
    $stmt = $pdo->prepare("INSERT INTO products (model_number, model_name, user_id) VALUES (?, ?, ?)");
    return $stmt->execute([$devicenum, $devicename, $userId]);
}

function updateUserDevice(PDO $pdo, string $devicenum, string $devicename, int $userId): bool {
    $stmt = $pdo->prepare("UPDATE products SET model_name = ? WHERE model_number = ? AND user_id = ? AND is_deleted = 0");
    return $stmt->execute([$devicename, $devicenum, $userId]);
}

function updateUserDeleteDevice(PDO $pdo, string $devicenum, string $devicename, int $userId): bool {
    $stmt = $pdo->prepare("UPDATE products SET user_id = ?, model_name = ? WHERE model_number = ? AND is_deleted = 1");
    return $stmt->execute([$userId, $devicename, $devicenum, ]);
}

function updateDeleteDevice(PDO $pdo, string $devicenum, int $userId): bool {
    $stmt = $pdo->prepare("UPDATE products SET is_deleted = 0 WHERE model_number = ? AND user_id = ? AND is_deleted = 1");
    return $stmt->execute([$devicenum, $userId]);
}
function deleteDevice(PDO $pdo, string $devicenum, int $userId): bool {
    $stmt = $pdo->prepare("UPDATE products SET is_deleted = 1 WHERE model_number = ? AND user_id = ? AND is_deleted = 0");
    return $stmt->execute([$devicenum, $userId]);
}
?>