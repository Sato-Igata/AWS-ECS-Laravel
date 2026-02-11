<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cookie;

class SessionAuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'login'    => ['required','string'],  // email or tele
            'password' => ['required','string'],
            'code'     => ['required','string'],
        ]);
        $code = $request->string('code')->toString();
        $login = $request->string('login')->toString();
        $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'tele';
        $credentials = [
            $field => $login,
            'password' => $request->string('password')->toString(),
            'is_deleted' => 0,
        ];

        // ① ID / パスワードチェック
        if (!Auth::attempt($credentials)) {
            return response()->json(['message' => 'ログイン情報が正しくありません'], 401);
        }
        
        $user = Auth::user();
        if (!$user) {
          \Log::error('Auth user is null after attempt');
          return response()->json(['message' => 'logged in but user missing'], 500);
        }

        // ② code_data から最新のコードを取得して検証
        try {
          $userStatus = filter_var($login, FILTER_VALIDATE_EMAIL) ? 2 : 1;
          $latestCode = DB::table('code_data')
            ->where('user_id', $user->id)
            ->where('user_status', $userStatus)
            ->where('is_deleted', false)
            ->orderByDesc('created_at')
            ->first();
          if (!$latestCode) {
              // コードが1件も見つからない
            Auth::logout();
            return response()->json(['message' => '確認コードが見つかりません'], 401);
          }
          // 有効期限チェック（例：10分以内に発行されたものだけOK）
          $expiredAt = now()->subMinutes(10);
          if ($latestCode->created_at < $expiredAt) {
            Auth::logout();
            return response()->json(['message' => '確認コードの有効期限が切れています'], 401);
          }
          // コード不一致
          if ($latestCode->code !== $code) {
            Auth::logout();
            return response()->json(['message' => '確認コードが正しくありません'], 401);
          }
          // 使い終わったコードは無効化（再利用させない）
          DB::table('code_data')
            ->where('id', $latestCode->id)
            ->update(['is_deleted' => true]);
        } catch (\Throwable $e) {
          \Log::error('code check failed', ['e' => $e->getMessage()]);
          Auth::logout();
          return response()->json(['message' => '確認コードの検証中にエラーが発生しました'], 500);
        }

        // ③ ここまで来たら「ID/パスワード」＋「確認コード」両方OK
        try {
          // セッション固定化対策（重要）
          $request->session()->regenerate();
        } catch (\Throwable $e) {
          \Log::error('session regenerate failed', ['e' => $e->getMessage()]);
          Auth::logout();
          return response()->json(['message' => 'session error'], 500);
        }
        
        try {
          $user->last_login_at = now();
          $user->save();
        } catch (\Throwable $e) {
          \Log::error('user save failed', ['e' => $e->getMessage()]);
          return response()->json(['message' => 'db save error'], 500);
        }

        // ④ refresh token 発行（平文）→ DB には hash だけ保存 → Cookie に平文を保存
        try {
          // random_bytes を base64url 化
          $refreshPlain = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
          // DB保存用ハッシュ（決定的ハッシュ：検索/一致確認）
          $refreshHash = hash('sha256', $refreshPlain);
          // 有効期限（例：30日）
          $expiresAt = now()->addDays(30);
          // $expiresAt = now()->addMinutes(15);
          $user->refresh_token_hash = $refreshHash;
          $user->refresh_token_expires_at = $expiresAt;
          $user->save();
        } catch (\Throwable $e) {
          \Log::error('refresh token issue failed', ['e' => $e->getMessage()]);
          Auth::logout();
          return response()->json(['message' => 'token issue error'], 500);
        }

        $cookie = cookie(
            'refresh_token',         // name
            $refreshPlain,           // value
            60 * 24 * 30,            // minutes
            // 15,                      // minutes（← 15分）
            '/',                     // path
            null,                    // domain
            false,                   // secure  ※開発中は false（http のため）
            true,                    // httpOnly
            false,                   // raw
            'Lax'                    // sameSite
        );
        return response()->json([
            'message' => 'ok',
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'email' => $user->email,
                'tele' => $user->tele,
            ],
        ])->withCookie($cookie);
    }

    public function logout(Request $request)
    {
        $user = Auth::user();
        Auth::logout();

        // セッション破棄（固定化対策）
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        // DB 側の refresh token も無効化
        if ($user) {
            try {
                $user->refresh_token_hash = null;
                $user->refresh_token_expires_at = null;
                $user->save();
            } catch (\Throwable $e) {
                \Log::error('refresh token clear failed', ['e' => $e->getMessage()]);
            }
        }
        return response()
            ->json(['message' => 'ok'])
            ->withoutCookie('refresh_token');
    }

    public function me(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
          return response()->json(['message' => 'unauthenticated'], 401);
        }
        // $user = $request->user();
        return response()->json([
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'email' => $user->email,
                'tele' => $user->tele,
            ],
        ]);
    }
}
