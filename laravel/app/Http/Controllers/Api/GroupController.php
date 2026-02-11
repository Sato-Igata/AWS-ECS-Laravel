<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cookie;

class GroupController extends Controller
{
    public function groupCreate(Request $request)
    {
        // ① セッションログイン確認（userId確認）
        $userId = Auth::id();
        if (!$userId) {
            return response()->json(
                ['status' => 'error', 'error' => '未ログインです', 'status' => 2],
                401,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }
        // ② Cookie の refresh_token 確認
        $refreshPlain = $request->cookie('refresh_token'); // Cookie名はあなたの実装に合わせる
        if (!$refreshPlain) {
            return response()->json(
                ['status' => 'error', 'error' => 'トークンがありません', 'status' => 2],
                401, [], JSON_UNESCAPED_UNICODE
            );
        }
        // ③ DB の hash と一致 + 期限チェック
        try {
            $refreshHash = hash('sha256', $refreshPlain);
            $row = DB::table('users')
                ->select('refresh_token_hash', 'refresh_token_expires_at', 'is_deleted')
                ->where('id', $userId)
                ->first();
            if (!$row || (int)$row->is_deleted === 1) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return response()->json(
                    ['status' => 'error', 'error' => 'ユーザーが見つかりません', 'status' => 2],
                    401, [], JSON_UNESCAPED_UNICODE
                );
            }
            if (empty($row->refresh_token_hash) || !hash_equals($row->refresh_token_hash, $refreshHash)) {
                // 不一致：盗用・古いCookie・別端末など
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return response()
                    ->json(['status' => 'error', 'error' => 'トークンが一致しません', 'status' => 2], 401, [], JSON_UNESCAPED_UNICODE)
                    ->withoutCookie('refresh_token');
            }
            // 期限（NULL対策込み）
            if (empty($row->refresh_token_expires_at) || now()->gte(\Carbon\Carbon::parse($row->refresh_token_expires_at))) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return response()
                    ->json(['status' => 'error', 'error' => 'トークンの有効期限が切れています', 'status' => 2], 401, [], JSON_UNESCAPED_UNICODE)
                    ->withoutCookie('refresh_token');
            }
        } catch (\Throwable $e) {
            \Log::error('token check failed', ['e' => $e->getMessage()]);
            return response()->json(['status'=>'error','error'=>'トークン検証でエラーが発生しました', 'status' => 2], 500, [], JSON_UNESCAPED_UNICODE);
        }

        $validated = $request->validate([
            'name'      => ['required', 'string', 'max:255'],
            'pass'      => ['required', 'string', 'max:255'], // pass
            'groupid'   => ['required', 'integer'],          // 1 or 2
            'bool'      => ['boolean'],
            'devicelist'=> ['array'],
            'devicelist.*' => ['integer'],
        ]);

        require_once base_path('functions/teams.php');
        $pdo = DB::connection()->getPdo();

        $groupName     = trim($validated['name']);
        $groupPass     = trim($validated['pass']);
        $groupStatus   = (int)$validated['groupid'];
        $participation = (bool)($validated['bool'] ?? true);
        $deviceIds     = $validated['devicelist'] ?? [];
        date_default_timezone_set('Asia/Tokyo');
        $timeId = trim((string)(date("YmdHis")));
        try {
            // TODO: 本来は team_id をランダム生成すべき（今は仮のまま）
            $textId = 'testetsd'. $timeId;

            $newTeamId = insertDataGroup($pdo, $userId, $textId, $groupName, $groupPass);
            if (!$newTeamId) {
                return response()->json(['status'=>'error','error'=>'作成に失敗しました。'], 500);
            }

            if ($participation === true) {
                $ok = insertDataGroupUser($pdo, $userId, $newTeamId, $groupStatus);
                if (!$ok) return response()->json(['status'=>'error','error'=>'作成に失敗しました。'], 500);

                $ok = updateGroupApproval($pdo, $userId, $newTeamId, $groupStatus);
                if (!$ok) return response()->json(['status'=>'error','error'=>'承認に失敗しました。'], 500);
            }

            foreach ($deviceIds as $deviceId) {
                $ok = insertDataGroupDevice($pdo, (int)$deviceId, $newTeamId, 3);
                if (!$ok) return response()->json(['status'=>'error','error'=>'作成に失敗しました。'], 500);

                $ok = updateGroupApproval($pdo, (int)$deviceId, $newTeamId, 3);
                if (!$ok) return response()->json(['status'=>'error','error'=>'承認に失敗しました。'], 500);
            }

            return response()->json([
                'status' => 'success',
                'id'     => $textId,
                'name'   => $groupName,
                'pass'   => $groupPass,
                'status' => $groupStatus,
                'bool'   => $participation,
                'device' => $deviceIds
            ], 200, [], JSON_UNESCAPED_UNICODE);

        } catch (\Throwable $e) {
            \Log::error('groupCreate failed', ['e' => $e->getMessage()]);
            return response()->json(['status'=>'error','error'=>'サーバーエラーが発生しました'], 500);
        }
    }
    
    public function groupEdit(Request $request)
    {
        // ① セッションログイン確認（userId確認）
        $userId = Auth::id();
        if (!$userId) {
            return response()->json(
                ['status' => 'error', 'error' => '未ログインです', 'status' => 2],
                401,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }
        // ② Cookie の refresh_token 確認
        $refreshPlain = $request->cookie('refresh_token'); // Cookie名はあなたの実装に合わせる
        if (!$refreshPlain) {
            return response()->json(
                ['status' => 'error', 'error' => 'トークンがありません', 'status' => 2],
                401, [], JSON_UNESCAPED_UNICODE
            );
        }
        // ③ DB の hash と一致 + 期限チェック
        try {
            $refreshHash = hash('sha256', $refreshPlain);
            $row = DB::table('users')
                ->select('refresh_token_hash', 'refresh_token_expires_at', 'is_deleted')
                ->where('id', $userId)
                ->first();
            if (!$row || (int)$row->is_deleted === 1) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return response()->json(
                    ['status' => 'error', 'error' => 'ユーザーが見つかりません', 'status' => 2],
                    401, [], JSON_UNESCAPED_UNICODE
                );
            }
            if (empty($row->refresh_token_hash) || !hash_equals($row->refresh_token_hash, $refreshHash)) {
                // 不一致：盗用・古いCookie・別端末など
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return response()
                    ->json(['status' => 'error', 'error' => 'トークンが一致しません', 'status' => 2], 401, [], JSON_UNESCAPED_UNICODE)
                    ->withoutCookie('refresh_token');
            }
            // 期限（NULL対策込み）
            if (empty($row->refresh_token_expires_at) || now()->gte(\Carbon\Carbon::parse($row->refresh_token_expires_at))) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return response()
                    ->json(['status' => 'error', 'error' => 'トークンの有効期限が切れています', 'status' => 2], 401, [], JSON_UNESCAPED_UNICODE)
                    ->withoutCookie('refresh_token');
            }
        } catch (\Throwable $e) {
            \Log::error('token check failed', ['e' => $e->getMessage()]);
            return response()->json(['status'=>'error','error'=>'トークン検証でエラーが発生しました', 'status' => 2], 500, [], JSON_UNESCAPED_UNICODE);
        }
        $validated = $request->validate([
            'textid'    => ['required', 'string', 'max:255'],
            'name'      => ['required', 'string', 'max:255'],
            'text'      => ['required', 'string', 'max:255'],
            'str'       => ['required', 'string', 'max:255'],
            'groupid'   => ['required', 'integer'],          // 1 or 2
            'bool'      => ['boolean'],
            'devicelist'=> ['array'],
            'devicelist.*' => ['integer'],
            'subjectlist'=> ['array'],
            'subjectlist.*' => ['integer'],
            'statuslist'=> ['array'],
            'statuslist.*' => ['integer'],
            'checkedlist'=> ['array'],
            'checkedlist.*' => ['integer'],
        ]);

        require_once base_path('functions/teams.php');
        $pdo = DB::connection()->getPdo();
        $groupTextId   = trim((string)($validated['textid'] ?? ''));
        $groupName     = trim((string)($validated['name'] ?? ''));
        $groupPass     = trim((string)($validated['text'] ?? ''));
        $newGroupPass  = trim((string)($validated['str'] ?? ''));
        $groupStatus   = (int)($validated['groupid'] ?? 0);
        $participation = (bool)($validated['bool'] ?? true);
        $deviceIds     = $validated['devicelist'] ?? [];
        $subjectList   = $validated['subjectlist'] ?? [];
        $statusList    = $validated['statuslist'] ?? [];
        $checkedList   = $validated['checkedlist'] ?? [];
        if ($groupTextId === '' || mb_strlen($groupTextId) > 50) {
            return response()->json(
                ['status' => 'error', 'error' => '名称は1〜50文字で入力してください'],
                404,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }
        if ($groupName === '' || mb_strlen($groupName) > 50) {
            return response()->json(
                ['status' => 'error', 'error' => '名称は1〜50文字で入力してください'],
                404,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }
        if ($groupPass === '' || mb_strlen($groupPass) > 50) {
            return response()->json(
                ['status' => 'error', 'error' => '名称は1〜50文字で入力してください'],
                404,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }
        if ($newGroupPass === '' || mb_strlen($newGroupPass) > 50) {
            return response()->json(
                ['status' => 'error', 'error' => '名称は1〜50文字で入力してください'],
                404,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }
        try {
            $testcount = 0;
            $results = getGroupTeamId($pdo, $groupTextId);
            if (!$results) {
                return response()->json(
                    ['status' => 'error', 'error' => 'グループが存在しません。'],
                    404,
                    [],
                    JSON_UNESCAPED_UNICODE
                );
            }
            $testcount += 1;
            $TeamIds  = array_column($results, 'id');
            $passList = array_column($results, 'password_hash');
            $flag = 0;
            $newTeamId = null;
            $len = count($passList);
            for ($i = 0; $i < $len; $i++) {
              if ($groupPass === $passList[$i]) {  // パス一致チェック
                  $newTeamId = $TeamIds[$i];       // 対応する TeamId をセット
                  $flag = 1;
                  // break;                           // 見つかったらループ終了
              }
            }
            $testcount += 1;
            if ($flag !== 1) {
                return response()->json(
                    ['status' => 'error', 'error' => 'グループが存在しません。'],
                    404,
                    [],
                    JSON_UNESCAPED_UNICODE
                );
            }
            $testcount += 1;
            $ok = updateGroupData($pdo, $userId, $newTeamId, $groupName, $newGroupPass);
            if (!$ok) {
                return response()->json(
                    ['status' => 'error', 'error' => 'グループ情報の更新に失敗しました。'],
                    404,
                    [],
                    JSON_UNESCAPED_UNICODE
                );
            }
            $testcount += 1;
            $count = 0;
            $createCount = 0;
            if ($participation == true) {
              $count += 1;
              $testcount += 1;
              $checkUser = checkData($pdo, $userId, $newTeamId, 1);
              $testcount += 1;
              $is_deleted = $checkUser['is_deleted']   ?? false;
              if (!$checkUser) {
                $testcount += 1;
                $ok = insertDataGroupUser($pdo, $userId, $newTeamId, $groupStatus);
                if (!$ok) {
                    return response()->json(
                        ['status' => 'error', 'error' => 'グループ情報の更新に失敗しました。'],
                        404,
                        [],
                        JSON_UNESCAPED_UNICODE
                    );
                }
                $ok = updateGroupApproval($pdo, $userId, $newTeamId, $groupStatus);
                if (!$ok) {
                    return response()->json(
                        ['status' => 'error', 'error' => '承認に失敗しました。'],
                        404,
                        [],
                        JSON_UNESCAPED_UNICODE
                    );
                }
              } else {
                $createCount += 1;
                if ($is_deleted) {
                  $testcount += 1;
                  $ok = updateGroupUserDeleteCancellation($pdo, $userId, $newTeamId, $groupStatus);
                  if (!$ok) {
                    return response()->json(
                        ['status' => 'error', 'error' => 'グループ情報の更新に失敗しました。'],
                        404,
                        [],
                        JSON_UNESCAPED_UNICODE
                    );
                  }
                  $ok = updateGroupApproval($pdo, $userId, $newTeamId, $groupStatus);
                  if (!$ok) {
                    return response()->json(
                        ['status' => 'error', 'error' => '承認に失敗しました。'],
                        404,
                        [],
                        JSON_UNESCAPED_UNICODE
                    );
                  }
                }
              }
            }
            $ok = updateGroupStatus($pdo, $userId, $newTeamId, $groupStatus);
            if (!$ok) {
                return response()->json(
                    ['status' => 'error', 'error' => 'ステータス変更に失敗しました。'],
                    404,
                    [],
                    JSON_UNESCAPED_UNICODE
                );
            }
            foreach ($deviceIds as $deviceId) {
                $count += 1;
                $checkDevice = checkData($pdo, $deviceId, $newTeamId, 2);
                $is_deleted = $checkDevice['is_deleted']   ?? false;
                if (!$checkDevice) {
                    $ok = insertDataGroupDevice($pdo, $deviceId, $newTeamId, 3);
                    if (!$ok) {
                        return response()->json(
                            ['status' => 'error', 'error' => 'グループ情報の更新に失敗しました。'],
                            404,
                            [],
                            JSON_UNESCAPED_UNICODE
                        );
                    }
                    $ok = updateGroupApproval($pdo, $deviceId, $newTeamId, 3);
                    if (!$ok) {
                        return response()->json(
                            ['status' => 'error', 'error' => '承認に失敗しました。'],
                            404,
                            [],
                            JSON_UNESCAPED_UNICODE
                        );
                    }
                } else {
                    $createCount += 1;
                    if ($is_deleted) {
                        $ok = updateGroupDeviceDeleteCancellation($pdo, $deviceId, $newTeamId, 3);
                        if (!$ok) {
                            return response()->json(
                                ['status' => 'error', 'error' => 'グループ情報の更新に失敗しました。'],
                                404,
                                [],
                                JSON_UNESCAPED_UNICODE
                            );
                        }
                        $ok = updateGroupApproval($pdo, $deviceId, $newTeamId, 3);
                        if (!$ok) {
                            return response()->json(
                                ['status' => 'error', 'error' => '承認に失敗しました。'],
                                404,
                                [],
                                JSON_UNESCAPED_UNICODE
                            );
                        }
                    }
                }
            }
            $len = count($subjectList);
            for ($i = 0; $i < $len; $i++) {
                $ok = updateGroupParticipation($pdo, $subjectList[$i], $newTeamId, $statusList[$i], $checkedList[$i]);
            }

            $message = '';
            return response()->json([
                'status' => 'success',
                'message' => $message,
            ], 200, [], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            \Log::error('groupEdit failed', ['e' => $e->getMessage()]);
            return response()->json(['status'=>'error','error'=>'サーバーエラーが発生しました'], 500);
        }
    }
    
    public function groupJoin(Request $request)
    {
        // ① セッションログイン確認（userId確認）
        $userId = Auth::id();
        if (!$userId) {
            return response()->json(
                ['status' => 'error', 'error' => '未ログインです', 'status' => 2],
                401,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }
        // ② Cookie の refresh_token 確認
        $refreshPlain = $request->cookie('refresh_token'); // Cookie名はあなたの実装に合わせる
        if (!$refreshPlain) {
            return response()->json(
                ['status' => 'error', 'error' => 'トークンがありません', 'status' => 2],
                401, [], JSON_UNESCAPED_UNICODE
            );
        }
        // ③ DB の hash と一致 + 期限チェック
        try {
            $refreshHash = hash('sha256', $refreshPlain);
            $row = DB::table('users')
                ->select('refresh_token_hash', 'refresh_token_expires_at', 'is_deleted')
                ->where('id', $userId)
                ->first();
            if (!$row || (int)$row->is_deleted === 1) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return response()->json(
                    ['status' => 'error', 'error' => 'ユーザーが見つかりません', 'status' => 2],
                    401, [], JSON_UNESCAPED_UNICODE
                );
            }
            if (empty($row->refresh_token_hash) || !hash_equals($row->refresh_token_hash, $refreshHash)) {
                // 不一致：盗用・古いCookie・別端末など
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return response()
                    ->json(['status' => 'error', 'error' => 'トークンが一致しません', 'status' => 2], 401, [], JSON_UNESCAPED_UNICODE)
                    ->withoutCookie('refresh_token');
            }
            // 期限（NULL対策込み）
            if (empty($row->refresh_token_expires_at) || now()->gte(\Carbon\Carbon::parse($row->refresh_token_expires_at))) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return response()
                    ->json(['status' => 'error', 'error' => 'トークンの有効期限が切れています', 'status' => 2], 401, [], JSON_UNESCAPED_UNICODE)
                    ->withoutCookie('refresh_token');
            }
        } catch (\Throwable $e) {
            \Log::error('token check failed', ['e' => $e->getMessage()]);
            return response()->json(['status'=>'error','error'=>'トークン検証でエラーが発生しました', 'status' => 2], 500, [], JSON_UNESCAPED_UNICODE);
        }
        $validated = $request->validate([
            'name'      => ['required', 'string', 'max:255'],
            'text'      => ['required', 'string', 'max:255'],
            'groupid'   => ['required', 'integer'],          // 1 or 2
            'bool'      => ['boolean'],
            'devicelist'=> ['array'],
            'devicelist.*' => ['integer'],
        ]);
        require_once base_path('functions/teams.php');
        $pdo = DB::connection()->getPdo();
        $groupTextId   = trim((string)($validated['name'] ?? ''));
        $groupPass     = trim((string)($validated['text'] ?? ''));
        $groupStatus   = (int)($validated['groupid'] ?? 0);
        $participation = (bool)($validated['bool'] ?? true);
        $deviceIds     = $validated['devicelist'] ?? [];   
        if ($groupTextId === '' || mb_strlen($groupTextId) > 50) {
            return response()->json(
                ['status' => 'error', 'error' => '名称は1〜50文字で入力してください'],
                404,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }
        if ($groupPass === '' || mb_strlen($groupPass) > 50) {
            return response()->json(
                ['status' => 'error', 'error' => '名称は1〜50文字で入力してください'],
                404,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }
        try {
          $results = getGroupTeamId($pdo, $groupTextId);
          if (!$results) {
            return response()->json(
                ['status' => 'error', 'error' => 'グループが存在しません。'],
                404,
                [],
                JSON_UNESCAPED_UNICODE
            );
          }
          $TeamIds  = array_column($results, 'id');
          $passList = array_column($results, 'password_hash');
          $flag = 0;
          $newTeamId = null;
          $len = count($passList);
          for ($i = 0; $i < $len; $i++) {
            if ($groupPass === $passList[$i]) {  // パス一致チェック
                $newTeamId = $TeamIds[$i];       // 対応する TeamId をセット
                $flag = 1;
                // break;                           // 見つかったらループ終了
            }
          }
          if ($flag !== 1) {
            return response()->json(
                ['status' => 'error', 'error' => 'グループが存在しません。'],
                404,
                [],
                JSON_UNESCAPED_UNICODE
            );
          }
          $count = 0;
          $createCount = 0;
          if ($participation == true) {
            $count += 1;
            $checkUser = checkData($pdo, $userId, $newTeamId, 1);
            $is_deleted = $checkUser['is_deleted']   ?? false;
            if (!$checkUser) {
              $ok = insertDataGroupUser($pdo, $userId, $newTeamId, $groupStatus);
              if (!$ok) {
                return response()->json(
                    ['status' => 'error', 'error' => 'リクエスト送信に失敗しました。'],
                    404,
                    [],
                    JSON_UNESCAPED_UNICODE
                );
              }
            } else {
              $createCount += 1;
              if ($is_deleted) {
                $ok = updateGroupUserDeleteCancellation($pdo, $userId, $newTeamId, $groupStatus);
                if (!$ok) {
                    return response()->json(
                        ['status' => 'error', 'error' => 'グループ情報の更新に失敗しました。'],
                        404,
                        [],
                        JSON_UNESCAPED_UNICODE
                    );
                }
              }
            }
          }
          $ok = updateGroupStatus($pdo, $userId, $newTeamId, $groupStatus);
          if (!$ok) {
            return response()->json(
                ['status' => 'error', 'error' => 'ステータス変更に失敗しました。'],
                404,
                [],
                JSON_UNESCAPED_UNICODE
            );
          }
          foreach ($deviceIds as $deviceId) {
            $count += 1;
            $checkDevice = checkData($pdo, $deviceId, $newTeamId, 2);
            $is_deleted = $checkDevice['is_deleted']   ?? false;
            if (!$checkDevice) {
              $ok = insertDataGroupDevice($pdo, $deviceId, $newTeamId, 3);
              if (!$ok) {
                return response()->json(
                    ['status' => 'error', 'error' => 'リクエスト送信に失敗しました。'],
                    404,
                    [],
                    JSON_UNESCAPED_UNICODE
                );
              }
            } else {
              $createCount += 1;
              if ($is_deleted) {
                $ok = updateGroupDeviceDeleteCancellation($pdo, $deviceId, $newTeamId, 3);
                if (!$ok) {
                    return response()->json(
                        ['status' => 'error', 'error' => 'グループ情報の更新に失敗しました。'],
                        404,
                        [],
                        JSON_UNESCAPED_UNICODE
                    );
                }
              }
            }
          }
          $message = '';
          if ($count == $createCount) {
            $message = '※ ユーザー追加/犬の追加で指定した対象はすべて既にリクエスト済みです。';
          }
          return response()->json([
            'status' => 'success',
            'message' => $message,
          ], 200, [], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            \Log::error('groupJoin failed', ['e' => $e->getMessage()]);
            return response()->json(['status'=>'error','error'=>'サーバーエラーが発生しました'], 500);
        }
    }

    public function groupList(Request $request)
    {
        // ① セッションログイン確認（userId確認）
        $userId = Auth::id();
        if (!$userId) {
            return response()->json(
                ['status' => 'error', 'error' => '未ログインです', 'status' => 2],
                401,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }
        // ② Cookie の refresh_token 確認
        $refreshPlain = $request->cookie('refresh_token'); // Cookie名はあなたの実装に合わせる
        if (!$refreshPlain) {
            return response()->json(
                ['status' => 'error', 'error' => 'トークンがありません', 'status' => 2],
                401, [], JSON_UNESCAPED_UNICODE
            );
        }
        // ③ DB の hash と一致 + 期限チェック
        try {
            $refreshHash = hash('sha256', $refreshPlain);
            $row = DB::table('users')
                ->select('refresh_token_hash', 'refresh_token_expires_at', 'is_deleted')
                ->where('id', $userId)
                ->first();
            if (!$row || (int)$row->is_deleted === 1) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return response()->json(
                    ['status' => 'error', 'error' => 'ユーザーが見つかりません', 'status' => 2],
                    401, [], JSON_UNESCAPED_UNICODE
                );
            }
            if (empty($row->refresh_token_hash) || !hash_equals($row->refresh_token_hash, $refreshHash)) {
                // 不一致：盗用・古いCookie・別端末など
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return response()
                    ->json(['status' => 'error', 'error' => 'トークンが一致しません', 'status' => 2], 401, [], JSON_UNESCAPED_UNICODE)
                    ->withoutCookie('refresh_token');
            }
            // 期限（NULL対策込み）
            if (empty($row->refresh_token_expires_at) || now()->gte(\Carbon\Carbon::parse($row->refresh_token_expires_at))) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return response()
                    ->json(['status' => 'error', 'error' => 'トークンの有効期限が切れています', 'status' => 2], 401, [], JSON_UNESCAPED_UNICODE)
                    ->withoutCookie('refresh_token');
            }
        } catch (\Throwable $e) {
            \Log::error('token check failed', ['e' => $e->getMessage()]);
            return response()->json(['status'=>'error','error'=>'トークン検証でエラーが発生しました', 'status' => 2], 500, [], JSON_UNESCAPED_UNICODE);
        }
        require_once base_path('functions/teams.php');
        $pdo = DB::connection()->getPdo();
        $results   = getGroupList($pdo, $userId);
        if ($results) {
            $idArray        = array_column($results, 'id');
            $textIdArray    = array_column($results, 'team_id');
            $nameArray      = array_column($results, 'team_name');
            $stdataArray    = array_column($results, 'stdata');
            $flagArray      = array_column($results, 'flag');
            return response()->json([
                'status'    => 'success',
                'idlist'    => $idArray,
                'textidlist'=> $textIdArray,
                'namelist'  => $nameArray,
                'stdata'    => $stdataArray,
                'flag'      => $flagArray,
            ], 200, [], JSON_UNESCAPED_UNICODE);
        } else {
            return response()->json(
                ['status' => 'error', 'error' => 'データが存在しません。'],
                404,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }
    }
    
    public function groupData(Request $request)
    {
        // ① セッションログイン確認（userId確認）
        $userId = Auth::id();
        if (!$userId) {
            return response()->json(
                ['status' => 'error', 'error' => '未ログインです', 'status' => 2],
                401,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }
        // ② Cookie の refresh_token 確認
        $refreshPlain = $request->cookie('refresh_token'); // Cookie名はあなたの実装に合わせる
        if (!$refreshPlain) {
            return response()->json(
                ['status' => 'error', 'error' => 'トークンがありません', 'status' => 2],
                401, [], JSON_UNESCAPED_UNICODE
            );
        }
        // ③ DB の hash と一致 + 期限チェック
        try {
            $refreshHash = hash('sha256', $refreshPlain);
            $row = DB::table('users')
                ->select('refresh_token_hash', 'refresh_token_expires_at', 'is_deleted')
                ->where('id', $userId)
                ->first();
            if (!$row || (int)$row->is_deleted === 1) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return response()->json(
                    ['status' => 'error', 'error' => 'ユーザーが見つかりません', 'status' => 2],
                    401, [], JSON_UNESCAPED_UNICODE
                );
            }
            if (empty($row->refresh_token_hash) || !hash_equals($row->refresh_token_hash, $refreshHash)) {
                // 不一致：盗用・古いCookie・別端末など
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return response()
                    ->json(['status' => 'error', 'error' => 'トークンが一致しません', 'status' => 2], 401, [], JSON_UNESCAPED_UNICODE)
                    ->withoutCookie('refresh_token');
            }
            // 期限（NULL対策込み）
            if (empty($row->refresh_token_expires_at) || now()->gte(\Carbon\Carbon::parse($row->refresh_token_expires_at))) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return response()
                    ->json(['status' => 'error', 'error' => 'トークンの有効期限が切れています', 'status' => 2], 401, [], JSON_UNESCAPED_UNICODE)
                    ->withoutCookie('refresh_token');
            }
        } catch (\Throwable $e) {
            \Log::error('token check failed', ['e' => $e->getMessage()]);
            return response()->json(['status'=>'error','error'=>'トークン検証でエラーが発生しました', 'status' => 2], 500, [], JSON_UNESCAPED_UNICODE);
        }
        $validated = $request->validate([
            'groupid'   => ['required', 'integer'],
        ]);
        require_once base_path('functions/teams.php');
        $pdo = DB::connection()->getPdo();
        $groupId = (int)($validated['groupid'] ?? 0);
        $results = getGroupData($pdo, $userId, $groupId);
        if ($results) {
            return response()->json([
                'status' => 'success',
                'name'      => $results['team_name'],
                'flag'      => $results['flag'],
            ], 200, [], JSON_UNESCAPED_UNICODE);
        } else {
            return response()->json(
                ['status' => 'error', 'error' => 'データが存在しません。'],
                404,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }
    }
    
    public function getGroup(Request $request)
    {
        // ① セッションログイン確認（userId確認）
        $userId = Auth::id();
        if (!$userId) {
            return response()->json(
                ['status' => 'error', 'error' => '未ログインです', 'status' => 2],
                401,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }
        // ② Cookie の refresh_token 確認
        $refreshPlain = $request->cookie('refresh_token'); // Cookie名はあなたの実装に合わせる
        if (!$refreshPlain) {
            return response()->json(
                ['status' => 'error', 'error' => 'トークンがありません', 'status' => 2],
                401, [], JSON_UNESCAPED_UNICODE
            );
        }
        // ③ DB の hash と一致 + 期限チェック
        try {
            $refreshHash = hash('sha256', $refreshPlain);
            $row = DB::table('users')
                ->select('refresh_token_hash', 'refresh_token_expires_at', 'is_deleted')
                ->where('id', $userId)
                ->first();
            if (!$row || (int)$row->is_deleted === 1) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return response()->json(
                    ['status' => 'error', 'error' => 'ユーザーが見つかりません', 'status' => 2],
                    401, [], JSON_UNESCAPED_UNICODE
                );
            }
            if (empty($row->refresh_token_hash) || !hash_equals($row->refresh_token_hash, $refreshHash)) {
                // 不一致：盗用・古いCookie・別端末など
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return response()
                    ->json(['status' => 'error', 'error' => 'トークンが一致しません', 'status' => 2], 401, [], JSON_UNESCAPED_UNICODE)
                    ->withoutCookie('refresh_token');
            }
            // 期限（NULL対策込み）
            if (empty($row->refresh_token_expires_at) || now()->gte(\Carbon\Carbon::parse($row->refresh_token_expires_at))) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return response()
                    ->json(['status' => 'error', 'error' => 'トークンの有効期限が切れています', 'status' => 2], 401, [], JSON_UNESCAPED_UNICODE)
                    ->withoutCookie('refresh_token');
            }
        } catch (\Throwable $e) {
            \Log::error('token check failed', ['e' => $e->getMessage()]);
            return response()->json(['status'=>'error','error'=>'トークン検証でエラーが発生しました', 'status' => 2], 500, [], JSON_UNESCAPED_UNICODE);
        }
        $validated = $request->validate([
            'groupid'   => ['required', 'integer'],
        ]);
        require_once base_path('functions/teams.php');
        $pdo = DB::connection()->getPdo();
        $groupId = (int)($validated['groupid'] ?? 0);
        $results = getGroup($pdo, $userId, $groupId) ?? [];
        if ($results) {
            $textid      = $results['team_id']         ?? '';
            $name        = $results['team_name']       ?? '';
            $pass        = $results['password_hash']   ?? '';
            return response()->json([
                'status' => 'success',
                'textid' => $textid,
                'name'   => $name,
                'pass'   => $pass,
            ], 200, [], JSON_UNESCAPED_UNICODE);
        } else {
            return response()->json(
                ['status' => 'error', 'error' => 'データが存在しません。'],
                404,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }
    }
    
    public function groupUser(Request $request)
    {
        // ① セッションログイン確認（userId確認）
        $userId = Auth::id();
        if (!$userId) {
            return response()->json(
                ['status' => 'error', 'error' => '未ログインです', 'status' => 2],
                401,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }
        // ② Cookie の refresh_token 確認
        $refreshPlain = $request->cookie('refresh_token'); // Cookie名はあなたの実装に合わせる
        if (!$refreshPlain) {
            return response()->json(
                ['status' => 'error', 'error' => 'トークンがありません', 'status' => 2],
                401, [], JSON_UNESCAPED_UNICODE
            );
        }
        // ③ DB の hash と一致 + 期限チェック
        try {
            $refreshHash = hash('sha256', $refreshPlain);
            $row = DB::table('users')
                ->select('refresh_token_hash', 'refresh_token_expires_at', 'is_deleted')
                ->where('id', $userId)
                ->first();
            if (!$row || (int)$row->is_deleted === 1) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return response()->json(
                    ['status' => 'error', 'error' => 'ユーザーが見つかりません', 'status' => 2],
                    401, [], JSON_UNESCAPED_UNICODE
                );
            }
            if (empty($row->refresh_token_hash) || !hash_equals($row->refresh_token_hash, $refreshHash)) {
                // 不一致：盗用・古いCookie・別端末など
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return response()
                    ->json(['status' => 'error', 'error' => 'トークンが一致しません', 'status' => 2], 401, [], JSON_UNESCAPED_UNICODE)
                    ->withoutCookie('refresh_token');
            }
            // 期限（NULL対策込み）
            if (empty($row->refresh_token_expires_at) || now()->gte(\Carbon\Carbon::parse($row->refresh_token_expires_at))) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return response()
                    ->json(['status' => 'error', 'error' => 'トークンの有効期限が切れています', 'status' => 2], 401, [], JSON_UNESCAPED_UNICODE)
                    ->withoutCookie('refresh_token');
            }
        } catch (\Throwable $e) {
            \Log::error('token check failed', ['e' => $e->getMessage()]);
            return response()->json(['status'=>'error','error'=>'トークン検証でエラーが発生しました', 'status' => 2], 500, [], JSON_UNESCAPED_UNICODE);
        }
        $validated = $request->validate([
            'groupid'   => ['required', 'integer'],
        ]);
        require_once base_path('functions/teams.php');
        $pdo = DB::connection()->getPdo();
        $groupId = (int)($validated['groupid'] ?? 0);
        $results = getGroupUser($pdo, $groupId);
        if ($results) {
          $subjectArray       = array_column($results, 'subject_id');
          $objectNameArray    = array_column($results, 'object_name');
          $statusArray        = array_column($results, 'job');
          $participationArray = array_column($results, 'participation');
          $approvalArray      = array_column($results, 'approval');
          $stnameArray        = array_column($results, 'status_name');
          $ptnameArray        = array_column($results, 'participation_name');
          $alnameArray        = array_column($results, 'approval_name');
          $flagArray          = array_column($results, 'flag');
          return response()->json([
            'status'            => 'success',
            'idlist'            => $subjectArray,
            'namelist'          => $objectNameArray,
            'statuslist'        => $statusArray,
            'participationlist' => $participationArray,
            'approvaluserlist'  => $approvalArray,
            'stnamelist'        => $stnameArray,
            'ptnamelist'        => $ptnameArray,
            'alnamelist'        => $alnameArray,
            'flaglist'          => $flagArray,
          ], 200, [], JSON_UNESCAPED_UNICODE);
        } else {
            return response()->json(
                ['status' => 'error', 'error' => 'データが存在しません。'],
                404,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }
    }
    
    public function requestGroups(Request $request)
    {
        // ① セッションログイン確認（userId確認）
        $userId = Auth::id();
        if (!$userId) {
            return response()->json(
                ['status' => 'error', 'error' => '未ログインです', 'status' => 2],
                401,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }
        // ② Cookie の refresh_token 確認
        $refreshPlain = $request->cookie('refresh_token'); // Cookie名はあなたの実装に合わせる
        if (!$refreshPlain) {
            return response()->json(
                ['status' => 'error', 'error' => 'トークンがありません', 'status' => 2],
                401, [], JSON_UNESCAPED_UNICODE
            );
        }
        // ③ DB の hash と一致 + 期限チェック
        try {
            $refreshHash = hash('sha256', $refreshPlain);
            $row = DB::table('users')
                ->select('refresh_token_hash', 'refresh_token_expires_at', 'is_deleted')
                ->where('id', $userId)
                ->first();
            if (!$row || (int)$row->is_deleted === 1) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return response()->json(
                    ['status' => 'error', 'error' => 'ユーザーが見つかりません', 'status' => 2],
                    401, [], JSON_UNESCAPED_UNICODE
                );
            }
            if (empty($row->refresh_token_hash) || !hash_equals($row->refresh_token_hash, $refreshHash)) {
                // 不一致：盗用・古いCookie・別端末など
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return response()
                    ->json(['status' => 'error', 'error' => 'トークンが一致しません', 'status' => 2], 401, [], JSON_UNESCAPED_UNICODE)
                    ->withoutCookie('refresh_token');
            }
            // 期限（NULL対策込み）
            if (empty($row->refresh_token_expires_at) || now()->gte(\Carbon\Carbon::parse($row->refresh_token_expires_at))) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return response()
                    ->json(['status' => 'error', 'error' => 'トークンの有効期限が切れています', 'status' => 2], 401, [], JSON_UNESCAPED_UNICODE)
                    ->withoutCookie('refresh_token');
            }
        } catch (\Throwable $e) {
            \Log::error('token check failed', ['e' => $e->getMessage()]);
            return response()->json(['status'=>'error','error'=>'トークン検証でエラーが発生しました', 'status' => 2], 500, [], JSON_UNESCAPED_UNICODE);
        }
        require_once base_path('functions/teams.php');
        $pdo = DB::connection()->getPdo();
        $results = getGroupRequestList($pdo, $userId);
        if ($results) {
          $idArray       = array_column($results, 'team_id');
          $nameArray     = array_column($results, 'team_name');
          $hostArray     = array_column($results, 'username');
          $objectArray   = array_column($results, 'objectname');
          return response()->json([
            'status'            => 'success',
            'idlist'            => $idArray,
            'namelist'          => $nameArray,
            'hostuser'          => $hostArray,
            'objectname'        => $objectArray
          ], 200, [], JSON_UNESCAPED_UNICODE);
        } else {
            return response()->json(
                ['status' => 'error', 'error' => 'データが存在しません。'],
                404,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }
    }
    
    public function requestGroupUser(Request $request)
    {
        // ① セッションログイン確認（userId確認）
        $userId = Auth::id();
        if (!$userId) {
            return response()->json(
                ['status' => 'error', 'error' => '未ログインです', 'status' => 2],
                401,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }
        // ② Cookie の refresh_token 確認
        $refreshPlain = $request->cookie('refresh_token'); // Cookie名はあなたの実装に合わせる
        if (!$refreshPlain) {
            return response()->json(
                ['status' => 'error', 'error' => 'トークンがありません', 'status' => 2],
                401, [], JSON_UNESCAPED_UNICODE
            );
        }
        // ③ DB の hash と一致 + 期限チェック
        try {
            $refreshHash = hash('sha256', $refreshPlain);
            $row = DB::table('users')
                ->select('refresh_token_hash', 'refresh_token_expires_at', 'is_deleted')
                ->where('id', $userId)
                ->first();
            if (!$row || (int)$row->is_deleted === 1) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return response()->json(
                    ['status' => 'error', 'error' => 'ユーザーが見つかりません', 'status' => 2],
                    401, [], JSON_UNESCAPED_UNICODE
                );
            }
            if (empty($row->refresh_token_hash) || !hash_equals($row->refresh_token_hash, $refreshHash)) {
                // 不一致：盗用・古いCookie・別端末など
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return response()
                    ->json(['status' => 'error', 'error' => 'トークンが一致しません', 'status' => 2], 401, [], JSON_UNESCAPED_UNICODE)
                    ->withoutCookie('refresh_token');
            }
            // 期限（NULL対策込み）
            if (empty($row->refresh_token_expires_at) || now()->gte(\Carbon\Carbon::parse($row->refresh_token_expires_at))) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return response()
                    ->json(['status' => 'error', 'error' => 'トークンの有効期限が切れています', 'status' => 2], 401, [], JSON_UNESCAPED_UNICODE)
                    ->withoutCookie('refresh_token');
            }
        } catch (\Throwable $e) {
            \Log::error('token check failed', ['e' => $e->getMessage()]);
            return response()->json(['status'=>'error','error'=>'トークン検証でエラーが発生しました', 'status' => 2], 500, [], JSON_UNESCAPED_UNICODE);
        }
        $validated = $request->validate([
            'groupid'   => ['required', 'integer'],
        ]);
        require_once base_path('functions/teams.php');
        $pdo = DB::connection()->getPdo();
        $groupId = (int)($validated['groupid'] ?? 0);
        $results = getRequestGroupUser($pdo, $groupId);
        if ($results) {
          $subjectArray       = array_column($results, 'subject_id');
          $objectNameArray    = array_column($results, 'object_name');
          $statusArray        = array_column($results, 'job');
          $participationArray = array_column($results, 'participation');
          $approvalArray      = array_column($results, 'approval');
          return response()->json([
            'status'            => 'success',
            'idlist'            => $subjectArray,
            'namelist'          => $objectNameArray,
            'statuslist'        => $statusArray,
            'participationlist' => $participationArray,
            'approvaluserlist'  => $approvalArray
          ], 200, [], JSON_UNESCAPED_UNICODE);
        } else {
            return response()->json(
                ['status' => 'error', 'error' => 'データが存在しません。'],
                404,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }
    }
    
    public function memberList(Request $request)
    {
        // ① セッションログイン確認（userId確認）
        $userId = Auth::id();
        if (!$userId) {
            return response()->json(
                ['status' => 'error', 'error' => '未ログインです', 'status' => 2],
                401,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }
        // ② Cookie の refresh_token 確認
        $refreshPlain = $request->cookie('refresh_token'); // Cookie名はあなたの実装に合わせる
        if (!$refreshPlain) {
            return response()->json(
                ['status' => 'error', 'error' => 'トークンがありません', 'status' => 2],
                401, [], JSON_UNESCAPED_UNICODE
            );
        }
        // ③ DB の hash と一致 + 期限チェック
        try {
            $refreshHash = hash('sha256', $refreshPlain);
            $row = DB::table('users')
                ->select('refresh_token_hash', 'refresh_token_expires_at', 'is_deleted')
                ->where('id', $userId)
                ->first();
            if (!$row || (int)$row->is_deleted === 1) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return response()->json(
                    ['status' => 'error', 'error' => 'ユーザーが見つかりません', 'status' => 2],
                    401, [], JSON_UNESCAPED_UNICODE
                );
            }
            if (empty($row->refresh_token_hash) || !hash_equals($row->refresh_token_hash, $refreshHash)) {
                // 不一致：盗用・古いCookie・別端末など
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return response()
                    ->json(['status' => 'error', 'error' => 'トークンが一致しません', 'status' => 2], 401, [], JSON_UNESCAPED_UNICODE)
                    ->withoutCookie('refresh_token');
            }
            // 期限（NULL対策込み）
            if (empty($row->refresh_token_expires_at) || now()->gte(\Carbon\Carbon::parse($row->refresh_token_expires_at))) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return response()
                    ->json(['status' => 'error', 'error' => 'トークンの有効期限が切れています', 'status' => 2], 401, [], JSON_UNESCAPED_UNICODE)
                    ->withoutCookie('refresh_token');
            }
        } catch (\Throwable $e) {
            \Log::error('token check failed', ['e' => $e->getMessage()]);
            return response()->json(['status'=>'error','error'=>'トークン検証でエラーが発生しました', 'status' => 2], 500, [], JSON_UNESCAPED_UNICODE);
        }
        $validated = $request->validate([
            'groupid'   => ['required', 'integer'],
        ]);
        require_once base_path('functions/teams.php');
        $pdo = DB::connection()->getPdo();
        $groupId = (int)($validated['groupid'] ?? 0);
        $results = selectGroupMember($pdo, $groupId);
        if ($results) {
          $idArray            = array_column($results, 'id');
          $subjectidArray     = array_column($results, 'subject_id');
          $objectNameArray    = array_column($results, 'object_name');
          $statusArray        = array_column($results, 'job');
          return response()->json([
            'status'            => 'success',
            'idlist'            => $idArray,
            'subjectidlist'     => $subjectidArray,
            'namelist'          => $objectNameArray,
            'statuslist'        => $statusArray,
          ], 200, [], JSON_UNESCAPED_UNICODE);
        } else {
            return response()->json(
                ['status' => 'error', 'error' => 'データが存在しません。'],
                404,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }
    }
    
    public function protected(Request $request)
    {
        // ① セッションログイン確認（userId確認）
        $userId = Auth::id();
        if (!$userId) {
            return response()->json(
                ['status' => 'error', 'error' => '未ログインです', 'status' => 2],
                401,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }
        // ② Cookie の refresh_token 確認
        $refreshPlain = $request->cookie('refresh_token'); // Cookie名はあなたの実装に合わせる
        if (!$refreshPlain) {
            return response()->json(
                ['status' => 'error', 'error' => 'トークンがありません', 'status' => 2],
                401, [], JSON_UNESCAPED_UNICODE
            );
        }
        // ③ DB の hash と一致 + 期限チェック
        try {
            $refreshHash = hash('sha256', $refreshPlain);
            $row = DB::table('users')
                ->select('refresh_token_hash', 'refresh_token_expires_at', 'is_deleted')
                ->where('id', $userId)
                ->first();
            if (!$row || (int)$row->is_deleted === 1) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return response()->json(
                    ['status' => 'error', 'error' => 'ユーザーが見つかりません', 'status' => 2],
                    401, [], JSON_UNESCAPED_UNICODE
                );
            }
            if (empty($row->refresh_token_hash) || !hash_equals($row->refresh_token_hash, $refreshHash)) {
                // 不一致：盗用・古いCookie・別端末など
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return response()
                    ->json(['status' => 'error', 'error' => 'トークンが一致しません', 'status' => 2], 401, [], JSON_UNESCAPED_UNICODE)
                    ->withoutCookie('refresh_token');
            }
            // 期限（NULL対策込み）
            if (empty($row->refresh_token_expires_at) || now()->gte(\Carbon\Carbon::parse($row->refresh_token_expires_at))) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return response()
                    ->json(['status' => 'error', 'error' => 'トークンの有効期限が切れています', 'status' => 2], 401, [], JSON_UNESCAPED_UNICODE)
                    ->withoutCookie('refresh_token');
            }
        } catch (\Throwable $e) {
            \Log::error('token check failed', ['e' => $e->getMessage()]);
            return response()->json(['status'=>'error','error'=>'トークン検証でエラーが発生しました', 'status' => 2], 500, [], JSON_UNESCAPED_UNICODE);
        }
        return response()->json([
            'status' => 'success',
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function approval(Request $request)
    {
        // ① セッションログイン確認（userId確認）
        $userId = Auth::id();
        if (!$userId) {
            return response()->json(
                ['status' => 'error', 'error' => '未ログインです', 'status' => 2],
                401,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }
        // ② Cookie の refresh_token 確認
        $refreshPlain = $request->cookie('refresh_token'); // Cookie名はあなたの実装に合わせる
        if (!$refreshPlain) {
            return response()->json(
                ['status' => 'error', 'error' => 'トークンがありません', 'status' => 2],
                401, [], JSON_UNESCAPED_UNICODE
            );
        }
        // ③ DB の hash と一致 + 期限チェック
        try {
            $refreshHash = hash('sha256', $refreshPlain);
            $row = DB::table('users')
                ->select('refresh_token_hash', 'refresh_token_expires_at', 'is_deleted')
                ->where('id', $userId)
                ->first();
            if (!$row || (int)$row->is_deleted === 1) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return response()->json(
                    ['status' => 'error', 'error' => 'ユーザーが見つかりません', 'status' => 2],
                    401, [], JSON_UNESCAPED_UNICODE
                );
            }
            if (empty($row->refresh_token_hash) || !hash_equals($row->refresh_token_hash, $refreshHash)) {
                // 不一致：盗用・古いCookie・別端末など
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return response()
                    ->json(['status' => 'error', 'error' => 'トークンが一致しません', 'status' => 2], 401, [], JSON_UNESCAPED_UNICODE)
                    ->withoutCookie('refresh_token');
            }
            // 期限（NULL対策込み）
            if (empty($row->refresh_token_expires_at) || now()->gte(\Carbon\Carbon::parse($row->refresh_token_expires_at))) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return response()
                    ->json(['status' => 'error', 'error' => 'トークンの有効期限が切れています', 'status' => 2], 401, [], JSON_UNESCAPED_UNICODE)
                    ->withoutCookie('refresh_token');
            }
        } catch (\Throwable $e) {
            \Log::error('token check failed', ['e' => $e->getMessage()]);
            return response()->json(['status'=>'error','error'=>'トークン検証でエラーが発生しました', 'status' => 2], 500, [], JSON_UNESCAPED_UNICODE);
        }
        $validated = $request->validate([
            'groupid'   => ['required', 'integer'],
            'id'        => ['required', 'integer'],
            'stid'      => ['required', 'integer'],
        ]);
        require_once base_path('functions/teams.php');
        $pdo = DB::connection()->getPdo();
        $groupId = (int)($validated['groupid'] ?? 0);
        $memberId = (int)($validated['id'] ?? 0);
        $statusId = (int)($validated['stid'] ?? 0);
        try {
            $ok = updateGroupApproval($pdo, $memberId, $groupId, $statusId);
            if (!$ok) {
                return response()->json(
                    ['status' => 'error', 'error' => 'メンバーの承認に失敗しました。'],
                    404,
                    [],
                    JSON_UNESCAPED_UNICODE
                );
            }
            return response()->json([
                'status' => 'success',
            ], 200, [], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            \Log::error('approval failed', ['e' => $e->getMessage()]);
            return response()->json(['status'=>'error','error'=>'サーバーエラーが発生しました'], 500);
        }
    }

    public function requestCheck(Request $request)
    {
        // ① セッションログイン確認（userId確認）
        $userId = Auth::id();
        if (!$userId) {
            return response()->json(
                ['status' => 'error', 'error' => '未ログインです', 'status' => 2],
                401,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }
        // ② Cookie の refresh_token 確認
        $refreshPlain = $request->cookie('refresh_token'); // Cookie名はあなたの実装に合わせる
        if (!$refreshPlain) {
            return response()->json(
                ['status' => 'error', 'error' => 'トークンがありません', 'status' => 2],
                401, [], JSON_UNESCAPED_UNICODE
            );
        }
        // ③ DB の hash と一致 + 期限チェック
        try {
            $refreshHash = hash('sha256', $refreshPlain);
            $row = DB::table('users')
                ->select('refresh_token_hash', 'refresh_token_expires_at', 'is_deleted')
                ->where('id', $userId)
                ->first();
            if (!$row || (int)$row->is_deleted === 1) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return response()->json(
                    ['status' => 'error', 'error' => 'ユーザーが見つかりません', 'status' => 2],
                    401, [], JSON_UNESCAPED_UNICODE
                );
            }
            if (empty($row->refresh_token_hash) || !hash_equals($row->refresh_token_hash, $refreshHash)) {
                // 不一致：盗用・古いCookie・別端末など
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return response()
                    ->json(['status' => 'error', 'error' => 'トークンが一致しません', 'status' => 2], 401, [], JSON_UNESCAPED_UNICODE)
                    ->withoutCookie('refresh_token');
            }
            // 期限（NULL対策込み）
            if (empty($row->refresh_token_expires_at) || now()->gte(\Carbon\Carbon::parse($row->refresh_token_expires_at))) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return response()
                    ->json(['status' => 'error', 'error' => 'トークンの有効期限が切れています', 'status' => 2], 401, [], JSON_UNESCAPED_UNICODE)
                    ->withoutCookie('refresh_token');
            }
        } catch (\Throwable $e) {
            \Log::error('token check failed', ['e' => $e->getMessage()]);
            return response()->json(['status'=>'error','error'=>'トークン検証でエラーが発生しました', 'status' => 2], 500, [], JSON_UNESCAPED_UNICODE);
        }
        $validated = $request->validate([
            'groupid'   => ['required', 'integer'],
        ]);
        require_once base_path('functions/teams.php');
        $pdo = DB::connection()->getPdo();
        $groupId = (int)($validated['groupid'] ?? 0);
        $results = requestCheck($pdo, $userId, $groupId);
        if ($results) {
          return response()->json([
            'status'            => 'success',
          ], 200, [], JSON_UNESCAPED_UNICODE);
        } else {
            return response()->json(
                ['status' => 'error', 'error' => 'データが存在しません。'],
                404,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }
    }
}