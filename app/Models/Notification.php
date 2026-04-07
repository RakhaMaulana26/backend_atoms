<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Notification extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'sender_id',
        'title',
        'message',
        'is_read',
        'is_starred',
        'type',
        'category',
        'reference_id',
        'data',
        'email_sent',
        'email_sent_at',
        'read_at',
        'scheduled_at',
        'status',
        'error_message',
        'recipient_ids',
    ];

    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
            'is_starred' => 'boolean',
            'email_sent' => 'boolean',
            'email_sent_at' => 'datetime',
            'read_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'recipient_ids' => 'json',
            'data' => 'json',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
