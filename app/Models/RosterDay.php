<?php

namespace App\Models;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RosterDay extends Model
{
    use HasFactory, SoftDeletes, HasAuditFields;

    protected $fillable = [
        'roster_period_id',
        'work_date',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
        ];
    }

    public function rosterPeriod()
    {
        return $this->belongsTo(RosterPeriod::class);
    }

    public function shiftAssignments()
    {
        return $this->hasMany(ShiftAssignment::class);
    }

    public function managerDuties()
    {
        return $this->hasMany(ManagerDuty::class);
    }

    public function shiftRequestsFrom()
    {
        return $this->hasMany(ShiftRequest::class, 'from_roster_day_id');
    }

    public function shiftRequestsTo()
    {
        return $this->hasMany(ShiftRequest::class, 'to_roster_day_id');
    }
}
