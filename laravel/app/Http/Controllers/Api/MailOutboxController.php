<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MailOutbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class MailOutboxController extends Controller
{
    public function send(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $email = $data['email'];

        // ① emailで「最新の pending 1件」を安全に掴む（行ロック）
        $row = DB::transaction(function () use ($email) {
            $row = MailOutbox::query()
                ->where('email', $email)
                ->where('status', 'pending')      // pendingだけ対象（重要）
                ->orderByDesc('created_at')       // 最新
                ->orderByDesc('id')               // created_at同値対策
                ->lockForUpdate()
                ->first();

            if (!$row) {
                return null;
            }

            // 試行回数 +1（ロック中なので競合しない）
            $row->tries = $row->tries + 1;
            $row->save();

            return $row;
        });

        if (!$row) {
            return response()->json([
                'message' => 'No pending mail for this email.',
            ], 404);
        }

        // ② 実送信
        try {
            Mail::raw($row->body_text, function ($message) use ($row) {
                $message->to($row->email)
                    ->subject($row->subject);
            });

            $row->status = 'sent';
            $row->last_error = null;
            $row->save();

            return response()->json([
                'id'     => $row->id,
                'status' => $row->status,
                'error'  => $row->last_error,
            ], 200);
        } catch (\Throwable $e) {
            $row->status = 'failed';
            $row->last_error = mb_substr($e->getMessage(), 0, 2000);
            $row->save();

            return response()->json([
                'id'     => $row->id,
                'status' => $row->status,
                'error'  => $row->last_error,
            ], 500);
        }
    }
}
