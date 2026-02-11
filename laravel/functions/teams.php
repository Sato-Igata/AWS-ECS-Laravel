<?php
//グループ存在確認
function groupNameTest(PDO $pdo, string $groupName): ?array {
    $stmt = $pdo->prepare("SELECT *
                           FROM teams G 
                           WHERE G.team_name = :groupname AND G.is_deleted = 0 ");
    $stmt->execute([
        ':groupname' => $groupName
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

//グループ&ユーザー確認
function getGroup(PDO $pdo, int $userId, int $groupId): ?array {
    $stmt = $pdo->prepare("SELECT G.team_id, G.team_name, G.password_hash
                           FROM teams G 
                           LEFT JOIN users U ON G.owner_user_id = U.id
                           WHERE G.id = :groupid AND G.owner_user_id = :userid AND G.is_deleted = 0 ");
    $stmt->execute([
        ':userid' => $userId,
        ':groupid' => $groupId
    ]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

//グループ&ユーザー情報
function getGroupData(PDO $pdo, int $userId, int $groupId): ?array {
    $stmt = $pdo->prepare("SELECT G.team_id, G.team_name,
                           CASE WHEN G.owner_user_id = :userid 
                                THEN 1 
                                ELSE 2 
                           END AS flag
                           FROM teams G 
                           WHERE G.id = :groupid AND G.is_deleted = 0 ");
    $stmt->execute([
        ':userid' => $userId,
        ':groupid' => $groupId
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row !== false ? $row : null;
}

//グループ&ユーザー確認
function getGroupTeamId(PDO $pdo, string $textId): ?array {
    $stmt = $pdo->prepare("SELECT *
                           FROM teams
                           WHERE team_id = :textId AND is_deleted = 0 ");
    $stmt->execute([
        ':textId' => $textId
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

//グループリスト
function getGroupList(PDO $pdo, int $userId): ?array {
  try {
    $stmt = $pdo->prepare("SELECT T.id, T.team_id, T.team_name, 
                                  CASE 
                                    WHEN sub.stdata IS NULL THEN 0
                                    ELSE sub.stdata
                                  END AS stdata,
                                  CASE 
                                    WHEN T.owner_user_id = ? THEN 1 
                                    ELSE 2 
                                  END AS flag
                           FROM teams T
                           LEFT JOIN 
                           (SELECT data_id,stdata,subject_id
                           FROM (
                             SELECT
                               G.job AS stdata,
                               G.subject_id,
                               G.team_id AS data_id,
                               ROW_NUMBER() OVER (
                                   PARTITION BY G.team_id
                                   ORDER BY
                                   (G.job <> 3
                                    AND G.approval = 1
                                    AND G.is_deleted = 0
                                    AND G.subject_id = ?) DESC,
                                   (G.subject_id = ?) DESC,
                                   G.id
                               ) AS rn
                             FROM team_details G
                             WHERE  (G.job <> 3 
                                AND  G.approval = 1 
                                AND  G.is_deleted = 0
                                AND  G.subject_id = ? )
                                OR  (G.job = 3 
                                AND  G.approval = 1 
                                AND  G.is_deleted = 0
                                AND  G.subject_id IN (SELECT P.id 
                                                      FROM products P
                                                      WHERE P.user_id = ?
                                                        AND P.is_deleted = 0))
                           ) AS x
                           WHERE x.rn = 1 ) sub
                           ON T.id = sub.data_id 
                           WHERE T.owner_user_id = ? ");
    $stmt->execute([$userId, $userId, $userId, $userId, $userId, $userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
  } catch (PDOException $e) {
    error_log('getGroupList PDO ERROR: ' . $e->getMessage());
    return null;
  }
}

//グループリスト
function getGroupRequestList(PDO $pdo, int $userId): ?array {
    $stmt = $pdo->prepare("SELECT T.team_id, T.team_name, H.username,
                                 (CASE 
                                  WHEN G.job = 3 
                                  THEN (SELECT P.model_name
                                        FROM products P
                                        WHERE P.id = G.subject_id
                                          AND P.user_id = ?
                                          AND P.is_deleted = 0)
                                  ELSE (SELECT U.username
                                        FROM users U
                                        WHERE U.id = G.subject_id
                                          AND U.id = ?
                                          AND U.is_deleted = 0) 
                                  END ) AS objectname
                           FROM team_details G 
                           LEFT JOIN teams T ON G.team_id = T.id
                           LEFT JOIN users H ON H.id = T.owner_user_id
                           WHERE ( G.subject_id = ? 
                             AND   G.job <> 3 
                             AND   G.approval = 0 
                             AND   G.is_deleted = 0
                             AND   T.is_deleted = 0 )
                             OR    G.subject_id IN (SELECT D.id
                                                    FROM products D
                                                    WHERE D.user_id = ?
                                                      AND D.is_deleted = 0)
                             AND   G.job = 3 
                             AND   G.approval = 0 
                             AND   G.is_deleted = 0
                             AND   T.is_deleted = 0 ");
    $stmt->execute([$userId, $userId, $userId, $userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

//ユーザー（グループ情報）
function getGroupUserData(PDO $pdo, int $userId, int $groupId): ?array {
    $stmt = $pdo->prepare("SELECT G.subject_id, U.username, 
                                  CASE WHEN U.id = GP.owner_user_id THEN 1 ELSE 2 END AS flag
                           FROM team_details AS G
                           JOIN users AS U ON U.id = G.subject_id
                           JOIN teams AS GP ON GP.id = G.team_id
                           WHERE G.team_id = :groupid 
                             AND G.job <> 3 
                             AND U.is_deleted = 0 
                             AND G.is_deleted = 0
                             AND GP.is_deleted = 0 
                             AND G.subject_id = :userid 
                           LIMIT 1");
    $stmt->execute([
        ':userid' => $userId,
        ':groupid' => $groupId
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

//ユーザー（グループ）
function getMapUser(PDO $pdo, int $groupId): ?array {
    $sql = "SELECT G.subject_id, G.job, G.participation, G.approval,
            CONCAT((SELECT U.username FROM users U WHERE U.id = G.subject_id AND U.is_deleted = 0),
            (CASE WHEN G.job = 1 THEN '(待ち)' ELSE '(勢子)' END)) AS object_name
            FROM team_details G
            LEFT JOIN teams T ON T.id = G.team_id
            WHERE G.team_id       = :groupid
              AND G.job <> 3
              AND G.participation = 1
              AND G.approval      = 1
              AND G.is_deleted    = 0
              AND T.is_deleted    = 0 ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':groupid' => $groupId
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

//ユーザー（グループ）
function getGroupUser(PDO $pdo, int $groupId): ?array {
    $sql = "SELECT 
            G.subject_id, G.job, G.participation, G.approval,
            CASE 
                WHEN G.job <> 3
                THEN (SELECT username FROM users WHERE id = G.subject_id AND is_deleted   = 0)
                ELSE (SELECT model_name FROM products WHERE id = G.subject_id AND is_deleted   = 0)
            END AS object_name,
            CASE 
                WHEN G.job = 1
                THEN '待ち'
                WHEN G.job = 2
                THEN '勢子'
                ELSE '犬'
            END AS status_name,
            CASE 
                WHEN G.participation = 1
                THEN '参加'
                ELSE '不参加'
            END AS participation_name,
            CASE 
                WHEN G.approval = 1
                THEN '承認'
                ELSE '未承認' 
            END AS approval_name,
            CASE 
                WHEN G.subject_id = T.owner_user_id
                THEN 1 
                ELSE 2 
            END AS flag
            FROM team_details G
            LEFT JOIN teams T ON T.id = G.team_id
            WHERE G.team_id      = :groupid
              AND G.approval     = 1
              AND G.is_deleted   = 0
              AND T.is_deleted   = 0 ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':groupid' => $groupId
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
//デバイス（グループ）
function getGroupDevice(PDO $pdo, int $groupId): ?array {
    $stmt = $pdo->prepare("SELECT P.model_number, P.model_name, P.id, G.participation
                           FROM team_details G
                           INNER JOIN products P ON G.subject_id = P.id
                           WHERE G.team_id = :groupid AND G.approval = 1 
                             AND G.job = 3 AND G.is_deleted = 0 AND P.is_deleted = 0 ");
    $stmt->execute([
        ':groupid' => $groupId
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
//ユーザー（グループ）リクエスト承認待ち
function getRequestGroupUser(PDO $pdo, int $groupId): ?array {
    $sql = "SELECT 
            G.subject_id, G.job, G.participation, G.approval,
            CASE 
                WHEN G.job <> 3
                THEN (SELECT username FROM users WHERE id = G.subject_id AND is_deleted   = 0)
                ELSE (SELECT model_name FROM products WHERE id = G.subject_id AND is_deleted   = 0)
            END AS object_name
            FROM team_details G
            WHERE G.team_id      = :groupid
              AND G.approval     = 0
              AND G.is_deleted   = 0 ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':groupid' => $groupId
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
//ユーザー（グループ）参加
function selectDataGroupUser(PDO $pdo, int $userId, int $groupId): ?array {
    $sql = "SELECT 
            G.subject_id, G.job, G.participation, G.approval,
            U.username,
            CASE 
                WHEN G.subject_id = T.owner_user_id
                THEN 1 
                ELSE 2 
            END AS flag
            FROM team_details G
            INNER JOIN users U ON U.id = G.subject_id
            LEFT JOIN teams T ON T.id = G.team_id
            WHERE G.subject_id   = :userid
              AND G.team_id      = :groupid
              AND G.approval     = 1
              AND G.job       <> 3
              AND G.is_deleted   = 0
              AND U.is_deleted   = 0";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':userid'  => $userId,
        ':groupid' => $groupId
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
//デバイス（グループ）参加
function selectDataGroupDevice(PDO $pdo, int $groupId): ?array {
    $stmt = $pdo->prepare("SELECT P.model_number, P.model_name, P.id
                           FROM team_details G
                           INNER JOIN products P ON G.subject_id = P.id
                           WHERE G.team_id = :groupid 
                             AND G.participation = 1 AND G.approval = 1 
                             AND G.job = 3 AND G.is_deleted = 0 AND P.is_deleted = 0 ");
    $stmt->execute([
        ':groupid' => $groupId
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
//グループ作成
function insertDataGroup(PDO $pdo, int $userId, string $textId, string $name, string $pass): ?int {
    $stmt = $pdo->prepare("INSERT INTO teams (owner_user_id, team_id, team_name, password_hash)
                           VALUES (:userid, :teamid, :teamname, :teampass)");
    $ok = $stmt->execute([
        ':userid'   => $userId,
        ':teamid'   => $textId,
        ':teamname' => $name,
        ':teampass' => $pass
    ]);
    if (!$ok) return null;
    return (int)$pdo->lastInsertId();
}
//ユーザー（グループ）リクエスト送信
function insertDataGroupUser(PDO $pdo, int $userId, int $groupId, int $st): bool {
    $stmt = $pdo->prepare("INSERT INTO team_details (subject_id, team_id, job) 
                           VALUES (:userid, :groupid, :st)");
    return $stmt->execute([
        ':userid'  => $userId,
        ':groupid' => $groupId,
        ':st'      => $st
    ]);
}
//デバイス（グループ）リクエスト送信
function insertDataGroupDevice(PDO $pdo, int $deviceId, int $groupId, int $st): bool {
    $stmt = $pdo->prepare("INSERT INTO team_details (subject_id, team_id, job) 
                           VALUES (:deviceid, :groupid, :st)");
    return $stmt->execute([
        ':deviceid' => $deviceId,
        ':groupid'  => $groupId,
        ':st'       => $st
    ]);
}
//グループメンバー
function selectGroupMember(PDO $pdo, int $groupId): ?array {
    $stmt = $pdo->prepare("SELECT id, G.subject_id, job, 
                                  CASE WHEN G.job <> 3
                                       THEN (SELECT username FROM users WHERE id = G.subject_id AND is_deleted   = 0)
                                       ELSE (SELECT model_name FROM products WHERE id = G.subject_id AND is_deleted   = 0)
                                  END AS object_name
                           FROM team_details G
                           WHERE G.team_id = :groupid 
                             AND G.approval = 1 
                             AND G.is_deleted = 0 ");
    $stmt->execute([
        ':groupid' => $groupId
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
//グループ　待ち／勢子
function updateGroupStatus(PDO $pdo, int $id, int $groupId, int $st): bool {
    $stmt = $pdo->prepare("UPDATE team_details SET job = :st
                            WHERE job <> 3 AND subject_id = :id AND team_id = :groupid AND is_deleted = 0 ");
    return $stmt->execute([
        ':id'      => $id,
        ':groupid' => $groupId,
        ':st'      => $st
    ]);
}
//グループ　参加／不参加
function updateGroupParticipation(PDO $pdo, int $id, int $groupId, int $st, int $pt): bool {
    $stmt = $pdo->prepare("UPDATE team_details SET participation = :pt 
                            WHERE job = :st AND subject_id = :id AND team_id = :groupid AND is_deleted = 0 ");
    return $stmt->execute([
        ':id'      => $id,
        ':groupid' => $groupId,
        ':st'      => $st,
        ':pt'      => $pt
    ]);
}
//グループ　リクエスト承認
function updateGroupApproval(PDO $pdo, int $id, int $groupId, int $st): bool {
    $stmt = $pdo->prepare("UPDATE team_details SET approval = 1 
                            WHERE approval = 0 AND job = :st AND subject_id = :id AND team_id = :groupid AND is_deleted = 0 ");
    return $stmt->execute([
        ':id'      => $id,
        ':groupid' => $groupId,
        ':st'      => $st
    ]);
}
//グループ　ユーザー削除解除
function updateGroupUserDeleteCancellation(PDO $pdo, int $id, int $groupId, int $st): bool {
    $stmt = $pdo->prepare("UPDATE team_details SET is_deleted = 0, job = :st, approval = 0
                            WHERE job <> 3 AND subject_id = :id AND team_id = :groupid AND is_deleted = 1 ");
    return $stmt->execute([
        ':id'      => $id,
        ':groupid' => $groupId,
        ':st'      => $st
    ]);
}
//グループ　デバイス削除解除
function updateGroupDeviceDeleteCancellation(PDO $pdo, int $id, int $groupId, int $st): bool {
    $stmt = $pdo->prepare("UPDATE team_details SET is_deleted = 0, job = :st, approval = 0
                            WHERE job = 3 AND subject_id = :id AND team_id = :groupid AND is_deleted = 1 ");
    return $stmt->execute([
        ':id'      => $id,
        ':groupid' => $groupId,
        ':st'      => $st
    ]);
}
//グループ　情報更新
function updateGroupData(PDO $pdo, int $userId, int $groupId, string $groupName, string $groupPass): bool {
    $stmt = $pdo->prepare("UPDATE teams SET team_name = :gname, password_hash = :gpass 
                            WHERE id = :groupid AND  owner_user_id = :userid AND is_deleted = 0 ");
    return $stmt->execute([
        ':userid'  => $userId,
        ':groupid' => $groupId,
        ':gname'    => $groupName,
        ':gpass'    => $groupPass
    ]);
}
//グループ　ユーザー（またはデバイス）削除
function deleteGroupDataDelete(PDO $pdo, int $id, int $groupId): bool {
    $stmt = $pdo->prepare("DELETE FROM team_details 
                            WHERE approval = 1 AND subject_id = :id AND team_id = :groupid AND is_deleted = 0 ");
    return $stmt->execute([
        ':id' => $id,
        ':groupid' => $groupId
    ]);
}
//グループ　削除
function deleteGroupDelete(PDO $pdo, int $userId, int $groupId): bool {
    $stmt = $pdo->prepare("DELETE FROM teams 
                            WHERE owner_user_id = :userid AND id = :groupid AND is_deleted = 0 ");
    return $stmt->execute([
        ':userid' => $userId,
        ':groupid' => $groupId
    ]);
}
//グループ詳細　削除
function deleteGroupDetailsDelete(PDO $pdo, int $groupId): bool {
    $stmt = $pdo->prepare("DELETE FROM team_details 
                            WHERE team_id = :groupid AND is_deleted = 0 ");
    return $stmt->execute([
        ':groupid' => $groupId
    ]);
}

//グループメンバー　削除
function deleteMember(PDO $pdo, int $groupId, int $memberId,int $statusId): bool {
    $stmt = $pdo->prepare("UPDATE team_details 
                            SET  is_deleted = 1
                            WHERE id = :memberid
                              AND team_id    = :groupid 
                              AND job   = :jobid 
                              AND is_deleted = 0 ");
    return $stmt->execute([
        ':groupid' => $groupId,
        ':memberid' => $memberId,
        ':jobid' => $statusId
    ]);
}

//ユーザー（グループ）
function requestCheck(PDO $pdo, int $userId, int $groupId): ?array {
    $sql = "SELECT *
            FROM team_details
            WHERE subject_id   = :userid
              AND team_id      = :groupid
              AND job         <> 3
              AND approval     = 1
              AND is_deleted   = 0 ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':userid' => $userId,
        ':groupid' => $groupId
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

//ユーザー（グループ）
function checkData(PDO $pdo, int $id, int $groupId, int $dataStatus): ?array {
    $sql = "SELECT is_deleted, team_id, subject_id 
            FROM team_details
            WHERE subject_id   = :id
              AND team_id      = :groupid
              AND (
                (:datast1 = 1 AND job <> 3)
              OR
                (:datast2 <> 1 AND job = 3)
              )";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id' => $id,
        ':groupid' => $groupId,
        ':datast1' => $dataStatus,
        ':datast2' => $dataStatus,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row !== false ? $row : null;
}
?>