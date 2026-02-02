<?php

// Input URL (dari user)
$inputUrl = 'https://docs.google.com/spreadsheets/d/1MJk_RV_ufGHr11bKyMQMxxDlQqv_6GhpZ9rPYSkELy8/edit?usp=sharing';

// Konversi ke export URL
function getGoogleSheetsExportUrl(string $url): ?string
{
    $pattern = '/docs\.google\.com\/spreadsheets\/d\/([a-zA-Z0-9_-]+)/';
    
    if (preg_match($pattern, $url, $matches)) {
        $spreadsheetId = $matches[1];
        
        // Extract sheet gid if present
        $gid = null;
        if (preg_match('/[#&?]gid=(\d+)/', $url, $gidMatches)) {
            $gid = $gidMatches[1];
        }
        
        // Return export URL for XLSX format
        $exportUrl = "https://docs.google.com/spreadsheets/d/{$spreadsheetId}/export?format=xlsx";
        if ($gid !== null) {
            $exportUrl .= "&gid={$gid}";
        }
        return $exportUrl;
    }
    
    return null;
}

$exportUrl = getGoogleSheetsExportUrl($inputUrl);

echo "Input URL: " . $inputUrl . PHP_EOL;
echo "Export URL: " . $exportUrl . PHP_EOL;
echo PHP_EOL;

if (!$exportUrl) {
    die("Failed to parse URL!");
}

$ch = curl_init($exportUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);
curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
curl_setopt($ch, CURLOPT_ENCODING, '');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    'Accept: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet, */*',
]);

$data = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
$finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
curl_close($ch);

echo "HTTP Code: " . $httpCode . PHP_EOL;
echo "Data Length: " . strlen($data) . PHP_EOL;
echo "Error: " . $error . PHP_EOL;
echo "Final URL: " . $finalUrl . PHP_EOL;

// Check if it's HTML
if (str_starts_with(trim($data), '<!DOCTYPE') || str_starts_with(trim($data), '<html')) {
    echo "WARNING: Response is HTML, not Excel file!" . PHP_EOL;
    echo "First 500 chars: " . substr($data, 0, 500) . PHP_EOL;
} else {
    echo "Response appears to be binary (good!)" . PHP_EOL;
    file_put_contents('test_php.xlsx', $data);
    echo "Saved to test_php.xlsx" . PHP_EOL;
}
