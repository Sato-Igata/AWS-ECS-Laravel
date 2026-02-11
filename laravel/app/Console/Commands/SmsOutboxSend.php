<?php

namespace App\Console\Commands;

use App\Support\Phone;
use App\Models\SmsOutbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SmsOutboxSend extends Command
{
    protected $signature = 'outbox:send-sms {--limit=20}';
    protected $description = 'Send pending SMS from sms_outbox';

    public function handle(): int
    {
        $limit = (int)$this->option('limit');

        // 送る対象を掴む（pendingのみ）
        $rows = DB::transaction(function () use ($limit) {
            $items = SmsOutbox::query()
                ->where('status', 'pending')
                ->where('tries', '<', 5)
                ->orderBy('created_at')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            foreach ($items as $it) {
                $it->tries = $it->tries + 1;
                $it->save();
            }

            return $items;
        });

        if ($rows->isEmpty()) {
            $this->info('No pending sms.');
            return self::SUCCESS;
        }
        
        foreach ($rows as $row) {
            try {
                $to = Phone::toE164JP($row->to_number);
                if ($to === null) {
                    throw new \RuntimeException('invalid_phone_number: '.$row->to_number);
                }
                $this->sendViaTwilio($to, $row->body_text);
                $this->info("sent: id={$row->id} to={$to}");

                $row->status = 'sent';
                $row->last_error = null;
                $row->save();

                $this->info("sent: id={$row->id} to={$row->to_number}");
            } catch (\Throwable $e) {
                $row->status = 'failed';
                $row->last_error = mb_substr($e->getMessage(), 0, 2000);
                $row->save();

                $this->error("failed: id={$row->id} reason={$row->last_error}");
            }
        }

        return self::SUCCESS;
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
