<?php

namespace App\Models;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Shift extends Model
{
    use HasFactory, SoftDeletes, HasAuditFields;

    protected $fillable = [
        'name',
    ];

    public function shiftAssignments()
    {
        return $this->hasMany(ShiftAssignment::class);
    }

    public function shiftRequests()
    {
        return $this->hasMany(ShiftRequest::class);
    }
}
