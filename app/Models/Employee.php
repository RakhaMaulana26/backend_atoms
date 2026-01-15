<?php

namespace App\Models;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use HasFactory, SoftDeletes, HasAuditFields;

    protected $fillable = [
        'user_id',
        'employee_type',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function shiftAssignments()
    {
        return $this->hasMany(ShiftAssignment::class);
    }

    public function managerDuties()
    {
        return $this->hasMany(ManagerDuty::class);
    }

    public function shiftRequestsAsRequester()
    {
        return $this->hasMany(ShiftRequest::class, 'requester_employee_id');
    }

    public function shiftRequestsAsTarget()
    {
        return $this->hasMany(ShiftRequest::class, 'target_employee_id');
    }
}
