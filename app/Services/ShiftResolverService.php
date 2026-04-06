<?php

namespace App\Services;

use App\Models\Shift;
use Illuminate\Support\Facades\Log;

/**
 * Centralized service for resolving shift_id from notes
 * Single source of truth to prevent inconsistencies
 */
class ShiftResolverService
{
    /**
     * Mapping from short notes to shift name
     */
    private static array $notesToShiftNameMap = [
        'P' => 'pagi',
        'S' => 'siang',
        'M' => 'malam',
        'PAGI' => 'pagi',
        'SIANG' => 'siang',
        'MALAM' => 'malam',
    ];

    /**
     * Non-working shift notes (should return null shift_id)
     */
    private static array $nonWorkingNotes = [
        'L', 'L1', 'L2',
        'CT', 'CUTI_TAHUNAN', 'CUTI TAHUNAN',
        'CS', 'CUTI_SAKIT', 'CUTI SAKIT',
        'DL', 'DINAS_LUAR', 'DINAS LUAR',
        'TB', 'TUGAS_BELAJAR', 'TUGAS BELAJAR',
        'OFF', 'LIBUR', 'CUTI',
    ];

    /**
     * Resolve shift_id from notes string
     * Returns null for non-working shifts (L, CT, CS, DL, TB, OFF)
     * 
     * @param string|null $notes Short notation or full name (P, S, M, pagi, siang, malam, etc)
     * @return int|null Shift ID or null if not a working shift
     */
    public static function resolveShiftId(?string $notes): ?int
    {
        if (!$notes) {
            Log::warning('ShiftResolverService::resolveShiftId - notes is empty');
            return null;
        }

        $notesUpper = strtoupper(trim($notes));
        $notesLower = strtolower(trim($notes));

        Log::info('ShiftResolverService::resolveShiftId', [
            'input_notes' => $notes,
            'upper' => $notesUpper,
            'lower' => $notesLower,
        ]);

        // Check if it's a non-working note
        if (self::isNonWorkingNote($notesUpper)) {
            Log::info('ShiftResolverService::resolveShiftId - non-working note detected', [
                'notes' => $notes,
            ]);
            return null;
        }

        // Get shift name from mapping, else use as-is
        $shiftName = self::$notesToShiftNameMap[$notesUpper] ?? $notesLower;

        // Query shift by exact name or fuzzy
        $shift = Shift::where('name', $shiftName)
            ->orWhere('name', 'LIKE', '%' . $shiftName . '%')
            ->first();

        if ($shift) {
            Log::info('ShiftResolverService::resolveShiftId - found shift', [
                'notes' => $notes,
                'shift_name' => $shift->name,
                'shift_id' => $shift->id,
            ]);
            return $shift->id;
        }

        Log::warning('ShiftResolverService::resolveShiftId - shift not found', [
            'notes' => $notes,
            'attempted_shift_name' => $shiftName,
        ]);
        return null;
    }

    /**
     * Check if notes represent a non-working shift
     * 
     * @param string $notesUpper Uppercase notes
     * @return bool True if non-working shift
     */
    private static function isNonWorkingNote(string $notesUpper): bool
    {
        // Direct match
        if (in_array($notesUpper, self::$nonWorkingNotes)) {
            return true;
        }

        // Prefix match
        if (str_starts_with($notesUpper, 'L') && strlen($notesUpper) <= 2) {
            return true; // L, L1, L2
        }

        // Contains match
        $lower = strtolower($notesUpper);
        return str_contains($lower, 'libur') ||
               str_contains($lower, 'cuti') ||
               str_contains($lower, 'dinas') ||
               str_contains($lower, 'tugas belajar') ||
               str_contains($lower, 'off');
    }

    /**
     * Get notes display name (for logging/debugging)
     */
    public static function getNotesDisplayName(string $notes): string
    {
        $notesUpper = strtoupper(trim($notes));
        $shiftMap = [
            'P' => 'Pagi',
            'S' => 'Siang',
            'M' => 'Malam',
            'L' => 'Libur',
            'L1' => 'Libur',
            'L2' => 'Libur',
            'CT' => 'Cuti Tahunan',
            'CS' => 'Cuti Sakit',
            'DL' => 'Dinas Luar',
            'TB' => 'Tugas Belajar',
            'OFF' => 'Off',
        ];
        return $shiftMap[$notesUpper] ?? $notes;
    }
}
