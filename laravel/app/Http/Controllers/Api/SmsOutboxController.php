<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SmsOutbox;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SmsOutboxController extends Controller
{
    public function sendLatest(Request $request)
    {
        $data = $request->validate([
            'to_number' => ['required', 'string', 'max:32'],
        ]);

        $toNumber = $data['to_number'];

        // ① 対象（pendingの最新1件）をロックして掴む
        $row = DB::transaction(function () use ($toNumber) {
            $row = SmsOutbox::query()
                ->where('to_number', $toNumber)
                ->where('status', 'pending')
                ->where('tries', '<', 5)
                ->orderByDesc('created_at') // 最新
                ->orderByDesc('id')         // created_at同値対策
                ->lockForUpdate()
                ->first();

            if (!$row) return null;

            $row->tries = $row->tries + 1;
            $row->save();

            return $row;
        });

        if (!$row) {
            return response()->json([
                'message' => 'No pending sms for this number.',
            ], 404);
        }

        // ② 送信
        try {
            $to = Phone::toE164JP($row->to_number);
            if ($to === null) {
                throw new \RuntimeException('invalid_phone_number: '.$row->to_number);
            }

            $this->sendViaTwilio($to, $row->body_text);

            $row->status = 'sent';
            $row->last_error = null;
            $row->save();

            return response()->json([
                'id'     => $row->id,
                'status' => $row->status,
                'error'  => $row->last_error,
                'to'     => $to,
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

    private function sendViaTwilio(string $to, string $body): void
    {
        $sid   = env('TWILIO_SID');
        $token = env('TWILIO_TOKEN');
        $from  = env('TWILIO_FROM');

        if (!$sid || !$token || !$from) {
            throw new \RuntimeException('Twilio env is missing (TWILIO_SID/TWILIO_TOKEN/TWILIO_FROM)');
        }

        $url = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";

        $res = Http::withBasicAuth($sid, $token)
            ->asForm()
            ->post($url, [
                'From' => $from,
                'To'   => $to,
                'Body' => $body,
            ]);

        if (!$res->successful()) {
            throw new \RuntimeException('Twilio API error: '.$res->status().' '.$res->body());
        }
    }
}
