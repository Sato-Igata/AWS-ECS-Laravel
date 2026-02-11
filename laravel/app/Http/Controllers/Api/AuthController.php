<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => ['required','email'],
            'password' => ['required','string'],
        ]);

        // 認証
        if (!Auth::guard('web')->attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['メールアドレスまたはパスワードが違います。'],
            ]);
        }

        // ★固定化攻撃対策：ログイン成功後にセッションIDを再生成
        $request->session()->regenerate(); // ←重要 :contentReference[oaicite:1]{index=1}

        return response()->json([
            'message' => 'logged_in',
            'user'    => $request->user(), // auth:web なので取れる
        ]);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();

        // ★固定化攻撃対策：セッションを無効化して、新しいCSRFトークンを発行
        $request->session()->invalidate();      // ←重要
        $request->session()->regenerateToken(); // ←推奨 :contentReference[oaicite:2]{index=2}

        return response()->json([
            'message' => 'logged_out',
        ]);
    }

    public function me(Request $request)
    {
        return response()->json([
            'user' => $request->user(),
        ]);
    }
}
