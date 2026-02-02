<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    private array $apiKeys = [];
    // Working models: gemini-2.5-flash-lite, gemma-3-27b-it (tested and have quota)
    private string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-lite:generateContent';

    public function __construct()
    {
        // Load multiple API keys for fallback
        $key1 = config('services.gemini.api_key', env('GEMINI_API_KEY', ''));
        $key2 = env('GEMINI_API_KEY_2', '');
        
        if (!empty($key1)) $this->apiKeys[] = $key1;
        if (!empty($key2)) $this->apiKeys[] = $key2;
        
        Log::info('GeminiService: Loaded ' . count($this->apiKeys) . ' API keys');
    }

    /**
     * Parse roster data from spreadsheet text using Gemini AI
     */
    public function parseRosterData(string $spreadsheetText): ?array
    {
        Log::info('GeminiService: Starting parseRosterData');
        
        if (empty($this->apiKeys)) {
            Log::error('Gemini API keys are not configured');
            return null;
        }

        $prompt = $this->buildPrompt($spreadsheetText);
        Log::info('GeminiService: Prompt built, length: ' . strlen($prompt));

        // Try each API key until one works
        foreach ($this->apiKeys as $index => $apiKey) {
            $keyNumber = $index + 1;
            Log::info("GeminiService: Trying API key #{$keyNumber}");
            
            $result = $this->callGeminiApi($apiKey, $prompt);
            
            if ($result !== null) {
                Log::info("GeminiService: Success with API key #{$keyNumber}");
                return $result;
            }
            
            Log::warning("GeminiService: API key #{$keyNumber} failed, trying next...");
        }
        
        Log::error('GeminiService: All API keys exhausted');
        return null;
    }
    
    /**
     * Call Gemini API with a specific key
     */
    private function callGeminiApi(string $apiKey, string $prompt): ?array
    {
        try {
            Log::info('GeminiService: Sending request to Gemini API');
            
            $response = Http::timeout(120)->post($this->baseUrl . '?key=' . $apiKey, [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 0.1,
                    'maxOutputTokens' => 65536,
                ]
            ]);

            Log::info('GeminiService: Response received', ['status' => $response->status()]);

            // If quota exceeded (429), return null to try next key
            if ($response->status() === 429) {
                Log::warning('GeminiService: Quota exceeded (429), will try next key');
                return null;
            }

            if ($response->successful()) {
                $data = $response->json();
                Log::info('GeminiService: Response data', ['keys' => array_keys($data)]);
                
                // Handle thinking model response - may have modelVersion, usageMetadata etc
                $text = null;
                if (isset($data['candidates'][0]['content']['parts'])) {
                    foreach ($data['candidates'][0]['content']['parts'] as $part) {
                        if (isset($part['text'])) {
                            $text = $part['text'];
                            break;
                        }
                    }
                }
                
                Log::info('GeminiService: Extracted text', ['length' => $text ? strlen($text) : 0]);
                
                if ($text) {
                    // Extract JSON from response (may be wrapped in markdown code blocks)
                    $json = $this->extractJson($text);
                    Log::info('GeminiService: Extracted JSON', ['length' => strlen($json), 'preview' => substr($json, 0, 200)]);
                    
                    $result = json_decode($json, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        Log::error('GeminiService: JSON decode error', ['error' => json_last_error_msg()]);
                        return null;
                    }
                    
                    Log::info('GeminiService: Successfully parsed', ['employees_count' => count($result['employees'] ?? [])]);
                    return $result;
                } else {
                    Log::error('GeminiService: No text in response', ['data' => json_encode($data)]);
                }
            } else {
                Log::error('Gemini API error', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Gemini API exception', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }

        return null;
    }

    /**
     * Build the prompt for Gemini to parse roster data
     */
    private function buildPrompt(string $spreadsheetText): string
    {
        return <<<PROMPT
Kamu adalah parser data roster/jadwal kerja. Analisis data spreadsheet berikut dan konversi ke format JSON.

DATA SPREADSHEET:
{$spreadsheetText}

INSTRUKSI:
1. Temukan informasi BULAN dan TAHUN dari data
2. Temukan semua karyawan dengan jadwal shift mereka
3. Identifikasi kode shift yang digunakan (P=Pagi, S=Siang, M=Malam, L=Libur, dll)

OUTPUT FORMAT (JSON ONLY, tanpa markdown):
{
  "month": 1,
  "year": 2026,
  "shift_codes": {
    "P": "pagi",
    "S": "siang", 
    "M": "malam",
    "L": "libur",
    "CT": "cuti_tahunan",
    "CS": "cuti_sakit",
    "DL": "dinas_luar",
    "OH": "office_hour"
  },
  "employees": [
    {
      "name": "Nama Karyawan",
      "position": "Jabatan",
      "grade": 12,
      "schedule": {
        "1": "P",
        "2": "S",
        "3": "M",
        "4": "L"
      }
    }
  ]
}

PENTING:
- month harus integer 1-12
- year harus integer 4 digit
- schedule key adalah tanggal (1-31), value adalah kode shift
- Jika ada kode shift baru yang tidak dikenal, tambahkan ke shift_codes
- Kembalikan HANYA JSON valid tanpa penjelasan atau markdown code blocks
PROMPT;
    }

    /**
     * Extract JSON from response text (handles markdown code blocks and control characters)
     */
    private function extractJson(string $text): string
    {
        Log::debug('GeminiService: Raw text before extraction', ['length' => strlen($text), 'preview' => substr($text, 0, 300)]);
        
        // Remove markdown code blocks if present
        $text = preg_replace('/```json\s*/i', '', $text);
        $text = preg_replace('/```\s*/', '', $text);
        
        // Remove BOM if present
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        
        // Trim whitespace
        $text = trim($text);
        
        // Try to find JSON object boundaries
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        
        if ($start !== false && $end !== false && $end > $start) {
            $text = substr($text, $start, $end - $start + 1);
        }
        
        // Check if JSON is complete (has employees array)
        if (strpos($text, '"employees"') === false) {
            Log::error('GeminiService: JSON is incomplete - missing employees array');
        }
        
        // Fix common JSON issues from AI responses
        // Remove trailing commas before } or ]
        $text = preg_replace('/,(\s*[}\]])/', '$1', $text);
        
        Log::debug('GeminiService: Cleaned JSON', ['length' => strlen($text), 'preview' => substr($text, 0, 500)]);
        
        return $text;
    }
}
