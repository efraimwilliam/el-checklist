<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Pesan Telegram yang menjadi "kartu" satu permintaan / kendala (pesan bot, atau pesan asli pengguna bila `origin`). */
class TelegramCard extends Model
{
    protected $fillable = ['chat_id', 'message_id', 'origin', 'guest_request_id', 'room_issue_id'];

    protected function casts(): array
    {
        return ['origin' => 'boolean'];
    }
}
