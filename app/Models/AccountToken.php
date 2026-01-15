<?php

namespace App\Models;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AccountToken extends Model
{
    use HasFactory, SoftDeletes, HasAuditFields;

    protected $fillable = [
        'user_id',
        'token',
        'type',
        'is_used',
        'expired_at',
    ];

    protected function casts(): array
    {
        return [
            'is_used' => 'boolean',
            'expired_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired()
    {
        return $this->expired_at < now();
    }

    public function isValid()
    {
        return !$this->is_used && !$this->isExpired();
    }
}
