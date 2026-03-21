<?php

namespace App\Models;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class LeaveRequest extends Model
{
    use HasFactory, SoftDeletes, HasAuditFields;

    protected $appends = [
        'request_type_name',
        'status_name',
        'document_url',
        'duration_days',
    ];

    protected $fillable = [
        'employee_id',
        'request_type',
        'start_date',
        'end_date',
        'total_days',
        'reason',
        'institution',
        'education_type',
        'program_course',
        'document_path',
        'document_content',
        'document_mime_type',
        'document_original_name',
        'status',
        'approved_by_manager_id',
        'approval_notes',
        'approved_at',
    ];

    protected $hidden = [
        'document_content',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'approved_at' => 'datetime',
            'total_days' => 'integer',
        ];
    }

    // Constants for request types
    public const TYPE_DOCTOR_LEAVE = 'doctor_leave';
    public const TYPE_ANNUAL_LEAVE = 'annual_leave';
    public const TYPE_EXTERNAL_DUTY = 'external_duty';
    public const TYPE_EDUCATIONAL_ASSIGNMENT = 'educational_assignment';

    // Constants for status
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    /**
     * Relationships
     */
    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function approvedByManager()
    {
        return $this->belongsTo(Employee::class, 'approved_by_manager_id');
    }

    public function approvals()
    {
        return $this->hasMany(LeaveRequestApproval::class)->orderBy('work_date');
    }

    /**
     * Scopes
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopeRejected($query)
    {
        return $query->where('status', self::STATUS_REJECTED);
    }

    public function scopeByEmployee($query, $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('request_type', $type);
    }

    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('start_date', [$startDate, $endDate])
                     ->orWhereBetween('end_date', [$startDate, $endDate]);
    }

    /**
     * Accessors
     */
    public function getRequestTypeNameAttribute()
    {
        return match($this->request_type) {
            self::TYPE_DOCTOR_LEAVE => 'Cuti Dokter',
            self::TYPE_ANNUAL_LEAVE => 'Cuti Tahunan',
            self::TYPE_EXTERNAL_DUTY => 'Dinas Luar',
            self::TYPE_EDUCATIONAL_ASSIGNMENT => 'Tugas Pendidikan',
            default => $this->request_type,
        };
    }

    public function getStatusNameAttribute()
    {
        return match($this->status) {
            self::STATUS_PENDING => 'Menunggu Persetujuan',
            self::STATUS_APPROVED => 'Disetujui',
            self::STATUS_REJECTED => 'Ditolak',
            default => $this->status,
        };
    }

    public function getDocumentUrlAttribute()
    {
        if (!$this->document_path) {
            return null;
        }
        
        return url(Storage::url($this->document_path));
    }

    public function getDurationDaysAttribute()
    {
        if (!$this->start_date || !$this->end_date) {
            return 0;
        }

        $startDate = \Carbon\Carbon::parse($this->start_date);
        $endDate = \Carbon\Carbon::parse($this->end_date);

        return $startDate->diffInDays($endDate) + 1;
    }

    /**
     * Methods
     */
    public function isPending()
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved()
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRejected()
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function canBeApproved()
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function canBeRejected()
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function approve($managerId, $notes = null)
    {
        $this->update([
            'status' => self::STATUS_APPROVED,
            'approved_by_manager_id' => $managerId,
            'approval_notes' => $notes,
            'approved_at' => now(),
        ]);
    }

    public function reject($managerId, $notes = null)
    {
        $this->update([
            'status' => self::STATUS_REJECTED,
            'approved_by_manager_id' => $managerId,
            'approval_notes' => $notes,
            'approved_at' => now(),
        ]);
    }

    /**
     * Check if leave request overlaps with another leave request
     */
    public function hasOverlap($excludeId = null)
    {
        $query = self::where('employee_id', $this->employee_id)
            ->where('status', self::STATUS_APPROVED)
            ->where(function ($q) {
                $q->whereBetween('start_date', [$this->start_date, $this->end_date])
                  ->orWhereBetween('end_date', [$this->start_date, $this->end_date])
                  ->orWhere(function ($q2) {
                      $q2->where('start_date', '<=', $this->start_date)
                         ->where('end_date', '>=', $this->end_date);
                  });
            });

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    /**
     * Boot method
     */
    protected static function boot()
    {
        parent::boot();

        // Auto-calculate total_days before saving
        static::saving(function ($leaveRequest) {
            if ($leaveRequest->start_date && $leaveRequest->end_date) {
                $startDate = \Carbon\Carbon::parse($leaveRequest->start_date);
                $endDate = \Carbon\Carbon::parse($leaveRequest->end_date);
                $leaveRequest->total_days = $startDate->diffInDays($endDate) + 1;
            }
        });

        // Delete document file when model is deleted
        static::deleting(function ($leaveRequest) {
            if ($leaveRequest->document_path && Storage::exists($leaveRequest->document_path)) {
                Storage::delete($leaveRequest->document_path);
            }
        });
    }
}
