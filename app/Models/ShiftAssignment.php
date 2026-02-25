<?php

namespace App\Models;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ShiftAssignment extends Model
{
    use HasFactory, SoftDeletes, HasAuditFields;

    protected $fillable = [
        'roster_day_id',
        'shift_id',
        'employee_id',
        'notes',
        'span_days',
    ];

    public function rosterDay()
    {
        return $this->belongsTo(RosterDay::class);
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
