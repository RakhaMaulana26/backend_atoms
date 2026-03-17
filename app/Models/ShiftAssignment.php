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

    /**
     * Notes to Shift mapping for auto-resolving shift_id
     * Note: Only working shifts (P, S, M) should map to actual shift records
     * Leave/off days (L, CT, CS, DL, TB, OFF) should return null shift_id
     */
    public static array $notesToShiftMap = [
        'P' => 'pagi',
        'S' => 'siang', 
        'M' => 'malam',
    ];

    /**
     * Non-working notes that should have null shift_id
     */
    public static array $nonWorkingNotes = [
        'L', 'L1', 'L2', 'CT', 'CS', 'DL', 'TB', 'OFF', 'LIBUR', 'CUTI'
    ];

    /**
     * Get the shift name from notes (for display purposes)
     */
    public function getShiftNameAttribute(): string
    {
        // If we have a shift relationship, use it
        if ($this->shift) {
            return $this->shift->name;
        }
        
        // Otherwise map from notes
        $notes = strtoupper(trim($this->notes ?? ''));
        return self::$notesToShiftMap[$notes] ?? $this->notes ?? 'Unknown';
    }

    /**
     * Check if this is a day off / non-working shift
     */
    public function isDayOff(): bool
    {
        $notes = strtoupper(trim($this->notes ?? ''));
        
        // Direct match
        if (in_array($notes, self::$nonWorkingNotes)) {
            return true;
        }
        
        // Prefix match for variations like L1, L2, LIBUR1, etc.
        $notesLower = strtolower($notes);
        return str_starts_with($notesLower, 'l') && !in_array($notes, ['LP']) || // L, L1, L2, Libur, but not LP (lepas pagi)
               str_contains($notesLower, 'libur') ||
               str_contains($notesLower, 'cuti') ||
               str_contains($notesLower, 'off');
    }

    /**
     * Check if this is a working shift (P, S, M)
     */
    public function isWorkingShift(): bool
    {
        $notes = strtoupper(trim($this->notes ?? ''));
        return in_array($notes, ['P', 'S', 'M']);
    }

    /**
     * Auto-resolve shift_id from notes if not set
     * Returns null for non-working days (leave, cuti, etc.)
     */
    public static function resolveShiftIdFromNotes(string $notes): ?int
    {
        $notesUpper = strtoupper(trim($notes));
        
        // For non-working days, return null (no shift_id)
        if (in_array($notesUpper, self::$nonWorkingNotes)) {
            return null;
        }
        
        // Check if it's a leave-like note using prefix/contains
        $notesLower = strtolower(trim($notes));
        if ((str_starts_with($notesLower, 'l') && strlen($notesLower) <= 2) || // L, L1, L2
            str_contains($notesLower, 'libur') ||
            str_contains($notesLower, 'cuti') ||
            str_contains($notesLower, 'off')) {
            return null;
        }
        
        // For working shifts, try to find the shift
        $shiftName = self::$notesToShiftMap[$notesUpper] ?? null;
        
        if (!$shiftName) {
            // Try direct match with shift name
            $shift = Shift::whereRaw('LOWER(name) = ?', [$notesLower])->first();
            return $shift?->id;
        }
        
        $shift = Shift::whereRaw('LOWER(name) = ?', [strtolower($shiftName)])->first();
        return $shift?->id;
    }

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
