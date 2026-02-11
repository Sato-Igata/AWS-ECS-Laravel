<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cookie;

class UserController extends Controller
{
    public function me(Request $request)
    {
        // auth ミドルウェアが通っているので、ログイン済み
        $user = $request->user();

        return response()->json([
            'status' => 'success',
            'user' => [
                'id'    => $user->id,
                'name'  => $user->username,
                'tele'  => $user->tele,
                'email' => $user->email,
            ],
        ]);
    }

    public function setUserData(Request $request)
    {
        $validated = $request->validate([
            'name'  => ['required', 'string', 'max:255'],
            'tele'  => ['nullable','string','max:255','required_without:email'],
            'email' => ['nullable','email','max:255','required_without:tele'],
            'pass'  => ['required', 'string', 'max:255'],
        ]);
        require_once base_path('functions/user.php');
        require_once base_path('functions/products.php');
        $pdo = DB::connection()->getPdo();
        $username   = trim((string)($validated['name'] ?? ''));
        $usertele   = trim((string)($validated['tele'] ?? ''));
        $useremail  = trim((string)($validated['email'] ?? ''));
        $userpass   = trim((string)($validated['pass'] ?? ''));
        $passhash = password_hash($userpass, PASSWORD_DEFAULT);
        $messeage = '';
        try {
            $telecheck  = findUserByTele($pdo, $usertele);
            $emailcheck = findUserByEmail($pdo, $useremail);
            if (($usertele != '' && $telecheck) || ($useremail != '' && $emailcheck)) {
                if (($usertele != '' && $telecheck['is_deleted'] === 0) || ($useremail != '' && $emailcheck['is_deleted'] === 0)) {
                    return response()->json(
                        ['status' => 'error', 'error' => '電話番号またはメールアドレスがすでに登録されています。'],
                        404,
                        [],
                        JSON_UNESCAPED_UNICODE
                    );
                } else {
                    if ($usertele != '' && $telecheck) {
                        $ok = updateDeleteUserData($pdo, $telecheck['id'], $username, $usertele, $useremail, $passhash);
                        if (!$ok) {
                            return response()->json(
                                ['status' => 'error', 'error' => 'ユーザー登録処理に失敗しました。'],
                                404,
                                [],
                                JSON_UNESCAPED_UNICODE
                            );
                        }
                        $uid = $telecheck['id'];
                    } else {
                        $ok = updateDeleteUserData($pdo, $emailcheck['id'], $username, $usertele, $useremail, $passhash);
                        if (!$ok) {
                            return response()->json(
                                ['status' => 'error', 'error' => 'ユーザー登録処理に失敗しました。'],
                                404,
                                [],
                                JSON_UNESCAPED_UNICODE
                            );
                        }
                        $uid = $emailcheck['id'];
                    }
                    $ok = updateDeleteUserSetting($pdo, $uid, 'free', 'unknown', $usertele, $useremail);
                    if (!$ok) {
                        return response()->json(
                            ['status' => 'error', 'error' => 'ユーザー登録処理に失敗しました。'],
                            404,
                            [],
                            JSON_UNESCAPED_UNICODE
                        );
                    }
                }
            } else {
                $ok = insertUserData($pdo, $username, $usertele, $useremail, $passhash);
                if (!$ok) {
                    return response()->json(
                        ['status' => 'error', 'error' => 'ユーザー登録処理に失敗しました。'],
                        404,
                        [],
                        JSON_UNESCAPED_UNICODE
                    );
                }
                if ($usertele != '') {
                    $usersTele  = findUserByTele($pdo, $usertele);
                    $uid = $usersTele['id'];
                } else {
                    $usersEmail  = findUserByEmail($pdo, $useremail);
                    $uid = $usersEmail['id'];
                }
                $ok = insertUserSetting($pdo, $uid, 'free', 'unknown', $usertele, $useremail);
                if (!$ok) {
                    return response()->json(
                        ['status' => 'error', 'error' => 'ユーザー登録処理に失敗しました。'],
                        404,
                        [],
                        JSON_UNESCAPED_UNICODE
                    );
                }
            }
            if ($usertele != '') {
                $usersTele  = findUserByTele($pdo, $usertele);
                $uid = $usersTele['id'];
                $ok = insertUrlData($pdo, $uid, 'done?pagenum=', 1, '新規ユーザー登録確認', 1);
                if (!$ok) {
                    return response()->json(
                        ['status' => 'error', 'error' => '確認メール送信に失敗しました。'],
                        404,
                        [],
                        JSON_UNESCAPED_UNICODE
                    );
                }
            } else {
                $usersEmail  = findUserByEmail($pdo, $useremail);
                $uid = $usersEmail['id'];
                $ok = insertUrlData($pdo, $uid, 'done?pagenum=', 1, '新規ユーザー登録確認', 2);
                if (!$ok) {
                    return response()->json(
                        ['status' => 'error', 'error' => '確認メール送信に失敗しました。'],
                        404,
                        [],
                        JSON_UNESCAPED_UNICODE
                    );
                }
            }
            return response()->json([
                'status'       => 'success',
            ], 200, [], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            \Log::error('setData failed', ['e' => $e->getMessage()]);
            return response()->json(['status'=>'error','error'=>'サーバーエラーが発生しました'], 500);
        }
    }

    public function userDevice(Request $request)
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

        // 2) 旧 functions/products.php を読み込む（あなたの配置に一致）
        require_once base_path('functions/products.php');

        // 3) 旧関数を実行（PDOで渡す）
        $pdo = DB::connection()->getPdo();

        try {
            $results = selectDataDevice($pdo, (int)$userId);
        } catch (\Throwable $e) {
            \Log::error('getUserDevice failed', [
                'e' => $e->getMessage(),
                'userId' => $userId,
            ]);
            return response()->json(
                ['status' => 'error', 'error' => 'サーバーエラーが発生しました'],
                500,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }

        if (!$results || count($results) === 0) {
            return response()->json(
                ['status' => 'error', 'error' => 'データが存在しません。'],
                404,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }

        return response()->json([
            'status' => 'success',
            'id'     => array_column($results, 'id'),
            'number' => array_column($results, 'model_number'),
            'name'   => array_column($results, 'model_name'),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function sinUp(Request $request)
    {
        return response()->json([
            'status' => 'success',
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function code(Request $request)
    {
        $validated = $request->validate([
            'email'    => ['required', 'string', 'max:255'],
            'pass'     => ['required', 'string', 'max:255'],
        ]);
        require_once base_path('functions/user.php');
        $pdo = DB::connection()->getPdo();
        $email = trim((string)($validated['email'] ?? ''));
        $pass  = trim((string)($validated['pass'] ?? ''));
        $code  = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        if (!$email || !$pass) {
            return response()->json(
                ['status' => 'error', 'error' => 'メールアドレスとパスワードを入力してください'],
                404,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }
        $user_tele  = findUserByTele($pdo, $email);
        $user_email = findUserByEmail($pdo, $email);
        if (($user_tele && $user_tele['email_verified'] === 0) || ($user_email && $user_email['email_verified'] === 0)) {
            return response()->json(
                ['status' => 'error', 'error' => '電話番号／メールアドレスの確認が取れていません。迷惑メール等を確認し、ユーザー登録を完了してください。'],
                404,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }
        if ($user_tele && password_verify($pass, $user_tele['password_hash'])) {
            $ok = newCodeTele($pdo, $email, $code);
            if ($ok) {
                return response()->json([
                    'status' => 'success',
                    'data'   => $user_tele,
                    'flag'   => 1
                ], 200, [], JSON_UNESCAPED_UNICODE);
            } else {
              return response()->json(
                ['status' => 'error', 'error' => '確認コードの発行に失敗しました'],
                500,
                [],
                JSON_UNESCAPED_UNICODE
              );
            }
        }
        if ($user_email && password_verify($pass, $user_email['password_hash'])) {
            $ok = newCodeEmail($pdo, $email, $code);
            if ($ok) {
                return response()->json([
                    'status' => 'success',
                    'data'   => $user_email,
                    'flag'   => 2
                ], 200, [], JSON_UNESCAPED_UNICODE);
            } else {
              return response()->json(
                ['status' => 'error', 'error' => '確認コードの発行に失敗しました'],
                500,
                [],
                JSON_UNESCAPED_UNICODE
              );
            }
        }
        return response()->json(
            ['status' => 'error', 'error' => 'メールアドレスかパスワードが間違っています'],
            404,
            [],
            JSON_UNESCAPED_UNICODE
        );
    }
    
    public function passwordForget(Request $request)
    {
        $validated = $request->validate([
            'email'    => ['required', 'string', 'max:255'],
        ]);
        require_once base_path('functions/user.php');
        $pdo = DB::connection()->getPdo();
        $identifier = trim((string)($validated['email'] ?? ''));
        $frontendUrl = config('app.frontend_url');
        $url = $frontendUrl.'/passwordForgetChg?pagenum=';
        $userTele  = findUserByTele($pdo, $identifier) ?: null;
        $userEmail = findUserByEmail($pdo, $identifier) ?: null;
        if ($userTele) {
            $flag = 1;
        } else {
            $flag = 2;
        }
        $user      = $userTele ?: $userEmail;
        if ($user) {
            $channelFlag = $userTele ? 1 : 2;
            insertUrlData($pdo, (int)$user['id'], $url, 3, 'パスワード再設定', $channelFlag);
            return response()->json([
                'status' => 'success',
                'flag'   => $flag
            ], 200, [], JSON_UNESCAPED_UNICODE);
        } else {
            return response()->json(
                ['status' => 'error', 'error' => '送信処理に失敗しました。'],
                404,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }
    }
    
    public function passwordChg(Request $request)
    {
        $validated = $request->validate([
            'email'    => ['required', 'string', 'max:255'],
            'text'     => ['required', 'string', 'max:255'],
        ]);
        require_once base_path('functions/user.php');
        $pdo = DB::connection()->getPdo();
        $identifier = trim((string)($validated['email'] ?? ''));
        $pstext     = trim((string)($validated['text'] ?? ''));
        $userTele  = findUserByTele($pdo, $identifier) ?: null;
        $userEmail = findUserByEmail($pdo, $identifier) ?: null;
        $user      = $userTele ?: $userEmail;
        if ($user) {
            $passhash = password_hash($pstext, PASSWORD_DEFAULT);
            if ($userTele) {
              $tele = $user['tele'];
              updateUserPasswordTele($pdo, $tele, $passhash);
              $flag = 1;
            } else {
              $email = $user['email'];
              updateUserPasswordEmail($pdo, $email, $passhash);
              $flag = 2;
            }
            return response()->json([
                'status' => 'success',
                'flag'   => $flag
            ], 200, [], JSON_UNESCAPED_UNICODE);
        } else {
            return response()->json(
                ['status' => 'error', 'error' => '更新処理に失敗しました。'],
                404,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }
    }
    
    public function userCheck(Request $request)
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
            'uid'    => ['required', 'integer'],
        ]);
        $uId   = (int)($validated['uid'] ?? 0);
        $check = 0;
        if ($uId === $userId) {
            $check = 1;
        } 
        return response()->json([
            'status' => 'success',
            'check'  => $check,
            'num'    => $userId,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }
    
    public function passwordCheck(Request $request)
    {
        // $userId = Auth::id();
        // if (!$userId) {
        //     return response()->json(
        //         ['status' => 'error', 'error' => '未ログインです'],
        //         401,
        //         [],
        //         JSON_UNESCAPED_UNICODE
        //     );
        // }
        $validated = $request->validate([
            'email'    => ['required', 'string', 'max:255'],
            'text'     => ['required', 'string', 'max:255'],
        ]);
        require_once base_path('functions/user.php');
        $pdo = DB::connection()->getPdo();
        $identifier = trim((string)($validated['email'] ?? ''));
        $pstext     = trim((string)($validated['text'] ?? ''));
        $userTele   = findUserByTele($pdo, $identifier) ?: null;
        $userEmail  = findUserByEmail($pdo, $identifier) ?: null;
        $user       = $userTele ?: $userEmail;
        if ($user && password_verify($pstext, $user['password_hash'])) {
            return response()->json([
                'status' => 'success',
            ], 200, [], JSON_UNESCAPED_UNICODE);
        } else {
            return response()->json(
                ['status' => 'error', 'error' => '更新処理に失敗しました。'],
                404,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }
    }

    public function setting(Request $request)
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
        require_once base_path('functions/user.php');
        require_once base_path('functions/products.php');
        $pdo = DB::connection()->getPdo();
        $user = getUserData($pdo, $userId);
        $setting = getUserSetting($pdo, $userId);
        $devicedata = selectDataDevice($pdo, $userId);
        $deviceList = $devicedata ? array_column($devicedata, 'model_number') : [];
        $devicename = $devicedata ? array_column($devicedata, 'model_name')   : [];
        if ($user && $setting) {
            $planId    = isset($setting['plan_id'])    ? (int)$setting['plan_id']    : 0;
            $paymentId = isset($setting['payment_id']) ? (int)$setting['payment_id'] : 0;
            $plandata    = $planId    ? (getUserPlan($pdo, $planId) ?: null)       : null;
            $paymentdata = $paymentId ? (getUserPayment($pdo, $paymentId) ?: null) : null;
            $planName    = $plandata['plan_name']       ?? '';
            $paymentName = $paymentdata['payment_name'] ?? '';
            return response()->json([
                'status' => 'success',
                'plan'       => $planName,
                'payment'    => $paymentName,
                'username'   => $user['username'] ?? '',
                'tele'       => $user['tele'] ?? '',
                'email'      => $user['email'] ?? '',
                'mapbtn'     => (int)($setting['map_btn']),
                'gpsflag'    => (int)($setting['gps_status']),
                'eneflag' => (int)($setting['energy_saving']),
                'devicelist' => $deviceList,
                'devicename' => $devicename
            ], 200, [], JSON_UNESCAPED_UNICODE);
        } else {
            return response()->json(
                ['status' => 'error', 'error' => '取得処理に失敗しました。'],
                404,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }
    }
    
    public function userSetting(Request $request)
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
    
    public function settingUpdate(Request $request)
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
            'tele'      => ['required', 'string', 'max:255'],
            'email'     => ['required', 'string', 'max:255'],
            'name'      => ['required', 'string', 'max:255'],
            'mapbtn'    => ['required', 'integer'],
            'gps'       => ['required', 'integer'],
            'ene'       => ['required', 'integer'],
            'devicelist'=> ['array'],
            'devicelist.*' => ['string', 'max:255'],
            'devicename'=> ['array'],
            'devicename.*' => ['string', 'max:255'],
        ]);
        require_once base_path('functions/user.php');
        require_once base_path('functions/products.php');
        $pdo = DB::connection()->getPdo();
        $usertele   = trim((string)($validated['tele'] ?? ''));
        $useremail  = trim((string)($validated['email'] ?? ''));
        $username   = trim((string)($validated['name'] ?? ''));
        $mapBtn     = (int)($validated['mapbtn'] ?? 0);
        $gps        = (int)($validated['gps'] ?? 1);
        $ene        = (int)($validated['ene'] ?? 0);
        $deviceList = $validated['devicelist'] ?? [];
        $deviceName = $validated['devicename'] ?? [];
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $messeage = '';
        $codeflag = 0;
        $user = getUserData($pdo, $userId);
        $setting = getUserSetting($pdo, $userId);
        if ($user && $setting) {
            if (trim((string)($user['tele'] ?? '')) !== $usertele && $codeflag !== 1) {
              $checkTele = findUserByTele($pdo, $usertele);
              if ($checkTele) {
                    return response()->json(
                        ['status' => 'error', 'error' => '電話番号は他のユーザーにより既に登録済みです。'],
                        404,
                        [],
                        JSON_UNESCAPED_UNICODE
                    );
              } else {
                $ok = updateUserSettingTeleEmail($pdo, $userId, $usertele, $useremail);
                if (!$ok) {
                    return response()->json(
                        ['status' => 'error', 'error' => 'ユーザー情報の更新に失敗しました。'],
                        404,
                        [],
                        JSON_UNESCAPED_UNICODE
                    );
                }
                newCodeSettingTele($pdo, $usertele, $code);
                $codeflag = 1;
                $messeage = '電話番号宛に確認コードを送信しました。コードの確認の際、「画面を閉じる、更新(リロード)、戻る」を実行しないでください。';
              }
            }
            if (trim((string)($user['email'] ?? '')) !== $useremail && $codeflag !== 1) {
              $checkEmail = findUserByEmail($pdo, $useremail);
              if ($checkEmail) {
                    return response()->json(
                        ['status' => 'error', 'error' => 'メールアドレスは他のユーザーにより既に登録済みです。'],
                        404,
                        [],
                        JSON_UNESCAPED_UNICODE
                    );
              } else {
                $ok = updateUserSettingTeleEmail($pdo, $userId, $usertele, $useremail);
                if (!$ok) {
                    return response()->json(
                        ['status' => 'error', 'error' => 'ユーザー情報の更新に失敗しました。'],
                        404,
                        [],
                        JSON_UNESCAPED_UNICODE
                    );
                }
                newCodeSettingEmail($pdo, $useremail, $code);
                $codeflag = 1;
                $messeage = 'メールアドレス宛に確認コードを送信しました。コードの確認の際、「画面を閉じる、更新(リロード)、戻る」を実行しないでください。';
              }
            }
            
            // ① ユーザー基本情報更新
            $ok = updateUserName($pdo, $userId, $username);
            if (!$ok) {
                    return response()->json(
                        ['status' => 'error', 'error' => 'ユーザー情報の更新に失敗しました。'],
                        404,
                        [],
                        JSON_UNESCAPED_UNICODE
                    );
            }
        
            // ② ユーザー設定更新
            $ok = updateUserSetting($pdo, $userId, $mapBtn, $gps, $ene);
            if (!$ok) {
                    return response()->json(
                        ['status' => 'error', 'error' => '設定情報の更新に失敗しました。'],
                        404,
                        [],
                        JSON_UNESCAPED_UNICODE
                    );
            }
            
            // ③ 新しいデバイスリストを反映（追加・復活・名前更新）
            foreach ($deviceList as $index => $deviceId) {
              // 対応する名前がある場合は取得（なければ空文字）
              $deviceId = trim((string)$deviceId);
              if ($deviceId == '') continue; // 空はスキップ
              $name = isset($deviceName[$index]) ? trim((string)$deviceName[$index]) : '';
              $deviceDelete = getDeviceDeleteCheck($pdo, $deviceId);
              if ($deviceDelete && (int)$deviceDelete['user_id'] === $userId) {
                $ok = updateDeleteDevice($pdo, $deviceId, $userId);
                if (!$ok) {
                    return response()->json(
                        ['status' => 'error', 'error' => 'デバイス情報が削除されていたため、復元を試みましたが失敗しました。'],
                        404,
                        [],
                        JSON_UNESCAPED_UNICODE
                    );
                }
              }
              $device = getDevice($pdo, $userId, $deviceId);
              if ($device) {
                $ok = updateUserDevice($pdo, $deviceId, $name, $userId);
                if (!$ok) {
                    return response()->json(
                        ['status' => 'error', 'error' => 'デバイス情報の更新に失敗しました。'],
                        404,
                        [],
                        JSON_UNESCAPED_UNICODE
                    );
                }
              } else {
                $deviceUser = getDeviceUserCheck($pdo, $deviceId);
                if ($deviceUser && (int)$deviceUser['user_id'] !== $userId) {
                    return response()->json(
                        ['status' => 'error', 'error' => 'デバイスは既に別のユーザーで登録されています。'],
                        404,
                        [],
                        JSON_UNESCAPED_UNICODE
                    );
                }
                $ok = insertUserDevice($pdo, $deviceId, $name, $userId);
                if (!$ok) {
                    return response()->json(
                        ['status' => 'error', 'error' => 'デバイスの追加に失敗しました。'],
                        404,
                        [],
                        JSON_UNESCAPED_UNICODE
                    );
                }
              }
            }
            
            // ④ 今 DB 上にある「このユーザーのアクティブなデバイス」を取得
            $currentDevices    = selectDataDevice($pdo, $userId); // is_deleted=0 のみ
            $currentDeviceList = $currentDevices ? array_column($currentDevices, 'model_number') : [];
        
            // 新しい deviceList と比較し、DBにしか残っていないものを is_deleted=1 にする
            foreach ($currentDeviceList as $oldDeviceId) {
              if (!in_array($oldDeviceId, $deviceList, true)) {
                $ok = deleteDevice($pdo, $oldDeviceId, $userId);
                if (!$ok) {
                    return response()->json(
                        ['status' => 'error', 'error' => 'デバイスの削除に失敗しました。'],
                        404,
                        [],
                        JSON_UNESCAPED_UNICODE
                    );
                }
              }
            }
            return response()->json([
                'status' => 'success',
                'messeage' => $messeage,
                'code'     => $codeflag,
            ], 200, [], JSON_UNESCAPED_UNICODE);
        } else {
            return response()->json(
                ['status' => 'error', 'error' => '保存処理に失敗しました。'],
                404,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }
    }
    
    public function settingUpdateUser(Request $request)
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
                ['status' => 'error', 'error' => 'トークンがありません'],
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
            'tele'      => ['required', 'string', 'max:255'],
            'email'     => ['required', 'string', 'max:255'],
            'text'      => ['required', 'string', 'max:255'],
        ]);
        require_once base_path('functions/user.php');
        $pdo = DB::connection()->getPdo();
        $usertele   = trim((string)($validated['tele'] ?? ''));
        $useremail  = trim((string)($validated['email'] ?? ''));
        $usercode   = trim((string)($validated['text'] ?? ''));
        $codeflag = 0;
        $creatcode = 0;
        $messeage = '';
        try {
            $user = getUserData($pdo, $userId);
            if (!$user || count($user) === 0) {
                return response()->json(
                    ['status' => 'error', 'error' => 'データが存在しません。'],
                    404,
                    [],
                    JSON_UNESCAPED_UNICODE
                );
            }
            if (trim((string)($user['tele'] ?? '')) !== $usertele && $codeflag !== 1) {
              $codeTele = findUserByCodeSettingTele($pdo, $userId, $usercode);
              if ($codeTele) {
                $ok = updateSettingHistory($pdo, $userId);
                if (!$ok) {
                    return response()->json(
                       ['status' => 'error', 'error' => 'ユーザー設定更新履歴の追加に失敗しました。'],
                       404,
                       [],
                       JSON_UNESCAPED_UNICODE
                    );
                }
                $ok = updateUserData($pdo, $userId, $usertele, $useremail);
                if (!$ok) {
                    return response()->json(
                       ['status' => 'error', 'error' => 'ユーザー情報の更新に失敗しました。'],
                       404,
                       [],
                       JSON_UNESCAPED_UNICODE
                    );
                }
              }
              $codeflag = 1;
              $creatcode = 1;
              $messeage = '電話番号の確認が取れました。';
            }
            if (trim((string)($user['email'] ?? '')) !== $useremail && $codeflag !== 1) {
              $codeEmail = findUserByCodeSettingEmail($pdo, $userId, $usercode);
              if ($codeEmail) {
                $ok = updateSettingHistory($pdo, $userId);
                if (!$ok) {
                    return response()->json(
                       ['status' => 'error', 'error' => 'ユーザー設定更新履歴の追加に失敗しました。'],
                       404,
                       [],
                       JSON_UNESCAPED_UNICODE
                    );
                }
                $ok = updateUserData($pdo, $userId, $usertele, $useremail);
                if (!$ok) {
                    return response()->json(
                       ['status' => 'error', 'error' => 'ユーザー情報の更新に失敗しました。'],
                       404,
                       [],
                       JSON_UNESCAPED_UNICODE
                    );
                }
              }
              $codeflag = 1;
              $creatcode = 2;
              $messeage = 'メールアドレスの確認が取れました。';
            }
            return response()->json([
                'status'       => 'success',
                'messeage'     => $messeage,
                'tele'         => $usertele,
                'email'        => $useremail,
                'flag'         => $creatcode
            ], 200, [], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            \Log::error('settingUpdateUser failed', ['e' => $e->getMessage()]);
            return response()->json(['status'=>'error','error'=>'サーバーエラーが発生しました'], 500);
        }
    }
    
    public function done(Request $request)
    {
        $validated = $request->validate([
            'id'   => ['required', 'integer'],
        ]);
        require_once base_path('functions/user.php');
        $pdo = DB::connection()->getPdo();
        $id      = (int)$validated['id'];
        try {
            $results = getUserURL($pdo, $id);
            if (!$results || count($results) === 0) {
                return response()->json(
                    ['status' => 'error', 'error' => 'データが存在しません。'],
                    404,
                    [],
                    JSON_UNESCAPED_UNICODE
                );
            }
            if ($results['subject_status'] === 1) {
                $ok = updateNewUser($pdo, $results['id']);
                if (!$ok) {
                    return response()->json(
                        ['status' => 'error', 'error' => 'ユーザー登録に失敗しました。'],
                        404,
                        [],
                        JSON_UNESCAPED_UNICODE
                    );
                }
            }
            return response()->json([
                'status'       => 'success',
                'tele'         => $results['tele'],
                'email'        => $results['email'],
                'username'     => $results['username'],
                'subjectstatus'=> $results['subject_status'],
                'userstatus'   => $results['user_status'],
            ], 200, [], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            \Log::error('done failed', ['e' => $e->getMessage()]);
            return response()->json(['status'=>'error','error'=>'サーバーエラーが発生しました'], 500);
        }
    }

    public function planData(Request $request)
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
        require_once base_path('functions/user.php');
        $pdo = DB::connection()->getPdo();
        $flag = 0;
        $messeage = '';
        $results = getUserSetting($pdo, $userId);
        if ($results) {
          $plan       = $results['plan_id'];
          $payment    = $results['payment_id'];
          $type = paymentType($pdo, $payment);
          if ($type['stripe_payment_type'] != 'unknown') {
            $flag = 1;
          }
          return response()->json([
            'status'            => 'success',
            'flag'              => $flag,
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

    public function getPlan(Request $request)
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
        require_once base_path('functions/user.php');
        $pdo = DB::connection()->getPdo();
        $results = planList($pdo);
        if ($results) {
          $idArray       = array_column($results, 'id');
          $nameArray     = array_column($results, 'plan_name');
          $monthlyArray  = array_column($results, 'monthly_fee');
          $typeArray     = array_column($results, 'stripe_plan_type');
          $textArray     = array_column($results, 'text_data');
          $planFlag = 0;
          $userSetting = getUserSetting($pdo, $userId);
          if ($userSetting) {
            $paymentId = $userSetting['payment_id'];
            $payment = paymentType($pdo, $paymentId);
            if ($payment['stripe_payment_type'] === 'unknown') {
              $planFlag = 1;
            }
          }
          return response()->json([
            'status'            => 'success',
            'idlist'            => $idArray,
            'name'              => $nameArray,
            'monthly'           => $monthlyArray,
            'type'              => $typeArray,
            'text'              => $textArray,
            'planflag'          => $planFlag,
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

    public function setPlan(Request $request)
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
            'id'    => ['required', 'integer'],
        ]);
        require_once base_path('functions/user.php');
        $pdo = DB::connection()->getPdo();
        $id = (int)($validated['id'] ?? 0);
        try {
            $ok = updateUserPlan($pdo, $id, $userId);
            if (!$ok) {
                return response()->json(
                    ['status' => 'error', 'error' => 'プラン登録に失敗しました。'],
                    404,
                    [],
                    JSON_UNESCAPED_UNICODE
                );
            }
            
            return response()->json([
                'status'       => 'success',
            ], 200, [], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            \Log::error('done failed', ['e' => $e->getMessage()]);
            return response()->json(['status'=>'error','error'=>'サーバーエラーが発生しました'], 500);
        }
    }

    public function plan(Request $request)
    {
        require_once base_path('functions/user.php');
        $pdo = DB::connection()->getPdo();
        $results = planList($pdo);
        if ($results) {
          $idArray       = array_column($results, 'id');
          $nameArray     = array_column($results, 'plan_name');
          $typeArray     = array_column($results, 'stripe_plan_type');
          return response()->json([
            'status'            => 'success',
            'idlist'            => $idArray,
            'namelist'          => $nameArray,
            'typelist'          => $typeArray,
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

    public function payment(Request $request)
    {
        require_once base_path('functions/user.php');
        $pdo = DB::connection()->getPdo();
        $results = paymentList($pdo);
        if ($results) {
          $idArray       = array_column($results, 'id');
          $nameArray     = array_column($results, 'payment_name');
          $typeArray     = array_column($results, 'stripe_payment_type');
          return response()->json([
            'status'            => 'success',
            'idlist'            => $idArray,
            'namelist'          => $nameArray,
            'typelist'          => $typeArray,
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
