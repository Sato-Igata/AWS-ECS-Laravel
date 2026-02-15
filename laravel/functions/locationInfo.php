<?php
use App\Services\DynamoDb\LocationInfoTable;
use App\Services\DynamoDb\LocationInfoUserTable;

function insertData(PDO $pdo, string $source, string $lat, string $lng, string $alt, string $stl, string $voltage, string $timestr): bool {
    $stmt = $pdo->prepare("INSERT INTO location_info (model_number, lat, lng, alt, stl, vol, time_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$source, $lat, $lng, $alt, $stl, $voltage, $timestr]);
    return $stmt->rowCount() > 0; // 0なら対象なし
}
function insertDataUser(PDO $pdo, int $userid, string $lat, string $lng, string $alt, string $acc, string $altacc, string $stl, string $voltage, string $timestr): bool {
    $stmt = $pdo->prepare("INSERT INTO location_info_user (user_id, lat, lng, alt, acc, alt_acc, stl, vol, time_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$userid, $lat, $lng, $alt, $acc, $altacc, $stl, $voltage, $timestr]);
    return $stmt->rowCount() > 0; // 0なら対象なし
}
function insertDataPoint(PDO $pdo, int $userid, string $lat, string $lng, string $alt, string $acc, string $altacc, string $stl, string $voltage, string $timestr, int $pointid, string $pointname): bool {
    $stmt = $pdo->prepare("INSERT INTO location_info_point (user_id, lat, lng, alt, acc, alt_acc, stl, vol, time_id, point_id, point_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$userid, $lat, $lng, $alt, $acc, $altacc, $stl, $voltage, $timestr, $pointid, $pointname]);
    return $stmt->rowCount() > 0; // 0なら対象なし
}
//普通のマップ
function selectData(PDO $pdo, int $userId, int $groupId): ?array {
    $stmt = $pdo->prepare("SELECT user_id, username, model_number, lat, lng, alt, acc, alt_acc, time_id, sub.jobflag AS status_flag, point_name, data_id 
            FROM (SELECT A.job AS jobflag, A.user_id, '' AS username, L.model_number, L.lat, L.lng, L.alt, '' AS acc, '' AS alt_acc, L.stl, L.time_id, L.created_at,
                         ROW_NUMBER() OVER (PARTITION BY L.model_number ORDER BY L.created_at DESC) AS rn, '' AS point_name, L.id AS data_id
                  FROM location_info L
                  INNER JOIN (SELECT P.user_id, P.model_number, G.job
                              FROM team_details G
                                   INNER JOIN products P ON G.subject_id = P.id
                              WHERE G.team_id = ? AND G.participation = 1 AND approval = 1 AND G.job = 3 AND G.is_deleted = 0
                             ) A ON L.model_number = A.model_number
                  WHERE L.is_deleted = 0
                 UNION ALL
                  SELECT  A.job AS jobflag, 
                          LU.user_id, A.username, '' AS model_number, LU.lat, LU.lng, LU.alt, LU.acc, LU.alt_acc, LU.stl, LU.time_id, LU.created_at,
                          ROW_NUMBER() OVER (PARTITION BY LU.user_id ORDER BY LU.created_at DESC) AS rn, '' AS point_name, LU.id AS data_id
                  FROM location_info_user LU
                  INNER JOIN (SELECT U.id, U.username, G.job
                              FROM team_details G
                                   INNER JOIN users U ON G.subject_id = U.id
                              WHERE G.team_id = ? AND G.participation = 1 AND approval = 1 AND G.job <> 3 AND G.is_deleted = 0
                             ) A ON LU.user_id = A.id
                  WHERE LU.is_deleted = 0
                 UNION ALL
                  SELECT (CASE WHEN LU.point_id = 1 THEN 4 WHEN LU.point_id = 2 THEN 5 ELSE A.job END) AS jobflag, 
                          LU.user_id, A.username, '' AS model_number, LU.lat, LU.lng, LU.alt, LU.acc, LU.alt_acc, LU.stl, LU.time_id, LU.created_at,
                          ROW_NUMBER() OVER (PARTITION BY LU.id, LU.point_name ORDER BY LU.created_at DESC) AS rn, LU.point_name, LU.id AS data_id
                  FROM location_info_point LU
                  INNER JOIN (SELECT U.id, U.username, G.job
                              FROM team_details G
                                   INNER JOIN users U ON G.subject_id = U.id
                              WHERE G.team_id = ? AND G.participation = 1 AND approval = 1 AND G.job <> 3 AND G.is_deleted = 0
                             ) A ON LU.user_id = A.id
                  WHERE LU.is_deleted = 0 AND LU.point_id <> '' AND LU.point_id IS NOT NULL
                 ) sub
            WHERE rn = 1
            ORDER BY created_at DESC ");
    $stmt->execute([$groupId, $groupId, $groupId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
//ポイント
function selectDataPoint(PDO $pdo, int $userId): ?array {
    $stmt = $pdo->prepare("SELECT 
                             user_id, '' AS username, '' AS model_number, 
                             lat, lng, alt, acc, alt_acc, time_id,
                             CASE WHEN point_id = 1 THEN 4 ELSE 5 END AS status_flag,
                             point_name, id AS data_id
                           FROM location_info_point
                           WHERE is_deleted = 0
                             AND user_id = :userid
                             AND point_id IN (1, 2)
                           ORDER BY created_at DESC");
    $stmt->execute([
        ':userid' => $userId
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
//軌跡
function selectDataTrajectory(PDO $pdo, int $userId, string $selectDate, int $selectCount): ?array {
    $num = (int)$selectDate;
    $oldDate = $num * 1000000;
    $newDate = ($num + 1) * 1000000;
    $stmt = $pdo->prepare("WITH
                           -- 対象ユーザーに紐づく製品（デバイス）集合
                           A_prod AS (
                             SELECT U.id AS user_id, P.model_number
                             FROM users U
                             JOIN products P ON P.user_id = U.id
                             WHERE U.id = ?
                           ),
                           -- デバイス位置（location_info）: モデルごとに“バケツ分け”
                           dev_raw AS (
                             SELECT
                               3 AS status_flag,
                               A_prod.user_id,
                               '' AS username,
                               L.model_number,
                               L.lat, L.lng, L.alt,
                               '' AS acc,
                               '' AS alt_acc,
                               L.stl,
                               L.time_id,
                               CAST(L.time_id AS UNSIGNED) AS intTime_id,
                               L.created_at,
                               '' AS point_name,
                               L.id AS data_id
                             FROM location_info L
                             JOIN A_prod ON A_prod.model_number = L.model_number
                             WHERE L.is_deleted = 0
                           ),
                           dev_buckets AS (
                             SELECT d.*,
                               MAX(d.intTime_id) OVER (PARTITION BY d.model_number) AS max_time_id_model,
                               FLOOR( (MAX(d.intTime_id) OVER (PARTITION BY d.model_number) - d.intTime_id) / GREATEST(?, 1) ) AS bucket
                             FROM dev_raw d
                           ),
                           dev_pick AS (
                             SELECT *
                             FROM (
                               SELECT dev_buckets.*,
                                 ROW_NUMBER() OVER (
                                   PARTITION BY model_number, bucket
                                   ORDER BY intTime_id DESC
                                 ) AS rn
                               FROM dev_buckets
                             ) t
                             WHERE t.rn = 1
                           ),
                           -- ユーザー位置（location_info_user）: 既存のロジックのまま
                           usr_raw AS (
                             SELECT
                               1 AS status_flag,
                               U.id AS user_id,
                               U.username,
                               '' AS model_number,
                               LU.lat, LU.lng, LU.alt, LU.acc, LU.alt_acc, LU.stl, 
                               LU.time_id,
                               CAST(LU.time_id AS UNSIGNED) AS intTime_id,
                               LU.created_at,
                               '' AS point_name,
                               LU.id AS data_id
                             FROM location_info_user LU
                             JOIN users U ON U.id = LU.user_id
                             WHERE LU.is_deleted = 0
                               AND U.id = ?
                           ),
                           usr_buckets AS (
                             SELECT u.*,
                               MAX(u.intTime_id) OVER () AS max_time_id,
                               FLOOR( (MAX(u.intTime_id) OVER () - u.intTime_id) / GREATEST(?, 1) ) AS bucket
                             FROM usr_raw u
                           ),
                           usr_pick AS (
                             SELECT *
                             FROM (
                               SELECT
                                 usr_buckets.*,
                                 ROW_NUMBER() OVER (PARTITION BY bucket ORDER BY intTime_id DESC) AS rn
                               FROM usr_buckets
                             ) t
                             WHERE t.rn = 1
                           ),
                           -- 代表点を統合
                           all_pick AS (
                             SELECT * FROM dev_pick
                             UNION ALL
                             SELECT * FROM usr_pick
                           )
                           SELECT
                             user_id, username, model_number,
                             lat, lng, alt, acc, alt_acc, time_id,
                             status_flag,
                             point_name, data_id,
                             created_at
                           FROM all_pick
                           WHERE CAST(time_id AS UNSIGNED) >= ?
                             AND CAST(time_id AS UNSIGNED) <  ?
                           ORDER BY CAST(time_id AS UNSIGNED) DESC ");
    $stmt->execute([$userId,$selectCount,$userId,$selectCount,$oldDate,$newDate]);
    $row = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return $row ?: [];
}
//待ち場
function selectDataBa(PDO $pdo, int $userId): ?array {
    $stmt = $pdo->prepare("SELECT LU.id, LU.point_name
                           FROM location_info_point LU
                           INNER JOIN (SELECT U.id, U.username
                                       FROM users U
                                       WHERE U.id = :userid
                                      ) A ON LU.user_id = A.id
                           WHERE LU.is_deleted = 0 AND LU.point_id = 1");
    $stmt->execute([
        ':userid' => $userId
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
//車
function selectDataCar(PDO $pdo, int $userId): ?array {
    $stmt = $pdo->prepare("SELECT LU.id, LU.point_name
                           FROM location_info_point LU
                           INNER JOIN (SELECT U.id, U.username
                                       FROM users U
                                       WHERE U.id = :userid
                                      ) A ON LU.user_id = A.id
                           WHERE LU.is_deleted = 0 AND LU.point_id = 2");
    $stmt->execute([
        ':userid' => $userId
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
//ポイント名変更
function pointRename(PDO $pdo, int $userId, int $pointId, string $name): bool {
  $sql = "UPDATE location_info_point
          SET point_name = :pname, updated_at = NOW()
          WHERE id = :id AND user_id = :userid AND is_deleted = 0";
  $st = $pdo->prepare($sql);
  $st->execute([':pname'=>$name, ':id'=>$pointId, ':userid'=>$userId]);
  return $st->rowCount() > 0; // 0なら対象なし
}
//ポイント削除
function pointDelete(PDO $pdo, int $userId, int $pointId): bool {
    $st = $pdo->prepare(
        "UPDATE location_info_point
         SET is_deleted = 1, updated_at = NOW()
         WHERE is_deleted = 0
           AND id = :pid
           AND user_id = :userid"
    );
    $st->execute([':userid' => $userId, ':pid' => $pointId]);
    return $st->rowCount() > 0; // 0なら対象なし
}



function selectDataDynamo(
    PDO $pdo,
    LocationInfoTable $deviceTable,        // location_info (model_number)
    LocationInfoUserTable $userTable,      // location_info_user (user_id)
    int $userId,
    int $groupId
): array {

    // A) MySQLで「対象一覧」を取る（元SQLのJOIN部分だけ）
    // job=3（デバイス）
    $stmt = $pdo->prepare("
        SELECT P.user_id, P.model_number, G.job
        FROM team_details G
        INNER JOIN products P ON G.subject_id = P.id
        WHERE G.team_id = ?
          AND G.participation = 1 AND G.approval = 1
          AND G.job = 3 AND G.is_deleted = 0
    ");
    $stmt->execute([$groupId]);
    $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // job<>3（ユーザー）
    $stmt = $pdo->prepare("
        SELECT U.id AS user_id, U.username, G.job
        FROM team_details G
        INNER JOIN users U ON G.subject_id = U.id
        WHERE G.team_id = ?
          AND G.participation = 1 AND G.approval = 1
          AND G.job <> 3 AND G.is_deleted = 0
    ");
    $stmt->execute([$groupId]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $modelNumbers = array_values(array_unique(array_map(fn($r)=> (string)$r['model_number'], $devices)));
    $userIds      = array_values(array_unique(array_map(fn($r)=> (string)$r['user_id'], $users)));

    // B) DynamoDBで「最新(LATEST)」を一括取得
    // ※ LocationInfoTable / LocationInfoUserTable 側で LATEST を保存している前提
    $ddbDeviceRaw = $deviceTable->batchGetLatestByModel($modelNumbers);   // Items (typed)
    $ddbUserRaw   = $userTable->batchGetLatestByUser($userIds);          // Items (typed)

    // 取り回ししやすい Map にする
    $latestByModel = [];
    foreach ($ddbDeviceRaw as $it) {
        $u = ddb_unwrap($it);
        if (!empty($u['model_number'])) $latestByModel[$u['model_number']] = $u;
    }

    $latestByUser = [];
    foreach ($ddbUserRaw as $it) {
        $u = ddb_unwrap($it);
        // user_id は N で入ってるので文字列化してキーに
        if (isset($u['user_id'])) $latestByUser[(string)$u['user_id']] = $u;
    }

    // C) point（待ち場/車）は MySQLで最新を取る（今のSQLの3つ目UNION相当）
    $stmt = $pdo->prepare("
        SELECT (CASE WHEN LU.point_id = 1 THEN 4 WHEN LU.point_id = 2 THEN 5 ELSE A.job END) AS jobflag,
               LU.user_id, A.username, LU.lat, LU.lng, LU.alt, LU.acc, LU.alt_acc, LU.stl, LU.time_id, LU.created_at,
               LU.point_name, LU.id AS data_id
        FROM (
          SELECT LU.*,
                 ROW_NUMBER() OVER (PARTITION BY LU.user_id, LU.point_name ORDER BY LU.created_at DESC) AS rn
          FROM location_info_point LU
          WHERE LU.is_deleted = 0 AND LU.point_id <> '' AND LU.point_id IS NOT NULL
        ) LU
        INNER JOIN (
          SELECT U.id, U.username, G.job
          FROM team_details G
          INNER JOIN users U ON G.subject_id = U.id
          WHERE G.team_id = ? AND G.participation = 1 AND approval = 1 AND G.job <> 3 AND G.is_deleted = 0
        ) A ON LU.user_id = A.id
        WHERE LU.rn = 1
    ");
    $stmt->execute([$groupId]);
    $points = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // D) マージして「元のSELECT結果と同じ形」に整形
    $results = [];

    // デバイス（job=3）
    foreach ($devices as $d) {
        $model = (string)$d['model_number'];
        $job   = (int)$d['job'];
        $loc   = $latestByModel[$model] ?? null;
        if (!$loc) continue;

        $timeId = (string)($loc['time_id'] ?? '');
        $results[] = [
            'user_id'      => (int)$d['user_id'],
            'username'     => '',
            'model_number' => $model,
            'lat'          => (string)($loc['lat'] ?? ''),
            'lng'          => (string)($loc['lng'] ?? ''),
            'alt'          => (string)($loc['alt'] ?? ''),
            'acc'          => '',
            'alt_acc'      => '',
            'time_id'      => $timeId,
            'status_flag'  => $job,
            'point_name'   => '',
            'data_id'      => $timeId,   // DynamoDBにAUTO_INCREMENTが無いので代替（time_idをdata_id相当として返す）
            '_sort'        => $timeId,   // ソート用
        ];
    }

    // ユーザー（job<>3）
    foreach ($users as $u) {
        $uid  = (string)$u['user_id'];
        $job  = (int)$u['job'];
        $loc  = $latestByUser[$uid] ?? null;
        if (!$loc) continue;

        $timeId = (string)($loc['time_id'] ?? '');
        $results[] = [
            'user_id'      => (int)$uid,
            'username'     => (string)$u['username'],
            'model_number' => '',
            'lat'          => (string)($loc['lat'] ?? ''),
            'lng'          => (string)($loc['lng'] ?? ''),
            'alt'          => (string)($loc['alt'] ?? ''),
            'acc'          => (string)($loc['acc'] ?? ''),
            'alt_acc'      => (string)($loc['alt_acc'] ?? ''),
            'time_id'      => $timeId,
            'status_flag'  => $job,
            'point_name'   => '',
            'data_id'      => $timeId,
            '_sort'        => $timeId,
        ];
    }

    // point（待ち場/車）
    foreach ($points as $p) {
        $results[] = [
            'user_id'      => (int)$p['user_id'],
            'username'     => (string)$p['username'],
            'model_number' => '',
            'lat'          => (string)$p['lat'],
            'lng'          => (string)$p['lng'],
            'alt'          => (string)$p['alt'],
            'acc'          => (string)$p['acc'],
            'alt_acc'      => (string)$p['alt_acc'],
            'time_id'      => (string)$p['time_id'],
            'status_flag'  => (int)$p['jobflag'],
            'point_name'   => (string)$p['point_name'],
            'data_id'      => (int)$p['data_id'],
            '_sort'        => (string)$p['time_id'], // created_atでやりたいならcreated_atを使う
        ];
    }

    // E) ORDER BY created_at DESC 相当 → time_id（YYYYMMDDHHMMSS）で降順
    usort($results, fn($a,$b) => strcmp($b['_sort'], $a['_sort']));

    // ソート用キーは消す
    foreach ($results as &$r) unset($r['_sort']);

    return $results;
}
?>