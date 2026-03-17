<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Status Permohonan Cuti</title>
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
        .status-approved {
            background-color: #d4edda;
            border-left: 4px solid #28a745;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .status-rejected {
            background-color: #f8d7da;
            border-left: 4px solid #dc3545;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .badge-approved {
            display: inline-block;
            padding: 5px 15px;
            background-color: #28a745;
            color: white;
            border-radius: 20px;
            font-weight: bold;
            font-size: 14px;
        }
        .badge-rejected {
            display: inline-block;
            padding: 5px 15px;
            background-color: #dc3545;
            color: white;
            border-radius: 20px;
            font-weight: bold;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📋 Status Permohonan Cuti</h1>
        </div>
        
        <div class="content">
            <p>Yth. <strong>{{ $employeeName }}</strong>,</p>
            
            @if($status === 'approved')
            <div class="status-approved">
                <h3 style="margin-top: 0; color: #155724;">
                    ✅ Permohonan Cuti Anda <span class="badge-approved">DISETUJUI</span>
                </h3>
                <p style="margin: 0;">Selamat! Permohonan cuti Anda telah disetujui oleh manager.</p>
            </div>
            @else
            <div class="status-rejected">
                <h3 style="margin-top: 0; color: #721c24;">
                    ❌ Permohonan Cuti Anda <span class="badge-rejected">DITOLAK</span>
                </h3>
                <p style="margin: 0;">Mohon maaf, permohonan cuti Anda tidak dapat disetujui.</p>
            </div>
            @endif
            
            <div class="info-box">
                <h3 style="margin-top: 0; color: #454D7C;">Detail Permohonan</h3>
                
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
                
                <div class="info-row">
                    <span class="info-label">Status:</span>
                    <span class="info-value">
                        @if($status === 'approved')
                            <span class="badge-approved">{{ $statusName }}</span>
                        @else
                            <span class="badge-rejected">{{ $statusName }}</span>
                        @endif
                    </span>
                </div>
                
                @if($approvedBy)
                <div class="info-row">
                    <span class="info-label">Disetujui oleh:</span>
                    <span class="info-value">{{ $approvedBy }}</span>
                </div>
                @endif
                
                @if($approvedAt)
                <div class="info-row">
                    <span class="info-label">Tanggal Keputusan:</span>
                    <span class="info-value">{{ $approvedAt }}</span>
                </div>
                @endif
                
                @if($approvalNotes)
                <div class="info-row">
                    <span class="info-label">Catatan Manager:</span>
                    <span class="info-value">{{ $approvalNotes }}</span>
                </div>
                @endif
            </div>
            
            @if($status === 'approved')
            <p>Pastikan Anda menyelesaikan semua tugas dan tanggung jawab sebelum tanggal cuti dimulai. Selamat menikmati waktu cuti Anda! 🎉</p>
            @else
            <p>Jika Anda memiliki pertanyaan lebih lanjut mengenai penolakan ini, silakan hubungi manager Anda.</p>
            @endif
            
            <div style="text-align: center;">
                <a href="{{ config('app.frontend_url') }}/leave-requests/{{ $leaveRequestId }}" class="button">
                    Lihat Detail Lengkap
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
