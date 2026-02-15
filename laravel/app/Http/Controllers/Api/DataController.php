<?php

namespace App\Http\Controllers\Api;

use App\Services\DynamoDb\LocationInfoTable;
use App\Services\DynamoDb\LocationInfoUserTable;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cookie;

class DataController extends Controller
{
    public function userLocationInformation(Request $request, LocationInfoUserTable $table)
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
            'id'     => ['required', 'integer'],
            'lat'    => ['required', 'numeric'],
            'lng'    => ['required', 'numeric'],
            'acc'    => ['required', 'numeric'],
            'alt'    => ['required', 'numeric'],
            'altacc' => ['required', 'numeric'],
        ]);
        require_once base_path('functions/locationInfo.php');
        $pdo = DB::connection()->getPdo();
        $pointType = (int)($validated['id'] ?? 0);
        $userLat    = trim((string)($validated['lat'] ?? 0));
        $userLng    = trim((string)($validated['lng'] ?? 0));
        $userAcc    = trim((string)($validated['acc'] ?? 0));
        $userAlt    = trim((string)($validated['alt'] ?? 0));
        $userAltAcc = trim((string)($validated['altacc'] ?? 0));
        $userStl = '1.0';
        $userVol = '1.0';
        date_default_timezone_set('Asia/Tokyo');
        $timeId = trim((string)(date("YmdHis")));
        if ($pointType == 1) {
            $pointName = '待ち場('.$timeId.')';
        } elseif ($pointType == 2) {
            $pointName = '車('.$timeId.')';
        } else {
            $pointName = '';
        }
        try {
            if ($pointType == 1 || $pointType == 2) {
              $ok = insertDataPoint($pdo, $userId, $userLat, $userLng, $userAlt, $userAcc, $userAltAcc, $userStl, $userVol, $timeId, $pointType, $pointName);
              if (!$ok) {
                  return response()->json(
                      ['status' => 'error', 'error' => '対象が見つかりません（権限/ID）'],
                      404,
                      [],
                      JSON_UNESCAPED_UNICODE
                  );
              }
              return response()->json([
                  'status' => 'success',
              ], 200, [], JSON_UNESCAPED_UNICODE);
            } else {
              $table->putLocation([
                'user_id'      => (string)$userId,
                'time_id'      => $timeId,
                'lat'          => $userLat,
                'lng'          => $userLng,
                'alt'          => $userAlt,
                'acc'          => $userAcc,
                'alt_acc'      => $userAltAcc,
                'stl'          => $userStl,
                'vol'          => $userVol,
              ]);
              return response()->json([
                  'status' => 'success',
              ], 200, [], JSON_UNESCAPED_UNICODE);
              //   $ok = insertDataUser($pdo, $userId, $userLat, $userLng, $userAlt, $userAcc, $userAltAcc, $userStl, $userVol, $timeId);
            }
        } catch (\Throwable $e) {
            \Log::error('groupCreate failed', ['e' => $e->getMessage()]);
            return response()->json(['status'=>'error','error'=>'サーバーエラーが発生しました'], 500);
        }
    }

    public function getMapUser(Request $request)
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
        $results = getMapUser($pdo, $groupId) ?? [];
        if ($results) {
            $subjectArray       = array_column($results, 'subject_id');
            $objectNameArray    = array_column($results, 'object_name');
            return response()->json([
                'status'    => 'success',
                'idlist'    => $subjectArray,
                'namelist'  => $objectNameArray,
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

    public function getDevice(Request $request)
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
        $results = selectDataGroupDevice($pdo, $groupId);
        if ($results) {
            $numberArray = array_column($results, 'model_number');
            $nameArray = array_column($results, 'model_name');
            $idArray = array_column($results, 'id');
            return response()->json([
                'status'    => 'success',
                'numberlist' => $numberArray,
                'namelist'   => $nameArray,
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

    public function getBa(Request $request)
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
        require_once base_path('functions/locationInfo.php');
        $pdo = DB::connection()->getPdo();
        $results = selectDataBa($pdo, $userId);
        if ($results) {
            $idArray   = $results ? array_column($results, 'id') : [];
            $nameArray = $results ? array_column($results, 'point_name') : [];
            return response()->json([
                'status'   => 'success',
                'idlist'   => $idArray,
                'namelist' => $nameArray,
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

    public function getCar(Request $request)
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
        require_once base_path('functions/locationInfo.php');
        $pdo = DB::connection()->getPdo();
        $results = selectDataCar($pdo, $userId);
        if ($results) {
            $idArray   = $results ? array_column($results, 'id') : [];
            $nameArray = $results ? array_column($results, 'point_name') : [];
            return response()->json([
                'status'   => 'success',
                'idlist'   => $idArray,
                'namelist' => $nameArray,
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

    public function getdata(
        Request $request,
        LocationInfoTable $deviceTable,
        LocationInfoUserTable $userTable
    ) {
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
            'flag'    => ['required', 'integer', 'in:1,2,3'],
            'groupid' => ['required_if:flag,1', 'integer', 'min:1'],
            'date'    => ['required_if:flag,3', 'string', 'max:255'],
            'count'   => ['required_if:flag,3', 'integer', 'min:0'],
        ]);
        require_once base_path('functions/locationInfo.php');
        $pdo = DB::connection()->getPdo();
        $groupId = (int)($validated['groupid'] ?? 0);
        $selectedDate = trim((string)($validated['date'] ?? 0));
        $selectedCount = (int)($validated['count'] ?? 5);
        $mapflag = (int)($validated['flag'] ?? 0);
        try {
            if ($mapflag === 1) {
                // $results = selectData($pdo, $userId, $groupId);
                $results = selectDataDynamo($pdo, $deviceTable, $userTable, $userId, $groupId);
            } elseif ($mapflag === 2) {
                $results = selectDataPoint($pdo, $userId);
            } else {
                $results = selectDataTrajectory($pdo, $userId, $selectedDate, $selectedCount);
            }
            if (is_array($results) && count($results) > 0) {
                return response()->json([
                    'status'=>'success',
                    'dataset'=>$results,
                ], 200, [], JSON_UNESCAPED_UNICODE);
            }
            if (!is_array($results)) {
                $results = [];
            }
            return response()->json(
                ['status' => 'error', 'error' => 'データが存在しません。', 'errorid'=> 1, 'data'=> $mapflag],
                404,
                [],
                JSON_UNESCAPED_UNICODE
            );
        } catch (\Throwable $e) {
            \Log::error('getdata failed', ['e' => $e->getMessage()]);
            return response()->json(['status'=>'error','error'=>'サーバーエラーが発生しました', 'errorid'=> 0 ], 500);
        }
    }

    public function baRename(Request $request)
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
            'id' => ['required', 'integer'],
            'name'    => ['required', 'string', 'max:255'],
        ]);
        require_once base_path('functions/locationInfo.php');
        $pdo = DB::connection()->getPdo();
        $pointId = (int)($validated['id'] ?? 0);
        $pointName = trim((string)($validated['name'] ?? ''));
        if ($pointId <= 0) {
            return response()->json(
                ['status' => 'error', 'error' => 'ポイントIDが不正です'],
                404,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }
        if ($pointName === '' || mb_strlen($pointName) > 50) {
            return response()->json(
                ['status' => 'error', 'error' => '名称は1〜50文字で入力してください'],
                404,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }
        try {
            $ok = pointRename($pdo, $userId, $pointId, $pointName); // ← boolean が返る想定
            if (!$ok) {
                return response()->json(
                    ['status' => 'error', 'error' => '対象が見つかりません（権限/ID）'],
                    404,
                    [],
                    JSON_UNESCAPED_UNICODE
                );
            }
            return response()->json([
                'status'=>'success',
            ], 200, [], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            \Log::error('baRename failed', ['e' => $e->getMessage()]);
            return response()->json(['status'=>'error','error'=>'サーバーエラーが発生しました', 'errorid'=> 0 ], 500);
        }
    }

    public function carRename(Request $request)
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
            'id' => ['required', 'integer'],
            'name'    => ['required', 'string', 'max:255'],
        ]);
        require_once base_path('functions/locationInfo.php');
        $pdo = DB::connection()->getPdo();
        $pointId = (int)($validated['id'] ?? 0);
        $pointName = trim((string)($validated['name'] ?? ''));
        if ($pointId <= 0) {
            return response()->json(
                ['status' => 'error', 'error' => 'ポイントIDが不正です'],
                404,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }
        if ($pointName === '' || mb_strlen($pointName) > 50) {
            return response()->json(
                ['status' => 'error', 'error' => '名称は1〜50文字で入力してください'],
                404,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }
        try {
            $ok = pointRename($pdo, $userId, $pointId, $pointName); // ← boolean が返る想定
            if (!$ok) {
                return response()->json(
                    ['status' => 'error', 'error' => '対象が見つかりません（権限/ID）'],
                    404,
                    [],
                    JSON_UNESCAPED_UNICODE
                );
            }
            return response()->json([
                'status'=>'success',
            ], 200, [], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            \Log::error('carRename failed', ['e' => $e->getMessage()]);
            return response()->json(['status'=>'error','error'=>'サーバーエラーが発生しました', 'errorid'=> 0 ], 500);
        }
    }

    public function baDelete(Request $request)
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
            'id' => ['required', 'integer'],
        ]);
        require_once base_path('functions/locationInfo.php');
        $pdo = DB::connection()->getPdo();
        $pointId = (int)($validated['id'] ?? 0);
        if ($pointId <= 0) {
            return response()->json(
                ['status' => 'error', 'error' => 'ポイントIDが不正です'],
                404,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }
        try {
            $ok = pointDelete($pdo, $userId, $pointId); // ← boolean が返る想定
            if (!$ok) {
                return response()->json(
                    ['status' => 'error', 'error' => '対象が見つかりません（権限/ID）'],
                    404,
                    [],
                    JSON_UNESCAPED_UNICODE
                );
            }
            return response()->json([
                'status'=>'success',
            ], 200, [], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            \Log::error('baDelete failed', ['e' => $e->getMessage()]);
            return response()->json(['status'=>'error','error'=>'サーバーエラーが発生しました', 'errorid'=> 0 ], 500);
        }
    }

    public function carDelete(Request $request)
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
            'id' => ['required', 'integer'],
        ]);
        require_once base_path('functions/locationInfo.php');
        $pdo = DB::connection()->getPdo();
        $pointId = (int)($validated['id'] ?? 0);
        if ($pointId <= 0) {
            return response()->json(
                ['status' => 'error', 'error' => 'ポイントIDが不正です'],
                404,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }
        try {
            $ok = pointDelete($pdo, $userId, $pointId); // ← boolean が返る想定
            if (!$ok) {
                return response()->json(
                    ['status' => 'error', 'error' => '対象が見つかりません（権限/ID）'],
                    404,
                    [],
                    JSON_UNESCAPED_UNICODE
                );
            }
            return response()->json([
                'status'=>'success',
            ], 200, [], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            \Log::error('carDelete failed', ['e' => $e->getMessage()]);
            return response()->json(['status'=>'error','error'=>'サーバーエラーが発生しました', 'errorid'=> 0 ], 500);
        }
    }
}