<?php

namespace App\Models;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RosterPeriod extends Model
{
    use HasFactory, SoftDeletes, HasAuditFields;

    protected $fillable = [
        'month',
        'year',
        'status',
        'created_by',
    ];

    public function rosterDays()
    {
        return $this->hasMany(RosterDay::class);
    }

    public function isPublished()
    {
        return $this->status === 'published';
    }
}
