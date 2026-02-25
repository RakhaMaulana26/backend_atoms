<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\RosterDay;
use App\Models\RosterPeriod;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Helpers\CacheHelper;
use App\Services\GeminiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class RosterImportController extends Controller
{
    /**
     * Shift code mapping from Excel to database
     */
    private $shiftMapping = [
        'P' => 'pagi',
        'S' => 'siang',
        'M' => 'malam',
        'L' => 'libur',
        'OH' => 'office_hour',
        'CS' => 'cuti_sakit',
        'CT' => 'cuti_tahunan',
        'DL' => 'dinas_luar',
        'Stby' => 'standby',
        'SC' => 'standby',
        'S/P' => 'standby_pagi',
        'S/S' => 'standby_siang',
        'S/M' => 'standby_malam',
        '-' => 'lepas_malam',
        'TB' => 'tugas_belajar',
        'CUTI TAHUNAN' => 'cuti_tahunan',
        'CUTI DOKTER' => 'cuti_sakit',
        'CUTI SAKIT' => 'cuti_sakit',
    ];
    
    /**
     * Map shift codes to user-friendly display names
     */
    private $shiftDisplayNames = [
        'P' => 'Pagi',
        'S' => 'Siang',
        'M' => 'Malam',
        'L' => 'Libur',
        'OH' => 'Office Hour',
        'CS' => 'Cuti Sakit',
        'CT' => 'Cuti Tahunan',
        'DL' => 'Dinas Luar',
        'Stby' => 'Standby',
        'SC' => 'Standby On Call',
        'S/P' => 'Standby Pagi',
        'S/S' => 'Standby Siang',
        'S/M' => 'Standby Malam',
        '-' => 'Lepas Malam',
        'TB' => 'Tugas Belajar',
    ];

    /**
     * POST /rosters/import
     * Import roster from Excel file
     */
    public function import(Request $request)
    {
        // Increase PHP timeout for AI processing
        set_time_limit(300); // 5 minutes
        
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:10240', // Max 10MB
            'use_ai' => 'boolean', // Optional: use AI to parse
        ]);

        $useAI = $request->boolean('use_ai', false);

        DB::beginTransaction();
        try {
            $file = $request->file('file');
            $reader = new Xlsx();
            $spreadsheet = $reader->load($file->getPathname());
            $sheet = $spreadsheet->getActiveSheet();
            
            // Parse the spreadsheet
            if ($useAI) {
                $parseResult = $this->parseWithAI($sheet);
            } else {
                $parseResult = $this->parseSpreadsheet($sheet);
            }
            
            if (!$parseResult['success']) {
                return response()->json([
                    'message' => 'Failed to parse spreadsheet',
                    'error' => $parseResult['error'],
                ], 422);
            }

            $month = $parseResult['month'];
            $year = $parseResult['year'];
            $employeeSchedules = $parseResult['employees'];

            // Check if roster period already exists
            $rosterPeriod = RosterPeriod::where('month', $month)
                ->where('year', $year)
                ->first();

            if (!$rosterPeriod) {
                // Create new roster period
                $rosterPeriod = RosterPeriod::create([
                    'month' => $month,
                    'year' => $year,
                    'status' => 'draft',
                ]);

                // Create roster days
                $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
                for ($day = 1; $day <= $daysInMonth; $day++) {
                    $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
                    RosterDay::create([
                        'roster_period_id' => $rosterPeriod->id,
                        'work_date' => $date,
                    ]);
                }
            }

            // Ensure all shifts exist
            $this->ensureShiftsExist();

            // Get all roster days
            $rosterDays = RosterDay::where('roster_period_id', $rosterPeriod->id)
                ->orderBy('work_date')
                ->get()
                ->keyBy(function ($day) {
                    return (int) date('j', strtotime($day->work_date));
                });

            // Process each employee schedule
            $stats = [
                'employees_processed' => 0,
                'employees_created' => 0,
                'assignments_created' => 0,
                'assignments_skipped' => 0,
                'errors' => [],
            ];

            foreach ($employeeSchedules as $empSchedule) {
                $employeeName = trim($empSchedule['name']);
                
                // Find or create employee
                $employee = $this->findOrCreateEmployee($empSchedule, $stats);
                
                if (!$employee) {
                    $stats['errors'][] = "Could not find or create employee: {$employeeName}";
                    continue;
                }

                $stats['employees_processed']++;

                // Create shift assignments for each day (store individually, frontend will auto-merge)
                foreach ($empSchedule['schedule'] as $dayNum => $shiftData) {
                    if (!isset($rosterDays[$dayNum])) {
                        continue;
                    }

                    // Handle both string format and array format (for backward compatibility)
                    $shiftCode = is_array($shiftData) ? $shiftData['code'] : $shiftData;
                    // Always set span_days = 1 (frontend will auto-merge consecutive cells)
                    $spanDays = 1;

                    $rosterDay = $rosterDays[$dayNum];
                    $shiftMapping = $this->mapShiftCodeWithNotes($shiftCode !== null ? (string) $shiftCode : null);
                    
                    if (!$shiftMapping['shift']) {
                        continue; // Skip empty cells
                    }

                    $shift = Shift::where('name', $shiftMapping['shift'])->first();
                    if (!$shift) {
                        continue;
                    }

                    // Check if assignment already exists
                    $existing = ShiftAssignment::where('roster_day_id', $rosterDay->id)
                        ->where('employee_id', $employee->id)
                        ->first();

                    if ($existing) {
                        // Update existing assignment
                        $existing->shift_id = $shift->id;
                        $existing->notes = $shiftMapping['notes'];
                        $existing->span_days = $spanDays;
                        $existing->save();
                        $stats['assignments_skipped']++;
                    } else {
                        // Create new assignment
                        ShiftAssignment::create([
                            'roster_day_id' => $rosterDay->id,
                            'employee_id' => $employee->id,
                            'shift_id' => $shift->id,
                            'notes' => $shiftMapping['notes'],
                            'span_days' => $spanDays,
                        ]);
                        $stats['assignments_created']++;
                    }
                }
            }

            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => 'import',
                'module' => 'roster',
                'reference_id' => $rosterPeriod->id,
                'description' => "Imported roster from Excel for {$month}/{$year}. " .
                    "Processed {$stats['employees_processed']} employees, " .
                    "created {$stats['assignments_created']} assignments.",
            ]);

            DB::commit();

            // Clear roster cache
            CacheHelper::clearRosterCache();

            // Load relationships for frontend cache
            $rosterPeriod->load([
                'rosterDays.shiftAssignments.shift',
                'rosterDays.shiftAssignments.employee.user',
            ]);

            // Include all employees (for roster view initialization)
            $allEmployees = \App\Models\Employee::with('user')
                ->where('is_active', true)
                ->whereNotNull('group_number')
                ->where('group_number', '>', 0)
                ->orderBy('employee_type')
                ->orderBy('group_number')
                ->orderBy('user_id')
                ->get();

            // Include all shifts (for dropdown options)
            $allShifts = \App\Models\Shift::orderBy('id')->get();

            return response()->json([
                'message' => 'Roster imported successfully',
                'data' => [
                    'roster_period' => $rosterPeriod,
                    'all_employees' => $allEmployees,
                    'all_shifts' => $allShifts,
                    'month' => $month,
                    'year' => $year,
                    'stats' => $stats,
                ],
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to import roster',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Parse the Excel spreadsheet
     */
    private function parseSpreadsheet($sheet): array
    {
        $highestRow = $sheet->getHighestRow();
        
        // Extract month and year from row 2 (format: "BULAN : JANUARI 2026")
        $monthYearText = $sheet->getCell('A2')->getValue();
        $monthYear = $this->parseMonthYear($monthYearText);
        
        if (!$monthYear) {
            // Try row 39 for second section
            $monthYearText = $sheet->getCell('A39')->getValue();
            $monthYear = $this->parseMonthYear($monthYearText);
        }

        if (!$monthYear) {
            return [
                'success' => false,
                'error' => 'Could not parse month/year from spreadsheet',
            ];
        }

        $employees = [];
        
        // Find employee data rows
        for ($row = 1; $row <= $highestRow; $row++) {
            $colA = $sheet->getCell('A' . $row)->getValue();
            $colB = $sheet->getCell('B' . $row)->getValue();
            
            // Skip non-employee rows
            if (!is_numeric($colA) || empty($colB)) {
                continue;
            }

            // Skip if column B contains shift legend
            if (str_contains($colB, ':: ') || str_contains($colB, ': ')) {
                continue;
            }

            // This is an employee row
            $employee = [
                'no' => (int) $colA,
                'name' => trim($colB),
                'kelas' => $sheet->getCell('C' . $row)->getValue(),
                'jabatan' => $sheet->getCell('D' . $row)->getValue(),
                'keterangan' => $sheet->getCell('AJ' . $row)->getValue(),
                'schedule' => [],
            ];

            // Read schedule from columns E (day 1) to AI (day 31)
            // E=1, F=2, G=3, ..., AI=31
            $scheduleColumns = [
                'E' => 1, 'F' => 2, 'G' => 3, 'H' => 4, 'I' => 5,
                'J' => 6, 'K' => 7, 'L' => 8, 'M' => 9, 'N' => 10,
                'O' => 11, 'P' => 12, 'Q' => 13, 'R' => 14, 'S' => 15,
                'T' => 16, 'U' => 17, 'V' => 18, 'W' => 19, 'X' => 20,
                'Y' => 21, 'Z' => 22, 'AA' => 23, 'AB' => 24, 'AC' => 25,
                'AD' => 26, 'AE' => 27, 'AF' => 28, 'AG' => 29, 'AH' => 30,
                'AI' => 31,
            ];

            foreach ($scheduleColumns as $col => $day) {
                $cellCoordinate = $col . $row;
                $shiftCode = $sheet->getCell($cellCoordinate)->getValue();
                
                // If cell is empty, check if it's part of a merged range
                if (($shiftCode === null || $shiftCode === '') && $this->isPartOfMergedCell($sheet, $cellCoordinate)) {
                    // Get the value from the merged range's top-left cell
                    $shiftCode = $this->getMergedCellValue($sheet, $cellCoordinate);
                }
                
                if ($shiftCode !== null && $shiftCode !== '') {
                    // Store each cell individually (no span_days)
                    // Frontend will auto-merge consecutive identical cells
                    $employee['schedule'][$day] = trim($shiftCode);
                }
            }

            // Only add if we have schedule data
            if (!empty($employee['schedule'])) {
                $employees[] = $employee;
            }
        }

        return [
            'success' => true,
            'month' => $monthYear['month'],
            'year' => $monthYear['year'],
            'employees' => $employees,
        ];
    }

    /**
     * Parse month and year from text like "BULAN : JANUARI 2026"
     */
    private function parseMonthYear(string $text): ?array
    {
        $months = [
            'JANUARI' => 1, 'FEBRUARI' => 2, 'MARET' => 3, 'APRIL' => 4,
            'MEI' => 5, 'JUNI' => 6, 'JULI' => 7, 'AGUSTUS' => 8,
            'SEPTEMBER' => 9, 'OKTOBER' => 10, 'NOVEMBER' => 11, 'DESEMBER' => 12,
            'JANUARY' => 1, 'FEBRUARY' => 2, 'MARCH' => 3, 'MAY' => 5,
            'JUNE' => 6, 'JULY' => 7, 'AUGUST' => 8, 'OCTOBER' => 10,
            'DECEMBER' => 12,
        ];

        $text = strtoupper($text);
        
        foreach ($months as $monthName => $monthNum) {
            if (str_contains($text, $monthName)) {
                // Extract year (4-digit number)
                if (preg_match('/(\d{4})/', $text, $matches)) {
                    return [
                        'month' => $monthNum,
                        'year' => (int) $matches[1],
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Map shift code from Excel to database shift name and notes
     * Returns ['shift' => shift_name, 'notes' => original_text]
     */
    private function mapShiftCode(?string $code): ?string
    {
        // Handle null or empty
        if ($code === null || $code === '') {
            return null;
        }
        
        $code = trim($code);
        $codeUpper = strtoupper($code);
        
        // Direct mapping - case insensitive
        foreach ($this->shiftMapping as $key => $value) {
            if (strtoupper($key) === $codeUpper) {
                return $value;
            }
        }

        // Handle special cases and variations
        if (str_contains($codeUpper, 'CUTI TAHUNAN') || $codeUpper === 'CT') {
            return 'cuti_tahunan';
        }
        if (str_contains($codeUpper, 'CUTI SAKIT') || str_contains($codeUpper, 'CUTI DOKTER') || $codeUpper === 'CS') {
            return 'cuti_sakit';
        }
        if (str_contains($codeUpper, 'DINAS LUAR') || $codeUpper === 'DL') {
            return 'dinas_luar';
        }
        if (str_contains($codeUpper, 'OFFICE HOUR') || $codeUpper === 'OH') {
            return 'office_hour';
        }
        if (str_contains($codeUpper, 'TUGAS BELAJAR') || $codeUpper === 'TB') {
            return 'tugas_belajar';
        }
        if (str_contains($codeUpper, 'STANDBY') || str_contains($codeUpper, 'STBY')) {
            return 'standby';
        }
        if (str_contains($codeUpper, 'LEPAS')) {
            return 'lepas_malam';
        }
        if (str_contains($codeUpper, 'LIBUR')) {
            return 'libur';
        }

        // If code doesn't match any known shift, return null
        // The caller should check for null and handle custom notes
        return null;
    }

    /**
     * Map shift code from Excel and extract notes if code is not a standard shift
     * Returns ['shift' => shift_name, 'notes' => custom_text_or_null]
     */
    private function mapShiftCodeWithNotes(?string $code): array
    {
        if ($code === null || trim($code) === '') {
            return ['shift' => null, 'notes' => null];
        }

        $originalCode = trim($code);
        $upperCode = strtoupper($originalCode);
        $shiftName = $this->mapShiftCode($code);

        if ($shiftName) {
            // ALWAYS save the original Excel text as notes
            // This ensures whatever is written in Excel appears exactly in the table
            return ['shift' => $shiftName, 'notes' => $originalCode];
        }

        // Code doesn't match any known shift
        // Use a generic shift and store original text as notes
        return ['shift' => 'libur', 'notes' => $originalCode];
    }

    /**
     * Auto-merge consecutive days with the same shift code and notes
     * Returns modified schedule with span_days set for merged entries
     */
    private function autoMergeConsecutiveDays(array $schedule): array
    {
        if (empty($schedule)) {
            return $schedule;
        }

        // Sort by day number
        ksort($schedule);
        
        $result = [];
        $skipUntil = 0;
        
        foreach ($schedule as $dayNum => $shiftData) {
            // Skip if this day was already merged into a previous entry
            if ($dayNum <= $skipUntil) {
                continue;
            }
            
            $currentCode = is_array($shiftData) ? $shiftData['code'] : $shiftData;
            $currentSpan = is_array($shiftData) && isset($shiftData['span']) ? $shiftData['span'] : 1;
            
            // If already merged from Excel, keep it as is
            if ($currentSpan > 1) {
                $result[$dayNum] = $shiftData;
                $skipUntil = $dayNum + $currentSpan - 1;
                continue;
            }
            
            // Look ahead for consecutive days with same code
            $consecutiveDays = 1;
            $nextDay = $dayNum + 1;
            
            while (isset($schedule[$nextDay])) {
                $nextCode = is_array($schedule[$nextDay]) ? $schedule[$nextDay]['code'] : $schedule[$nextDay];
                $nextSpan = is_array($schedule[$nextDay]) && isset($schedule[$nextDay]['span']) ? $schedule[$nextDay]['span'] : 1;
                
                // Stop if next day has different code or is already merged
                if ($nextCode !== $currentCode || $nextSpan > 1) {
                    break;
                }
                
                $consecutiveDays++;
                $nextDay++;
            }
            
            // Store the entry with its span
            $result[$dayNum] = [
                'code' => $currentCode,
                'span' => $consecutiveDays,
            ];
            
            $skipUntil = $dayNum + $consecutiveDays - 1;
        }
        
        return $result;
    }

    /**
     * Find or create employee based on name
     */
    private function findOrCreateEmployee(array $empData, array &$stats): ?Employee
    {
        $name = trim($empData['name']);
        
        // Try to find existing employee by name
        $employee = Employee::whereHas('user', function ($query) use ($name) {
            $query->where('name', $name);
        })->first();

        if ($employee) {
            return $employee;
        }

        // Create new user and employee
        $email = strtolower(str_replace([' ', '.', ',', "'"], ['_', '', '', ''], $name)) . '@airnav.com';
        
        // Check if email already exists
        $existingUser = User::where('email', $email)->first();
        if ($existingUser) {
            return $existingUser->employee;
        }

        // Determine role based on jabatan
        $jabatan = strtoupper($empData['jabatan'] ?? '');
        $role = User::ROLE_CNS;
        $employeeType = 'CNS';

        if (str_contains($jabatan, 'SPV') || str_contains($jabatan, 'SUPERVISOR')) {
            $role = User::ROLE_MANAGER_TEKNIK;
            $employeeType = 'Manager Teknik';
        } elseif (str_contains($jabatan, 'TFP')) {
            $role = User::ROLE_SUPPORT;
            $employeeType = 'Support';
        }

        // Create user
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'password' => Hash::make('password123'),
            'is_active' => true,
            'grade' => $empData['kelas'] ?? null,
        ]);

        // Create employee
        $employee = $user->employee()->create([
            'employee_type' => $employeeType,
            'is_active' => true,
        ]);

        $stats['employees_created']++;

        return $employee;
    }

    /**
     * Ensure all required shifts exist in database
     */
    private function ensureShiftsExist(array $additionalShifts = []): void
    {
        $shifts = array_unique(array_merge(
            array_values($this->shiftMapping),
            $additionalShifts
        ));
        
        foreach ($shifts as $shiftName) {
            Shift::firstOrCreate(['name' => $shiftName]);
        }
    }

    /**
     * Get the span (number of columns) for a merged cell
     * Returns 1 if not merged, or number of days spanned if merged
     */
    private function getMergedCellSpan($sheet, string $cellCoordinate, array $scheduleColumns): int
    {
        $mergeRanges = $sheet->getMergeCells();
        
        foreach ($mergeRanges as $mergeRange) {
            // Parse merge range (e.g., "E5:H5")
            [$rangeStart, $rangeEnd] = explode(':', $mergeRange);
            
            // Check if current cell is the start of this merge range
            if ($cellCoordinate === $rangeStart) {
                $startCol = preg_replace('/[0-9]+/', '', $rangeStart);
                $endCol = preg_replace('/[0-9]+/', '', $rangeEnd);
                
                // Count how many day columns are spanned
                $startDay = $scheduleColumns[$startCol] ?? null;
                $endDay = $scheduleColumns[$endCol] ?? null;
                
                if ($startDay !== null && $endDay !== null) {
                    return $endDay - $startDay + 1;
                }
            }
        }
        
        return 1; // Not merged
    }

    /**
     * Check if a cell is part of a merged cell range
     */
    private function isPartOfMergedCell($sheet, string $cellCoordinate): bool
    {
        $mergeRanges = $sheet->getMergeCells();
        
        foreach ($mergeRanges as $mergeRange) {
            if ($this->isCellInRange($cellCoordinate, $mergeRange)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get the value from the top-left cell of a merged range
     */
    private function getMergedCellValue($sheet, string $cellCoordinate): ?string
    {
        $mergeRanges = $sheet->getMergeCells();
        
        foreach ($mergeRanges as $mergeRange) {
            if ($this->isCellInRange($cellCoordinate, $mergeRange)) {
                // Get the top-left cell of the merged range
                [$rangeStart, $rangeEnd] = explode(':', $mergeRange);
                return $sheet->getCell($rangeStart)->getValue();
            }
        }
        
        return null;
    }

    /**
     * Check if a cell coordinate is within a range (e.g., "E5" in "E5:H5")
     */
    private function isCellInRange(string $cellCoordinate, string $range): bool
    {
        [$rangeStart, $rangeEnd] = explode(':', $range);
        
        // Extract column and row from coordinates
        preg_match('/([A-Z]+)(\d+)/', $cellCoordinate, $cellMatches);
        preg_match('/([A-Z]+)(\d+)/', $rangeStart, $startMatches);
        preg_match('/([A-Z]+)(\d+)/', $rangeEnd, $endMatches);
        
        if (count($cellMatches) < 3 || count($startMatches) < 3 || count($endMatches) < 3) {
            return false;
        }
        
        $cellCol = $cellMatches[1];
        $cellRow = (int) $cellMatches[2];
        $startCol = $startMatches[1];
        $startRow = (int) $startMatches[2];
        $endCol = $endMatches[1];
        $endRow = (int) $endMatches[2];
        
        // Use PhpSpreadsheet's coordinate helper to compare columns
        $cellColIndex = Coordinate::columnIndexFromString($cellCol);
        $startColIndex = Coordinate::columnIndexFromString($startCol);
        $endColIndex = Coordinate::columnIndexFromString($endCol);
        
        // Check if cell is within the range
        return $cellColIndex >= $startColIndex && $cellColIndex <= $endColIndex
            && $cellRow >= $startRow && $cellRow <= $endRow;
    }

    /**
     * Parse spreadsheet using AI (Gemini)
     */
    private function parseWithAI($sheet): array
    {
        // Convert spreadsheet to text format
        $textData = $this->spreadsheetToText($sheet);
        
        // Use Gemini to parse
        $geminiService = new GeminiService();
        $parsed = $geminiService->parseRosterData($textData);
        
        if (!$parsed) {
            return [
                'success' => false,
                'error' => 'AI failed to parse the spreadsheet. Make sure GEMINI_API_KEY is configured.',
            ];
        }

        // Validate parsed data
        if (!isset($parsed['month']) || !isset($parsed['year']) || !isset($parsed['employees'])) {
            return [
                'success' => false,
                'error' => 'AI returned incomplete data structure.',
            ];
        }

        // Update shift mapping with AI-detected codes
        if (isset($parsed['shift_codes'])) {
            $this->shiftMapping = array_merge($this->shiftMapping, $parsed['shift_codes']);
        }

        // Convert AI format to internal format
        $employees = [];
        foreach ($parsed['employees'] as $emp) {
            $employees[] = [
                'name' => $emp['name'] ?? 'Unknown',
                'kelas' => $emp['grade'] ?? null,
                'jabatan' => $emp['position'] ?? '',
                'schedule' => $emp['schedule'] ?? [],
            ];
        }

        return [
            'success' => true,
            'month' => (int) $parsed['month'],
            'year' => (int) $parsed['year'],
            'employees' => $employees,
            'ai_shift_codes' => $parsed['shift_codes'] ?? [],
        ];
    }

    /**
     * Convert spreadsheet to text format for AI parsing
     */
    private function spreadsheetToText($sheet): string
    {
        $highestRow = min($sheet->getHighestRow(), 200); // Limit to 200 rows
        $highestCol = $sheet->getHighestColumn();
        $highestColIndex = Coordinate::columnIndexFromString($highestCol);
        $highestColIndex = min($highestColIndex, 50); // Limit to 50 columns
        
        $lines = [];
        
        for ($row = 1; $row <= $highestRow; $row++) {
            $rowData = [];
            for ($colIndex = 1; $colIndex <= $highestColIndex; $colIndex++) {
                $col = Coordinate::stringFromColumnIndex($colIndex);
                $value = $sheet->getCell($col . $row)->getValue();
                if ($value !== null && $value !== '') {
                    $rowData[] = $value;
                }
            }
            if (!empty($rowData)) {
                $lines[] = "Row {$row}: " . implode(' | ', $rowData);
            }
        }
        
        return implode("\n", $lines);
    }

    /**
     * POST /rosters/import-url
     * Import roster from Google Spreadsheet URL
     */
    public function importFromUrl(Request $request)
    {
        set_time_limit(300); // 5 minutes
        
        $request->validate([
            'spreadsheet_url' => 'required|url',
            'use_ai' => 'boolean',
        ]);

        $url = $request->spreadsheet_url;
        $useAI = $request->boolean('use_ai', false);

        DB::beginTransaction();
        try {
            // Parse Google Spreadsheet URL to get export URL
            $exportUrl = $this->getGoogleSheetsExportUrl($url);
            
            if (!$exportUrl) {
                return response()->json([
                    'message' => 'Invalid Google Spreadsheet URL',
                    'error' => 'Please provide a valid Google Sheets URL (e.g., https://docs.google.com/spreadsheets/d/SPREADSHEET_ID/...)',
                ], 422);
            }

            // Download the spreadsheet as XLSX
            $tempFile = tempnam(sys_get_temp_dir(), 'roster_') . '.xlsx';
            
            $ch = curl_init($exportUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 120);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
            curl_setopt($ch, CURLOPT_ENCODING, ''); // Accept any encoding
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet, application/vnd.ms-excel, */*',
                'Accept-Language: en-US,en;q=0.9',
                'Connection: keep-alive',
            ]);
            curl_setopt($ch, CURLOPT_COOKIEJAR, tempnam(sys_get_temp_dir(), 'cookie_'));
            curl_setopt($ch, CURLOPT_COOKIEFILE, '');
            
            $data = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            curl_close($ch);

            // Debug info for troubleshooting
            $debugInfo = "HTTP Code: {$httpCode}, Final URL: {$finalUrl}, Data Length: " . strlen($data);

            if ($httpCode !== 200 || empty($data)) {
                return response()->json([
                    'message' => 'Failed to download spreadsheet',
                    'error' => $error ?: 'Make sure the spreadsheet is publicly accessible (Anyone with the link can view)',
                    'debug' => $debugInfo,
                    'http_code' => $httpCode,
                ], 422);
            }

            // Check if response is HTML (error page) instead of Excel file
            if (str_starts_with(trim($data), '<!DOCTYPE') || str_starts_with(trim($data), '<html')) {
                return response()->json([
                    'message' => 'Failed to download spreadsheet',
                    'error' => 'Google returned an HTML page instead of Excel file. The spreadsheet might require sign-in or is not publicly accessible.',
                    'http_code' => $httpCode,
                ], 422);
            }

            file_put_contents($tempFile, $data);

            // Read and parse the spreadsheet
            $reader = new Xlsx();
            $spreadsheet = $reader->load($tempFile);
            $sheet = $spreadsheet->getActiveSheet();

            // Parse the spreadsheet
            if ($useAI) {
                $parseResult = $this->parseWithAI($sheet);
            } else {
                $parseResult = $this->parseSpreadsheet($sheet);
            }

            // Clean up temp file
            unlink($tempFile);

            if (!$parseResult['success']) {
                return response()->json([
                    'message' => 'Failed to parse spreadsheet',
                    'error' => $parseResult['error'],
                ], 422);
            }

            $month = $parseResult['month'];
            $year = $parseResult['year'];
            $employeeSchedules = $parseResult['employees'];

            // Check if roster period already exists
            $rosterPeriod = RosterPeriod::where('month', $month)
                ->where('year', $year)
                ->first();

            if (!$rosterPeriod) {
                // Create new roster period with spreadsheet URL
                $rosterPeriod = RosterPeriod::create([
                    'month' => $month,
                    'year' => $year,
                    'status' => 'draft',
                    'spreadsheet_url' => $url,
                    'last_synced_at' => now(),
                ]);

                // Create roster days
                $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
                for ($day = 1; $day <= $daysInMonth; $day++) {
                    $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
                    RosterDay::create([
                        'roster_period_id' => $rosterPeriod->id,
                        'work_date' => $date,
                    ]);
                }
            } else {
                // Update existing roster with spreadsheet URL
                $rosterPeriod->spreadsheet_url = $url;
                $rosterPeriod->last_synced_at = now();
                $rosterPeriod->save();
            }

            // Ensure all shifts exist
            $this->ensureShiftsExist();

            // Get all roster days
            $rosterDays = RosterDay::where('roster_period_id', $rosterPeriod->id)
                ->orderBy('work_date')
                ->get()
                ->keyBy(function ($day) {
                    return (int) date('j', strtotime($day->work_date));
                });

            // Process each employee schedule
            $stats = [
                'employees_processed' => 0,
                'employees_created' => 0,
                'assignments_created' => 0,
                'assignments_skipped' => 0,
                'errors' => [],
            ];

            foreach ($employeeSchedules as $empSchedule) {
                $employeeName = trim($empSchedule['name']);
                
                $employee = $this->findOrCreateEmployee($empSchedule, $stats);
                
                if (!$employee) {
                    $stats['errors'][] = "Could not find or create employee: {$employeeName}";
                    continue;
                }

                $stats['employees_processed']++;

                // Store each day individually (frontend will auto-merge)
                foreach ($empSchedule['schedule'] as $dayNum => $shiftData) {
                    if (!isset($rosterDays[$dayNum])) {
                        continue;
                    }

                    // Handle both string format and array format (for backward compatibility)
                    $shiftCode = is_array($shiftData) ? $shiftData['code'] : $shiftData;
                    // Always set span_days = 1 (frontend will auto-merge consecutive cells)
                    $spanDays = 1;

                    $rosterDay = $rosterDays[$dayNum];
                    $shiftMapping = $this->mapShiftCodeWithNotes($shiftCode !== null ? (string) $shiftCode : null);
                    
                    if (!$shiftMapping['shift']) {
                        continue;
                    }

                    $shift = Shift::where('name', $shiftMapping['shift'])->first();
                    if (!$shift) {
                        continue;
                    }

                    $existing = ShiftAssignment::where('roster_day_id', $rosterDay->id)
                        ->where('employee_id', $employee->id)
                        ->first();

                    if ($existing) {
                        $existing->shift_id = $shift->id;
                        $existing->notes = $shiftMapping['notes'];
                        $existing->span_days = $spanDays;
                        $existing->save();
                        $stats['assignments_skipped']++;
                    } else {
                        ShiftAssignment::create([
                            'roster_day_id' => $rosterDay->id,
                            'employee_id' => $employee->id,
                            'shift_id' => $shift->id,
                            'notes' => $shiftMapping['notes'],
                            'span_days' => $spanDays,
                        ]);
                        $stats['assignments_created']++;
                    }
                }
            }

            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => 'import',
                'module' => 'roster',
                'reference_id' => $rosterPeriod->id,
                'description' => "Imported roster from Google Sheets URL for {$month}/{$year}. " .
                    "Processed {$stats['employees_processed']} employees, " .
                    "created {$stats['assignments_created']} assignments.",
            ]);

            DB::commit();

            CacheHelper::clearRosterCache();

            // Load relationships for frontend cache
            $rosterPeriod->load([
                'rosterDays.shiftAssignments.shift',
                'rosterDays.shiftAssignments.employee.user',
            ]);

            return response()->json([
                'message' => 'Roster imported successfully from Google Spreadsheet',
                'data' => [
                    'roster_period' => $rosterPeriod,
                    'month' => $month,
                    'year' => $year,
                    'stats' => $stats,
                ],
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to import roster from URL',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Extract Google Sheets export URL from various URL formats
     */
    private function getGoogleSheetsExportUrl(string $url): ?string
    {
        // Pattern 1: https://docs.google.com/spreadsheets/d/SPREADSHEET_ID/edit...
        // Pattern 2: https://docs.google.com/spreadsheets/d/SPREADSHEET_ID/view...
        // Pattern 3: https://docs.google.com/spreadsheets/d/SPREADSHEET_ID
        
        $pattern = '/docs\.google\.com\/spreadsheets\/d\/([a-zA-Z0-9_-]+)/';
        
        if (preg_match($pattern, $url, $matches)) {
            $spreadsheetId = $matches[1];
            
            // Build export URL
            $exportUrl = "https://docs.google.com/spreadsheets/d/{$spreadsheetId}/export?format=xlsx";
            
            // Only add gid if explicitly present in the URL
            if (preg_match('/[#&?]gid=(\d+)/', $url, $gidMatches)) {
                $exportUrl .= "&gid={$gidMatches[1]}";
            }
            
            return $exportUrl;
        }
        
        return null;
    }

    /**
     * Download spreadsheet from URL
     */
    private function downloadSpreadsheet(string $exportUrl): array
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'roster_') . '.xlsx';
        
        $ch = curl_init($exportUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet, application/vnd.ms-excel, */*',
            'Accept-Language: en-US,en;q=0.9',
            'Connection: keep-alive',
        ]);
        curl_setopt($ch, CURLOPT_COOKIEJAR, tempnam(sys_get_temp_dir(), 'cookie_'));
        curl_setopt($ch, CURLOPT_COOKIEFILE, '');
        
        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode !== 200 || empty($data)) {
            return [
                'success' => false,
                'error' => $error ?: 'Failed to download spreadsheet',
            ];
        }

        // Check if response is HTML
        if (str_starts_with(trim($data), '<!DOCTYPE') || str_starts_with(trim($data), '<html')) {
            return [
                'success' => false,
                'error' => 'Google returned an HTML page instead of Excel file',
            ];
        }

        file_put_contents($tempFile, $data);

        return [
            'success' => true,
            'tempFile' => $tempFile,
        ];
    }

    /**
     * POST /rosters/{id}/sync
     * Sync roster from linked Google Spreadsheet
     */
    public function syncFromSpreadsheet($id)
    {
        set_time_limit(300);

        $rosterPeriod = RosterPeriod::findOrFail($id);

        if (empty($rosterPeriod->spreadsheet_url)) {
            return response()->json([
                'message' => 'No spreadsheet URL linked to this roster',
                'error' => 'Please import from a spreadsheet URL first to link it',
            ], 422);
        }

        DB::beginTransaction();
        try {
            $url = $rosterPeriod->spreadsheet_url;
            $exportUrl = $this->getGoogleSheetsExportUrl($url);

            if (!$exportUrl) {
                return response()->json([
                    'message' => 'Invalid spreadsheet URL stored',
                    'error' => 'The linked spreadsheet URL is invalid',
                ], 422);
            }

            // Download spreadsheet
            $downloadResult = $this->downloadSpreadsheet($exportUrl);
            
            if (!$downloadResult['success']) {
                return response()->json([
                    'message' => 'Failed to download spreadsheet',
                    'error' => $downloadResult['error'],
                ], 422);
            }

            $tempFile = $downloadResult['tempFile'];

            // Read and parse
            $reader = new Xlsx();
            $spreadsheet = $reader->load($tempFile);
            $sheet = $spreadsheet->getActiveSheet();
            $parseResult = $this->parseSpreadsheet($sheet);

            unlink($tempFile);

            if (!$parseResult['success']) {
                return response()->json([
                    'message' => 'Failed to parse spreadsheet',
                    'error' => $parseResult['error'],
                ], 422);
            }

            $employeeSchedules = $parseResult['employees'];

            // Ensure shifts exist
            $this->ensureShiftsExist();

            // Get roster days
            $rosterDays = RosterDay::where('roster_period_id', $rosterPeriod->id)
                ->orderBy('work_date')
                ->get()
                ->keyBy(function ($day) {
                    return (int) date('j', strtotime($day->work_date));
                });

            // Process assignments
            $stats = [
                'employees_processed' => 0,
                'employees_created' => 0,
                'assignments_created' => 0,
                'assignments_updated' => 0,
                'errors' => [],
            ];

            foreach ($employeeSchedules as $empSchedule) {
                $employee = $this->findOrCreateEmployee($empSchedule, $stats);
                
                if (!$employee) {
                    $stats['errors'][] = "Could not find or create employee: " . trim($empSchedule['name']);
                    continue;
                }

                $stats['employees_processed']++;

                // Store each day individually (frontend will auto-merge)
                foreach ($empSchedule['schedule'] as $dayNum => $shiftData) {
                    if (!isset($rosterDays[$dayNum])) continue;

                    // Handle both string format and array format (for backward compatibility)
                    $shiftCode = is_array($shiftData) ? $shiftData['code'] : $shiftData;
                    // Always set span_days = 1 (frontend will auto-merge consecutive cells)
                    $spanDays = 1;

                    $rosterDay = $rosterDays[$dayNum];
                    $shiftMapping = $this->mapShiftCodeWithNotes($shiftCode !== null ? (string) $shiftCode : null);
                    if (!$shiftMapping['shift']) continue;

                    $shift = Shift::where('name', $shiftMapping['shift'])->first();
                    if (!$shift) continue;

                    $existing = ShiftAssignment::where('roster_day_id', $rosterDay->id)
                        ->where('employee_id', $employee->id)
                        ->first();

                    if ($existing) {
                        if ($existing->shift_id !== $shift->id || $existing->notes !== $shiftMapping['notes'] || $existing->span_days !== $spanDays) {
                            $existing->shift_id = $shift->id;
                            $existing->notes = $shiftMapping['notes'];
                            $existing->span_days = $spanDays;
                            $existing->save();
                            $stats['assignments_updated']++;
                        }
                    } else {
                        ShiftAssignment::create([
                            'roster_day_id' => $rosterDay->id,
                            'employee_id' => $employee->id,
                            'shift_id' => $shift->id,
                            'notes' => $shiftMapping['notes'],
                            'span_days' => $spanDays,
                        ]);
                        $stats['assignments_created']++;
                    }
                }
            }

            // Update last synced timestamp
            $rosterPeriod->last_synced_at = now();
            $rosterPeriod->save();

            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => 'sync',
                'module' => 'roster',
                'reference_id' => $rosterPeriod->id,
                'description' => "Synced roster {$rosterPeriod->month}/{$rosterPeriod->year} from Google Sheets. " .
                    "Updated {$stats['assignments_updated']}, created {$stats['assignments_created']} assignments.",
            ]);

            DB::commit();
            CacheHelper::clearRosterCache();

            return response()->json([
                'message' => 'Roster synced successfully',
                'data' => [
                    'roster_period' => $rosterPeriod->fresh(),
                    'stats' => $stats,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to sync roster',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * PUT /rosters/{id}/spreadsheet-url
     * Update or remove linked spreadsheet URL
     */
    public function updateSpreadsheetUrl(Request $request, $id)
    {
        $request->validate([
            'spreadsheet_url' => 'nullable|url',
        ]);

        $rosterPeriod = RosterPeriod::findOrFail($id);

        $url = $request->spreadsheet_url;

        if ($url) {
            // Validate it's a Google Sheets URL
            if (!$this->getGoogleSheetsExportUrl($url)) {
                return response()->json([
                    'message' => 'Invalid Google Spreadsheet URL',
                ], 422);
            }
        }

        $rosterPeriod->spreadsheet_url = $url;
        $rosterPeriod->save();

        return response()->json([
            'message' => $url ? 'Spreadsheet URL linked successfully' : 'Spreadsheet URL removed',
            'data' => $rosterPeriod,
        ]);
    }

    /**
     * POST /rosters/{id}/push-to-spreadsheet
     * Push roster data back to Google Spreadsheet (two-way sync)
     */
    public function pushToSpreadsheet($id)
    {
        $rosterPeriod = RosterPeriod::findOrFail($id);

        if (empty($rosterPeriod->spreadsheet_url)) {
            return response()->json([
                'message' => 'Roster is not linked to any spreadsheet',
            ], 422);
        }

        try {
            $googleSheetsService = new \App\Services\GoogleSheetsService();
            $result = $googleSheetsService->syncRosterToSpreadsheet($rosterPeriod);

            // Update last synced timestamp
            $rosterPeriod->last_synced_at = now();
            $rosterPeriod->save();

            return response()->json([
                'message' => 'Roster data pushed to spreadsheet successfully',
                'data' => [
                    'roster_period' => $rosterPeriod,
                    'sync_result' => $result,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to push data to spreadsheet',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
