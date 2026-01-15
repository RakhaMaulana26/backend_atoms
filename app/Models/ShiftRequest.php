<?php

namespace App\Models;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ShiftRequest extends Model
{
    use HasFactory, SoftDeletes, HasAuditFields;

    protected $fillable = [
        'requester_employee_id',
        'target_employee_id',
        'from_roster_day_id',
        'to_roster_day_id',
        'shift_id',
        'reason',
        'status',
        'approved_by_target',
        'approved_by_from_manager',
        'approved_by_to_manager',
    ];

    protected function casts(): array
    {
        return [
            'approved_by_target' => 'boolean',
            'approved_by_from_manager' => 'boolean',
            'approved_by_to_manager' => 'boolean',
        ];
    }

    public function requesterEmployee()
    {
        return $this->belongsTo(Employee::class, 'requester_employee_id');
    }

    public function targetEmployee()
    {
        return $this->belongsTo(Employee::class, 'target_employee_id');
    }

    public function fromRosterDay()
    {
        return $this->belongsTo(RosterDay::class, 'from_roster_day_id');
    }

    public function toRosterDay()
    {
        return $this->belongsTo(RosterDay::class, 'to_roster_day_id');
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function isFullyApproved()
    {
        return $this->approved_by_target 
            && $this->approved_by_from_manager 
            && $this->approved_by_to_manager;
    }
}
