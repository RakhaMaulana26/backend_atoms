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
        'title',
        'message',
        'is_read',
        'email_sent',
        'email_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
            'email_sent' => 'boolean',
            'email_sent_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
