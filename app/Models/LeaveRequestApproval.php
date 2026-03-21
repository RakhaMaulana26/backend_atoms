<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LeaveRequestApproval extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'leave_request_id',
        'roster_day_id',
        'work_date',
        'employee_shift_notes',
        'manager_employee_id',
        'status',
        'approval_notes',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date:Y-m-d',
            'approved_at' => 'datetime',
        ];
    }

    public function leaveRequest()
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    public function rosterDay()
    {
        return $this->belongsTo(RosterDay::class);
    }

    public function managerEmployee()
    {
        return $this->belongsTo(Employee::class, 'manager_employee_id');
    }
}
