<?php

namespace App\Services;

use Google\Client;
use Google\Service\Sheets;
use Google\Service\Sheets\ValueRange;
use App\Models\RosterPeriod;
use App\Models\RosterDay;
use App\Models\ShiftAssignment;
use App\Models\Shift;
use Illuminate\Support\Facades\Log;

class GoogleSheetsService
{
    protected ?Client $client = null;
    protected ?Sheets $sheetsService = null;

    /**
     * Initialize Google Client with Service Account credentials
     */
    protected function getClient(): Client
    {
        if ($this->client === null) {
            $this->client = new Client();
            $this->client->setApplicationName('Roster Management System');
            $this->client->setScopes([Sheets::SPREADSHEETS]);
            
            $credentialsPath = storage_path('app/google-credentials.json');
            
            if (!file_exists($credentialsPath)) {
                throw new \Exception('Google credentials file not found at: ' . $credentialsPath);
            }
            
            $this->client->setAuthConfig($credentialsPath);
        }
        
        return $this->client;
    }

    /**
     * Get Google Sheets Service instance
     */
    protected function getSheetsService(): Sheets
    {
        if ($this->sheetsService === null) {
            $this->sheetsService = new Sheets($this->getClient());
        }
        
        return $this->sheetsService;
    }

    /**
     * Extract Spreadsheet ID from URL
     */
    public function extractSpreadsheetId(string $url): ?string
    {
        // Match patterns like:
        // https://docs.google.com/spreadsheets/d/SPREADSHEET_ID/edit
        // https://docs.google.com/spreadsheets/d/SPREADSHEET_ID/
        if (preg_match('/\/spreadsheets\/d\/([a-zA-Z0-9-_]+)/', $url, $matches)) {
            return $matches[1];
        }
        
        return null;
    }

    /**
     * Get the first sheet name from spreadsheet
     */
    public function getFirstSheetName(string $spreadsheetId): string
    {
        try {
            $spreadsheet = $this->getSheetsService()->spreadsheets->get($spreadsheetId);
            $sheets = $spreadsheet->getSheets();
            
            if (!empty($sheets)) {
                return $sheets[0]->getProperties()->getTitle();
            }
            
            return 'Sheet1'; // fallback
        } catch (\Exception $e) {
            Log::error('Failed to get sheet name: ' . $e->getMessage());
            return 'Sheet1'; // fallback
        }
    }

    /**
     * Read data from spreadsheet
     */
    public function readSpreadsheet(string $spreadsheetId, string $range = null): array
    {
        try {
            // If no range specified, use first sheet
            if ($range === null) {
                $range = $this->getFirstSheetName($spreadsheetId);
            }
            
            $response = $this->getSheetsService()->spreadsheets_values->get($spreadsheetId, $range);
            return $response->getValues() ?? [];
        } catch (\Exception $e) {
            Log::error('Failed to read spreadsheet: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Write data to spreadsheet
     */
    public function writeSpreadsheet(string $spreadsheetId, string $range, array $values): int
    {
        try {
            $body = new ValueRange([
                'values' => $values
            ]);
            
            $params = [
                'valueInputOption' => 'USER_ENTERED'
            ];
            
            $result = $this->getSheetsService()->spreadsheets_values->update(
                $spreadsheetId,
                $range,
                $body,
                $params
            );
            
            return $result->getUpdatedCells();
        } catch (\Exception $e) {
            Log::error('Failed to write to spreadsheet: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Clear and write data to spreadsheet
     */
    public function clearAndWriteSpreadsheet(string $spreadsheetId, string $sheetName, array $values): int
    {
        try {
            // Calculate range based on data size
            $rowCount = count($values);
            $colCount = count($values[0] ?? []);
            $endCol = $this->numberToColumnLetter($colCount);
            $range = "{$sheetName}!A1:{$endCol}{$rowCount}";
            
            // Clear the entire sheet first
            $clearRange = "{$sheetName}";
            $this->getSheetsService()->spreadsheets_values->clear(
                $spreadsheetId,
                $clearRange,
                new \Google\Service\Sheets\ClearValuesRequest()
            );
            
            // Write new data
            return $this->writeSpreadsheet($spreadsheetId, $range, $values);
        } catch (\Exception $e) {
            Log::error('Failed to clear and write spreadsheet: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Export roster data to spreadsheet format
     * Format mengikuti template "Jadwal Dinas Airnav.xlsx":
     * Row 1: Unit kerja header
     * Row 2: "BULAN : JANUARI 2026"
     * Row 3: Header kolom (NO, NAMA, KELAS, JABATAN, 1-31, KETERANGAN)
     * Row 4+: Data karyawan
     */
    public function exportRosterToSpreadsheet(RosterPeriod $rosterPeriod): array
    {
        $rosterPeriod->load(['rosterDays.shiftAssignments.employee.user', 'rosterDays.shiftAssignments.shift']);
        
        // Get all days in the month
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $rosterPeriod->month, $rosterPeriod->year);
        
        // Month names in Indonesian
        $monthNames = [
            1 => 'JANUARI', 2 => 'FEBRUARI', 3 => 'MARET', 4 => 'APRIL',
            5 => 'MEI', 6 => 'JUNI', 7 => 'JULI', 8 => 'AGUSTUS',
            9 => 'SEPTEMBER', 10 => 'OKTOBER', 11 => 'NOVEMBER', 12 => 'DESEMBER'
        ];
        
        $rows = [];
        
        // Row 1: Unit header
        $row1 = ['JADWAL DINAS PERSONEL'];
        // Fill empty cells to match column count
        for ($i = 1; $i < (4 + $daysInMonth + 1); $i++) {
            $row1[] = '';
        }
        $rows[] = $row1;
        
        // Row 2: Month and Year
        $monthName = $monthNames[$rosterPeriod->month] ?? 'UNKNOWN';
        $row2 = ["BULAN : {$monthName} {$rosterPeriod->year}"];
        for ($i = 1; $i < (4 + $daysInMonth + 1); $i++) {
            $row2[] = '';
        }
        $rows[] = $row2;
        
        // Row 3: Header row (NO, NAMA, KELAS, JABATAN, 1-31, KETERANGAN)
        $headerRow = ['NO', 'NAMA', 'KELAS', 'JABATAN'];
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $headerRow[] = (string) $day;
        }
        $headerRow[] = 'KETERANGAN';
        $rows[] = $headerRow;
        
        // Get all unique employees with assignments
        $employeeAssignments = [];
        
        foreach ($rosterPeriod->rosterDays as $rosterDay) {
            $dayNum = (int) date('j', strtotime($rosterDay->work_date));
            
            foreach ($rosterDay->shiftAssignments as $assignment) {
                $employeeId = $assignment->employee_id;
                $employee = $assignment->employee;
                $user = $employee->user ?? null;
                
                $employeeName = $user->name ?? 'Unknown';
                $shiftCode = $this->getShiftCode($assignment->shift->name ?? '');
                
                if (!isset($employeeAssignments[$employeeId])) {
                    // Get employee grade as kelas
                    $kelas = $user->grade ?? '';
                    
                    // Get employee type/role as jabatan
                    $jabatan = $employee->employee_type ?? '';
                    
                    $employeeAssignments[$employeeId] = [
                        'name' => $employeeName,
                        'kelas' => $kelas,
                        'jabatan' => $jabatan,
                        'schedule' => array_fill(1, $daysInMonth, ''),
                        'keterangan' => '',
                    ];
                }
                
                $employeeAssignments[$employeeId]['schedule'][$dayNum] = $shiftCode;
            }
        }
        
        // Sort employees by name
        uasort($employeeAssignments, function($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        
        // Build data rows (Row 4+)
        $rowNum = 1;
        foreach ($employeeAssignments as $employeeId => $data) {
            $row = [
                $rowNum,                    // NO
                $data['name'],              // NAMA
                $data['kelas'],             // KELAS
                $data['jabatan'],           // JABATAN
            ];
            
            // Add schedule for each day
            for ($day = 1; $day <= $daysInMonth; $day++) {
                $row[] = $data['schedule'][$day] ?? '';
            }
            
            $row[] = $data['keterangan'];   // KETERANGAN
            
            $rows[] = $row;
            $rowNum++;
        }
        
        // Add shift legend at the bottom
        $rows[] = []; // Empty row
        $rows[] = ['KETERANGAN SHIFT:'];
        $rows[] = ['P :: Pagi', '', 'S :: Siang', '', 'M :: Malam'];
        $rows[] = ['L :: Libur', '', 'C :: Cuti', '', 'OFF :: Day Off'];
        $rows[] = ['SK :: Sakit', '', 'I :: Izin'];
        
        return $rows;
    }

    /**
     * Sync roster data back to Google Spreadsheet
     */
    public function syncRosterToSpreadsheet(RosterPeriod $rosterPeriod): array
    {
        if (empty($rosterPeriod->spreadsheet_url)) {
            throw new \Exception('Roster is not linked to any spreadsheet');
        }
        
        $spreadsheetId = $this->extractSpreadsheetId($rosterPeriod->spreadsheet_url);
        
        if (!$spreadsheetId) {
            throw new \Exception('Invalid spreadsheet URL');
        }
        
        // Get first sheet name
        $sheetName = $this->getFirstSheetName($spreadsheetId);
        
        // Export roster data to spreadsheet format
        $data = $this->exportRosterToSpreadsheet($rosterPeriod);
        
        // Write to spreadsheet
        $updatedCells = $this->clearAndWriteSpreadsheet($spreadsheetId, $sheetName, $data);
        
        $rowCount = count($data);
        $colCount = count($data[0] ?? []);
        
        return [
            'success' => true,
            'updated_cells' => $updatedCells,
            'rows' => $rowCount,
            'columns' => $colCount,
            'sheet_name' => $sheetName
        ];
    }

    /**
     * Convert shift name to code
     */
    protected function getShiftCode(string $shiftName): string
    {
        $codes = [
            'Pagi' => 'P',
            'Siang' => 'S',
            'Malam' => 'M',
            'Libur' => 'L',
            'Off' => 'OFF',
            'Cuti' => 'C',
            'Sakit' => 'SK',
            'Izin' => 'I',
        ];
        
        return $codes[$shiftName] ?? $shiftName;
    }

    /**
     * Convert column number to letter (1 = A, 2 = B, ..., 27 = AA)
     */
    protected function numberToColumnLetter(int $number): string
    {
        $letter = '';
        while ($number > 0) {
            $number--;
            $letter = chr(65 + ($number % 26)) . $letter;
            $number = intval($number / 26);
        }
        return $letter;
    }
}
