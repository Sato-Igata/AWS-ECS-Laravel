<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

// SMS例：Twilioを使う想定（後述）
// use Twilio\Rest\Client as TwilioClient;

class DispatchOutbox extends Command
{
    protected $signature = 'outbox:dispatch {--limit=50}';
    protected $description = 'Send pending emails/SMS from outbox tables';

    public function handle(): int
    {
        $limit = (int)$this->option('limit');
        $maxTries = 5;

        $this->dispatchMail($limit, $maxTries);
        $this->dispatchSms($limit, $maxTries);

        return self::SUCCESS;
    }

    private function dispatchMail(int $limit, int $maxTries): void
    {
        // 1) pendingをロックして取得（並列対策）
        $rows = DB::transaction(function () use ($limit) {
            $items = DB::table('mail_outbox')
                ->where('status', 'pending')
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate() // FOR UPDATE
                ->get();

            // ここで processing にして “掴んだ” 状態にする
            if ($items->count() > 0) {
                DB::table('mail_outbox')
                    ->whereIn('id', $items->pluck('id')->all())
                    ->update(['status' => 'processing']);
            }
            return $items;
        });

        foreach ($rows as $row) {
            try {
                // 2) 送信
                Mail::raw($row->body_text, function ($message) use ($row) {
                    $message->to($row->email)
                        ->subject($row->subject);
                });

                // 3) 成功
                DB::table('mail_outbox')->where('id', $row->id)->update([
                    'status' => 'sent',
                    'tries' => DB::raw('tries + 1'),
                    'last_error' => null,
                ]);
            } catch (Throwable $e) {
                $tries = ((int)$row->tries) + 1;
                $nextStatus = ($tries >= $maxTries) ? 'failed' : 'pending';

                DB::table('mail_outbox')->where('id', $row->id)->update([
                    'status' => $nextStatus,
                    'tries' => $tries,
                    'last_error' => mb_strimwidth($e->getMessage(), 0, 2000, '...'),
                ]);
            }
        }
    }

    private function dispatchSms(int $limit, int $maxTries): void
    {
        $rows = DB::transaction(function () use ($limit) {
            $items = DB::table('sms_outbox')
                ->where('status', 'pending')
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            if ($items->count() > 0) {
                DB::table('sms_outbox')
                    ->whereIn('id', $items->pluck('id')->all())
                    ->update(['status' => 'processing']);
            }
            return $items;
        });

        foreach ($rows as $row) {
            try {
                // 例：Twilio送信（後述の設定が必要）
                // $twilio = new TwilioClient(config('services.twilio.sid'), config('services.twilio.token'));
                // $twilio->messages->create($row->to_number, [
                //     'from' => config('services.twilio.from'),
                //     'body' => $row->body_text,
                // ]);

                // ひとまずダミー（実装したら消す）
                // throw new \Exception("SMS provider not configured.");

                DB::table('sms_outbox')->where('id', $row->id)->update([
                    'status' => 'sent',
                    'tries' => DB::raw('tries + 1'),
                    'last_error' => null,
                ]);
            } catch (Throwable $e) {
                $tries = ((int)$row->tries) + 1;
                $nextStatus = ($tries >= $maxTries) ? 'failed' : 'pending';

                DB::table('sms_outbox')->where('id', $row->id)->update([
                    'status' => $nextStatus,
                    'tries' => $tries,
                    'last_error' => mb_strimwidth($e->getMessage(), 0, 2000, '...'),
                ]);
            }
        }
    }
}
