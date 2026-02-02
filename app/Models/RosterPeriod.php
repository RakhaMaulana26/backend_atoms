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
        'spreadsheet_url',
        'last_synced_at',
        'created_by',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'month' => 'integer',
            'year' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * Check if roster has linked spreadsheet
     */
    public function hasSpreadsheetLink(): bool
    {
        return !empty($this->spreadsheet_url);
    }

    /**
     * Append snake_case relationship to JSON
     */
    protected $with = [];
    
    protected $appends = [];

    public function rosterDays()
    {
        return $this->hasMany(RosterDay::class);
    }

    public function isPublished()
    {
        return $this->status === 'published';
    }
}
