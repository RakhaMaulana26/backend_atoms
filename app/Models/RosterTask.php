<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class RosterTask extends Model
{
    protected $fillable = [
        'date',
        'shift_key',
        'role',
        'assigned_to',
        'title',
        'description',
        'priority',
        'status',
        'created_by',
    ];

    protected $casts = [
        'assigned_to' => 'array',
        'date' => 'date',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignedUsers()
    {
        return User::whereIn('id', $this->assigned_to ?? [])->get();
    }
}
