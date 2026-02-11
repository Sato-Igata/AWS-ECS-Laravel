<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsOutbox extends Model
{
    protected $table = 'sms_outbox';

    protected $fillable = [
        'user_id','to_number','body_text','status','tries','last_error',
    ];

    public $timestamps = true;
}
