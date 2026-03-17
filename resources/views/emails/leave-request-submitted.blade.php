<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permohonan Cuti Baru</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f4f4f4;
        }
        .container {
            background-color: #ffffff;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        .header {
            background: linear-gradient(135deg, #454D7C 0%, #222E6A 100%);
            color: white;
            padding: 20px;
            border-radius: 8px 8px 0 0;
            margin: -30px -30px 20px -30px;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
        }
        .content {
            margin: 20px 0;
        }
        .info-box {
            background-color: #f8f9fa;
            border-left: 4px solid #454D7C;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .info-row {
            display: flex;
            padding: 8px 0;
            border-bottom: 1px solid #e0e0e0;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .info-label {
            font-weight: bold;
            color: #454D7C;
            min-width: 150px;
        }
        .info-value {
            color: #333;
            flex: 1;
        }
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
            font-size: 12px;
            color: #666;
            text-align: center;
        }
        .button {
            display: inline-block;
            padding: 12px 24px;
            background-color: #222E6A;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            margin-top: 20px;
            text-align: center;
        }
        .button:hover {
            background-color: #1a2550;
        }
        .alert {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📋 Permohonan Cuti Baru</h1>
        </div>
        
        <div class="content">
            <p>Yth. <strong>{{ $managerName }}</strong>,</p>
            
            <div class="alert">
                <strong>⚠️ Perhatian:</strong> Ada permohonan cuti baru yang memerlukan persetujuan Anda.
            </div>
            
            <div class="info-box">
                <h3 style="margin-top: 0; color: #454D7C;">Detail Permohonan</h3>
                
                <div class="info-row">
                    <span class="info-label">Nama Karyawan:</span>
                    <span class="info-value">{{ $employeeName }}</span>
                </div>
                
                <div class="info-row">
                    <span class="info-label">Jenis Cuti:</span>
                    <span class="info-value">{{ $requestType }}</span>
                </div>
                
                <div class="info-row">
                    <span class="info-label">Tanggal Mulai:</span>
                    <span class="info-value">{{ $startDate }}</span>
                </div>
                
                <div class="info-row">
                    <span class="info-label">Tanggal Selesai:</span>
                    <span class="info-value">{{ $endDate }}</span>
                </div>
                
                <div class="info-row">
                    <span class="info-label">Total Hari:</span>
                    <span class="info-value">{{ $totalDays }} hari</span>
                </div>
                
                @if($reason)
                <div class="info-row">
                    <span class="info-label">Alasan:</span>
                    <span class="info-value">{{ $reason }}</span>
                </div>
                @endif
                
                @if($institution)
                <div class="info-row">
                    <span class="info-label">Institusi:</span>
                    <span class="info-value">{{ $institution }}</span>
                </div>
                @endif
                
                @if($educationType)
                <div class="info-row">
                    <span class="info-label">Jenis Pendidikan:</span>
                    <span class="info-value">{{ $educationType }}</span>
                </div>
                @endif
                
                @if($programCourse)
                <div class="info-row">
                    <span class="info-label">Program/Kursus:</span>
                    <span class="info-value">{{ $programCourse }}</span>
                </div>
                @endif
                
                <div class="info-row">
                    <span class="info-label">Diajukan pada:</span>
                    <span class="info-value">{{ $createdAt }}</span>
                </div>
            </div>
            
            <p>Silakan login ke sistem untuk memeriksa dokumen pendukung dan memberikan persetujuan atau penolakan.</p>
            
            <div style="text-align: center;">
                <a href="{{ config('app.frontend_url') }}/leave-requests/{{ $leaveRequestId }}" class="button">
                    Lihat Detail & Berikan Persetujuan
                </a>
            </div>
        </div>
        
        <div class="footer">
            <p>Email ini dikirim secara otomatis dari AIRNAV Technical Operation Management System.</p>
            <p>Mohon tidak membalas email ini.</p>
            <p>&copy; {{ date('Y') }} AIRNAV. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
