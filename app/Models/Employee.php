<?php

namespace App\Models;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use HasFactory, SoftDeletes, HasAuditFields;

    // Employee type constants (same as User roles)
    const TYPE_ADMIN = 'admin';
    const TYPE_CNS = 'cns';
    const TYPE_SUPPORT = 'support';
    const TYPE_MANAGER_TEKNIK = 'manager_teknik';
    const TYPE_GENERAL_MANAGER = 'general_manager';

    // Available employee types
    public static function getTypes()
    {
        return [
            self::TYPE_ADMIN => 'Administrator',
            self::TYPE_CNS => 'CNS',
            self::TYPE_SUPPORT => 'Support',
            self::TYPE_MANAGER_TEKNIK => 'Manager Teknik',
            self::TYPE_GENERAL_MANAGER => 'General Manager',
        ];
    }

    protected $fillable = [
        'user_id',
        'employee_type',
        'group_number',
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

    // Employee type helper methods
    public function isAdmin()
    {
        return $this->employee_type === self::TYPE_ADMIN;
    }

    public function isCns()
    {
        return $this->employee_type === self::TYPE_CNS;
    }

    public function isSupport()
    {
        return $this->employee_type === self::TYPE_SUPPORT;
    }

    public function isManagerTeknik()
    {
        return $this->employee_type === self::TYPE_MANAGER_TEKNIK;
    }

    public function isGeneralManager()
    {
        return $this->employee_type === self::TYPE_GENERAL_MANAGER;
    }

    public function getEmployeeTypeNameAttribute()
    {
        return self::getTypes()[$this->employee_type] ?? $this->employee_type;
    }
}
