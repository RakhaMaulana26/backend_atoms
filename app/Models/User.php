<?php

namespace App\Models;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes, HasAuditFields, HasApiTokens;

    // Role constants
    const ROLE_ADMIN = 'Admin';
    const ROLE_CNS = 'Cns';
    const ROLE_SUPPORT = 'Support';
    const ROLE_MANAGER_TEKNIK = 'Manager Teknik';
    const ROLE_GENERAL_MANAGER = 'General Manager';

    // Available roles
    public static function getRoles()
    {
        return [
            self::ROLE_ADMIN => 'Administrator',
            self::ROLE_CNS => 'CNS',
            self::ROLE_SUPPORT => 'Support',
            self::ROLE_MANAGER_TEKNIK => 'Manager Teknik',
            self::ROLE_GENERAL_MANAGER => 'General Manager',
        ];
    }

    protected $fillable = [
        'name',
        'email',
        'role',
        'grade',
        'password',
        'is_active',
        'last_login',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'password' => 'hashed',
            'last_login' => 'datetime',
        ];
    }

    // Relationships
    public function employee()
    {
        return $this->hasOne(Employee::class);
    }

    public function accountTokens()
    {
        return $this->hasMany(AccountToken::class);
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class);
    }

    // Role helper methods
    public function isAdmin()
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isCns()
    {
        return $this->role === self::ROLE_CNS;
    }

    public function isSupport()
    {
        return $this->role === self::ROLE_SUPPORT;
    }

    public function isManagerTeknik()
    {
        return $this->role === self::ROLE_MANAGER_TEKNIK;
    }

    public function isGeneralManager()
    {
        return $this->role === self::ROLE_GENERAL_MANAGER;
    }

    public function getRoleNameAttribute()
    {
        return self::getRoles()[$this->role] ?? $this->role;
    }
}

