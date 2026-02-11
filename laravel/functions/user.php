<?php

function findUserByTele(PDO $pdo, string $tele): ?array {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE tele = :tele");
    $stmt->execute(['tele' => $tele]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function findUserByEmail(PDO $pdo, string $email): ?array {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function findUserByCodeTele(PDO $pdo, int $userId, string $code): ?array {
    $stmt = $pdo->prepare("SELECT * FROM code_data
                           WHERE user_id = :userid AND code = :code AND user_status = 1 ORDER BY id DESC LIMIT 1");
    $stmt->execute(['userid' => $userId, 'code' => $code]);
    $usercode = $stmt->fetch();
    return $usercode ?: null;
}

function findUserByCodeEmail(PDO $pdo, int $userId, string $code): ?array {
    $stmt = $pdo->prepare("SELECT * FROM code_data 
                           WHERE user_id = :userid AND code = :code AND user_status = 2 ORDER BY id DESC LIMIT 1");
    $stmt->execute(['userid' => $userId, 'code' => $code]);
    $usercode = $stmt->fetch();
    return $usercode ?: null;
}

function findUserByCodeSettingTele(PDO $pdo, int $userId, string $code): ?array {
    $stmt = $pdo->prepare("SELECT * FROM code_data
                           WHERE user_id = :userid AND code = :code AND user_status = 3 ORDER BY id DESC LIMIT 1");
    $stmt->execute(['userid' => $userId, 'code' => $code]);
    $usercode = $stmt->fetch();
    return $usercode ?: null;
}

function findUserByCodeSettingEmail(PDO $pdo, int $userId, string $code): ?array {
    $stmt = $pdo->prepare("SELECT * FROM code_data 
                           WHERE user_id = :userid AND code = :code AND user_status = 4 ORDER BY id DESC LIMIT 1");
    $stmt->execute(['userid' => $userId, 'code' => $code]);
    $usercode = $stmt->fetch();
    return $usercode ?: null;
}

function findDeviceByID(PDO $pdo, string $device): ?array {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE model_number = ?");
    $stmt->execute([$device]);
    $deviceTest = $stmt->fetch();
    return $deviceTest ?: null;
}

function updateUserRefreshToken(PDO $pdo, string $token_hash, string $expires, int $user_id): bool {
    $stmt = $pdo->prepare("UPDATE users SET refresh_token_hash = ?, refresh_token_expires_at = ? WHERE id = ?");
    return $stmt->execute([$token_hash, $expires, $user_id]);
}

function updateUserPasswordTele(PDO $pdo, string $usertele, string $passwordtext): bool {
    $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE tele = ?");
    return $stmt->execute([$passwordtext, $usertele]);
}

function updateUserPasswordEmail(PDO $pdo, string $useremail, string $passwordtext): bool {
    $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
    return $stmt->execute([$passwordtext, $useremail]);
}

function newUser(PDO $pdo, string $email, string $name, string $passhash): bool {
    $stmt = $pdo->prepare("INSERT INTO users (email, username, password_hash) VALUES (?, ?, ?)");
    return $stmt->execute([$email, $name, $passhash]);
}

function newDevice(PDO $pdo, string $device, int $userid): bool {
    $stmt = $pdo->prepare("INSERT INTO products (model_number, user_id) VALUES (?, ?)");
    return $stmt->execute([$device, $userid]);
}

function updateDevice(PDO $pdo, string $device, int $userid): bool {
    $stmt = $pdo->prepare("UPDATE products SET user_id = ? WHERE model_number = ?");
    return $stmt->execute([$userid, $device]);
}

function updateUserData(PDO $pdo, int $userid, string $usertele, string $useremail): bool {
    $stmt = $pdo->prepare("UPDATE users SET tele = ?, email = ? WHERE id = ? AND is_deleted = 0");
    return $stmt->execute([$usertele, $useremail, $userid]);
}

function updateDeleteUserData(PDO $pdo, int $userid, string $username, string $tele, string $email, string $userpass): bool {
    $stmt = $pdo->prepare("UPDATE users SET tele = ?, email = ?, username = ?, password_hash = ?, email_verified = 0  is_deleted = 0 WHERE id = ? AND is_deleted = 1");
    return $stmt->execute([$tele, $email, $username, $userpass, $userid]);
}

function updateUserName(PDO $pdo, int $userid, string $username): bool {
    $stmt = $pdo->prepare("UPDATE users SET username = ? WHERE id = ? AND is_deleted = 0");
    return $stmt->execute([$username, $userid]);
}

function updateUserSetting(PDO $pdo, int $userid, int $mapBtn, int $gps, int $ene): bool {
    $stmt = $pdo->prepare("UPDATE setting SET map_btn = ?, gps_status = ?, energy_saving = ? WHERE user_id = ? AND is_deleted = 0");
    return $stmt->execute([$mapBtn, $gps, $ene, $userid]);
}

function updateDeleteUserSetting(PDO $pdo, int $userid, string $plan, string $payment, string $tele, string $email): bool {
    $stmt = $pdo->prepare("UPDATE setting SET plan_id = (SELECT id FROM plan_data WHERE stripe_plan_type = ? AND is_deleted = 0), 
                                              payment_id = (SELECT id FROM payment_data WHERE stripe_payment_type = ? AND is_deleted = 0),
                                              new_tele = ?, new_email = ?, is_deleted = 0 
                                        WHERE user_id = ? AND is_deleted = 1");
    return $stmt->execute([$plan, $payment, $tele, $email, $userid]);
}

function updateUserPlan(PDO $pdo, int $id, int $userid): bool {
    $stmt = $pdo->prepare("UPDATE setting SET plan_id = ? WHERE user_id = ? AND is_deleted = 0");
    return $stmt->execute([$id, $userid]);
}

function updateUserSettingTeleEmail(PDO $pdo, int $userid, string $usertele, string $useremail): bool {
    $stmt = $pdo->prepare("UPDATE setting SET new_tele = ?, new_email = ? WHERE user_id = ? AND is_deleted = 0");
    return $stmt->execute([$usertele, $useremail, $userid]);
}

function updateNewUser(PDO $pdo, int $userid): bool {
    $stmt = $pdo->prepare("UPDATE users SET email_verified = 1 WHERE id = ? AND is_deleted = 0");
    return $stmt->execute([$userid]);
}

function newCodeTele(PDO $pdo, string $tele, string $code): bool {
    $stmt = $pdo->prepare("INSERT INTO code_data (user_id, code, user_status) VALUES ((SELECT id FROM users WHERE tele = ?), ?, 1)");
    return $stmt->execute([$tele, $code]);
}

function newCodeEmail(PDO $pdo, string $email, string $code): bool {
    $stmt = $pdo->prepare("INSERT INTO code_data (user_id, code, user_status) VALUES ((SELECT id FROM users WHERE email = ?), ?, 2)");
    return $stmt->execute([$email, $code]);
}

function newCodeSettingTele(PDO $pdo, string $tele, string $code): bool {
    $stmt = $pdo->prepare("INSERT INTO code_data (user_id, code, user_status) VALUES ((SELECT user_id FROM setting WHERE new_tele = ?), ?, 3)");
    return $stmt->execute([$tele, $code]);
}

function newCodeSettingEmail(PDO $pdo, string $email, string $code): bool {
    $stmt = $pdo->prepare("INSERT INTO code_data (user_id, code, user_status) VALUES ((SELECT user_id FROM setting WHERE new_email = ?), ?, 4)");
    return $stmt->execute([$email, $code]);
}

function insertUserData(PDO $pdo, string $username, string $tele, string $email, string $userpass): bool {
    $stmt = $pdo->prepare("INSERT INTO users (tele, email, username, password_hash) VALUES (?, ?, ?, ?)");
    return $stmt->execute([$tele, $email, $username, $userpass]);
}

function insertUserSetting(PDO $pdo, int $userid, string $plan, string $payment, string $tele, string $email): bool {
    $stmt = $pdo->prepare("INSERT INTO setting (user_id, plan_id, payment_id, new_tele, new_email) VALUES (?, 
                           (SELECT id FROM plan_data WHERE stripe_plan_type = ? AND is_deleted = 0), 
                           (SELECT id FROM payment_data WHERE stripe_payment_type = ? AND is_deleted = 0), ?, ?)");
    return $stmt->execute([$userid, $plan, $payment, $tele, $email]);
}

function insertContactData(PDO $pdo, string $email, string $username, string $commentdata): bool {
    $stmt = $pdo->prepare("INSERT INTO contact_data (email, username, commentdata) VALUES (?, ?, ?)");
    return $stmt->execute([$email, $username, $commentdata]);
}

function insertUrlData(PDO $pdo, int $userid, string $url, int $flag, string $text, int $userstatus): bool {
    $stmt = $pdo->prepare("INSERT INTO url_data (user_id, user_url, subject_status, subject_text, user_status) VALUES (?, ?, ?, ?, ?)");
    return $stmt->execute([$userid, $url, $flag, $text, $userstatus]);
}

//ユーザー
function getUserData(PDO $pdo, int $userId): ?array {
    $stmt = $pdo->prepare("SELECT id, username, tele, email
                           FROM users 
                           WHERE id = :userid AND is_deleted = 0");
    $stmt->execute([
        ':userid' => $userId
    ]);
    $results = $stmt->fetch();
    return $results ?: null;
}
//ユーザー設定
function getUserSetting(PDO $pdo, int $userId): ?array {
    $stmt = $pdo->prepare("SELECT S.plan_id, S.payment_id, S.map_btn, S.gps_status, S.energy_saving
                           FROM users U 
                           INNER JOIN setting S ON S.user_id = U.id
                           WHERE U.id = :userid AND U.is_deleted = 0");
    $stmt->execute([
        ':userid' => $userId
    ]);
    $results = $stmt->fetch();
    return $results ?: null;
}
//URL確認
function getUserURL(PDO $pdo, int $urlId): ?array {
    $stmt = $pdo->prepare("SELECT U.id, U.tele, U.email, U.username, UD.subject_status, UD.subject_text, UD.user_status
                           FROM users U 
                           INNER JOIN url_data UD ON UD.user_id = U.id
                           WHERE UD.id = :urlid AND U.is_deleted = 0 AND UD.is_deleted = 0 LIMIT 1");
    $stmt->execute([
        ':urlid' => $urlId
    ]);
    $results = $stmt->fetch();
    return $results ?: null;
}
//プラン確認
function getUserPlan(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT plan_name
                           FROM plan_data
                           WHERE id = :id AND is_deleted = 0");
    $stmt->execute([
        ':id' => $id
    ]);
    $results = $stmt->fetch();
    return $results ?: null;
}
//支払い確認
function getUserPayment(PDO $pdo, int $id): ?array {
     $stmt = $pdo->prepare("SELECT payment_name
                           FROM payment_data 
                           WHERE id = :id AND is_deleted = 0");
    $stmt->execute([
        ':id' => $id
    ]);
    $results = $stmt->fetch();
    return $results ?: null;
}

function updateSettingHistory (PDO $pdo, int $userId): bool {
    $stmt = $pdo->prepare("INSERT INTO setting_history (user_id, new_tele, new_email, old_tele, old_email)
                           VALUES (
                                (SELECT id  FROM users WHERE id = ? AND is_deleted = 0), 
                                (SELECT new_tele FROM setting WHERE id = ? AND is_deleted = 0), 
                                (SELECT new_email FROM setting WHERE id = ? AND is_deleted = 0), 
                                (SELECT tele FROM users WHERE id = ? AND is_deleted = 0), 
                                (SELECT email FROM users WHERE id = ? AND is_deleted = 0)
                            )");
    return $stmt->execute([$userId, $userId, $userId, $userId, $userId]);
}

//プランPRICEを取得
function planPrice(PDO $pdo, string $planType): array {
    $stmt = $pdo->prepare("SELECT stripe_price_id
                           FROM plan_data
                           WHERE stripe_plan_type = ? AND is_deleted = 0");
    $stmt->execute([$planType]);
    $results = $stmt->fetch();
    return $results ?: null;
}

//支払いTypeを取得
function paymentType(PDO $pdo, int $id): array {
    $stmt = $pdo->prepare("SELECT stripe_payment_type
                           FROM payment_data
                           WHERE id = ? AND is_deleted = 0");
    $stmt->execute([$id]);
    $results = $stmt->fetch();
    return $results ?: null;
}

//プランリスト
function planList(PDO $pdo): array {
    $stmt = $pdo->prepare("SELECT id, plan_name, monthly_fee, stripe_plan_type,
                           (CASE WHEN stripe_plan_type = 'free'    THEN 'このプランでは、デバイスIDは登録できません。
                            試用期間は登録後30日間です。試用期間を過ぎると自動でノーマルプランに移行し、月々の支払いが発生します。
                            試用期間内に解約なさった場合には支払いは発生しません。'
                                 WHEN stripe_plan_type = 'normal'  THEN 'このプランでは、デバイスIDを3つ登録できます。'
                                 WHEN stripe_plan_type = 'premium' THEN 'このプランでは、デバイスIDを5つ登録できます。'
                                 ELSE '' END) AS text_data
                           FROM plan_data
                           WHERE is_deleted = 0");
    $stmt->execute([]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
//支払いリスト
function paymentList(PDO $pdo): array {
     $stmt = $pdo->prepare("SELECT id, payment_name, stripe_payment_type
                           FROM payment_data 
                           WHERE is_deleted = 0");
    $stmt->execute([]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>