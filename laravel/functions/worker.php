<?php // worker.php
require __DIR__.'/vendor/autoload.php';

$pdo = new PDO('mysql:host=127.0.0.1;dbname=appdb;charset=utf8mb4','appuser','apppass', [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

while (true) {
  $pdo->beginTransaction();
  $st = $pdo->query("SELECT id,email,'subject',body_text FROM mail_outbox WHERE 'status' = 'pending' ORDER BY id LIMIT 1 FOR UPDATE");
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) { $pdo->commit(); sleep(1); continue; }
  $pdo->prepare("UPDATE mail_outbox SET tries=tries+1 WHERE id=?")->execute([$row['id']]);
  $pdo->commit();

  try {
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = 'email-smtp.ap-northeast-1.amazonaws.com';
    $mail->Port = 587;
    $mail->SMTPAuth = true;
    $mail->Username = 'SMTP_USER';
    $mail->Password = 'SMTP_PASS';
    $mail->setFrom('no-reply@example.com');
    $mail->addAddress($row['email']);
    $mail->Subject = $row['subject'];
    $mail->Body    = $row['body_text'];
    $mail->send();

    $pdo->prepare("UPDATE mail_outbox SET 'status'='sent', last_error=NULL WHERE id=?")->execute([$row['id']]);
  } catch (Throwable $e) {
    $pdo->prepare("UPDATE mail_outbox SET 'status'=IF(tries>=5,'failed','pending'), last_error=LEFT(?,500) WHERE id=?")
        ->execute([substr((string)$e,0,500), $row['id']]);
  }
}
