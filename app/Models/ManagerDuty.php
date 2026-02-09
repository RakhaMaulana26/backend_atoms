<?php

namespace App\Models;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ManagerDuty extends Model
{
    use HasFactory, SoftDeletes, HasAuditFields;

    protected $fillable = [
        'roster_day_id',
        'employee_id',
        'duty_type',
        'shift_id',
    ];

    public function rosterDay()
    {
        return $this->belongsTo(RosterDay::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }
}
