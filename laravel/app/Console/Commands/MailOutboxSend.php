<?php
// apps/server/laravel/app/Console/Commands/MailOutboxSend.php
namespace App\Console\Commands;

use App\Models\MailOutbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class MailOutboxSend extends Command
{
    protected $signature = 'outbox:send-mail {--limit=20}';
    protected $description = 'Send pending emails from mail_outbox safely (with row locks).';

    public function handle(): int
    {
        $limit = (int)$this->option('limit');

        $rows = DB::transaction(function () use ($limit) {
            $items = MailOutbox::query()
                ->where('status', 'pending')
                ->orderBy('created_at')
                ->limit($limit)
                ->lockForUpdate() // InnoDB row lock
                ->get();

            foreach ($items as $it) {
                $it->tries = $it->tries + 1;
                $it->save();
            }

            return $items;
        });

        if ($rows->isEmpty()) {
            $this->info('No pending mail.');
            return self::SUCCESS;
        }

        foreach ($rows as $row) {
            try {
                Mail::raw($row->body_text, function ($message) use ($row) {
                    $message->to($row->email)
                            ->subject($row->subject);
                });

                $row->status = 'sent';
                $row->last_error = null;
                $row->save();

                $this->info("sent: id={$row->id} to={$row->email}");
            } catch (\Throwable $e) {
                $row->status = 'failed';
                $row->last_error = mb_substr($e->getMessage(), 0, 2000);
                $row->save();

                $this->error("failed: id={$row->id} reason={$row->last_error}");
            }
        }

        return self::SUCCESS;
    }
}
