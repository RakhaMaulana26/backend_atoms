<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
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
        .message {
            background-color: #f8f9fa;
            border-left: 4px solid #454D7C;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
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
        }
        .button:hover {
            background-color: #1a2550;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔔 AIRNAV Notification</h1>
        </div>
        
        <div class="content">
            <p>Hello <strong>{{ $userName }}</strong>,</p>
            
            <p>You have received a new notification:</p>
            
            <div class="message">
                <h2 style="margin-top: 0; color: #454D7C;">{{ $title }}</h2>
                <p style="margin: 0;">{{ $message }}</p>
            </div>
            
            <p style="color: #666; font-size: 14px;">
                <em>Received on {{ $createdAt }}</em>
            </p>
            
            <a href="{{ config('app.frontend_url') }}/notifications" class="button">
                View in Application
            </a>
        </div>
        
        <div class="footer">
            <p>This is an automated message from AIRNAV Technical Operation Management System.</p>
            <p>Please do not reply to this email.</p>
            <p>&copy; {{ date('Y') }} AIRNAV. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
