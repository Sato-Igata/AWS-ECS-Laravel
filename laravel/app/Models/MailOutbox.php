<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailOutbox extends Model
{
    protected $table = 'mail_outbox';

    protected $fillable = [
        'user_id','email','subject','body_text','status','tries','last_error',
    ];

    public $timestamps = true;
}
