<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset Code</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            background-color: #ffffff;
            border-radius: 8px;
            padding: 40px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .logo {
            font-size: 32px;
            font-weight: bold;
            color: #2C3558;
            margin-bottom: 10px;
        }
        .subtitle {
            color: #666;
            font-size: 14px;
        }
        .content {
            margin: 30px 0;
        }
        .code-box {
            background-color: #f8f9fa;
            border: 2px solid #222E6A;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            margin: 30px 0;
        }
        .code {
            font-size: 36px;
            font-weight: bold;
            letter-spacing: 8px;
            color: #222E6A;
            font-family: 'Courier New', monospace;
        }
        .code-label {
            font-size: 12px;
            color: #666;
            margin-bottom: 10px;
            text-transform: uppercase;
        }
        .warning {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 20px 0;
            font-size: 14px;
        }
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            text-align: center;
            font-size: 12px;
            color: #666;
        }
        .button {
            display: inline-block;
            padding: 12px 30px;
            background-color: #222E6A;
            color: #ffffff;
            text-decoration: none;
            border-radius: 6px;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">ATOMS</div>
            <div class="subtitle">AirNav Technical Operation Management System</div>
        </div>

        <div class="content">
            <h2 style="color: #2C3558;">Password Reset Request</h2>
            
            <p>Hello <strong>{{ $user->name }}</strong>,</p>
            
            <p>We received a request to reset your password for your ATOMS account. Use the code below to reset your password:</p>

            <div class="code-box">
                <div class="code-label">Your Password Reset Code</div>
                <div class="code">{{ $code }}</div>
            </div>

            <p>Enter this code on the password reset page to create a new password.</p>

            <div class="warning">
                <strong>⚠️ Important:</strong> This code will expire in 24 hours. If you didn't request a password reset, please ignore this email or contact your system administrator.
            </div>

            <p>For security reasons:</p>
            <ul>
                <li>Never share this code with anyone</li>
                <li>The code can only be used once</li>
                <li>If you didn't request this reset, your account may be at risk</li>
            </ul>
        </div>

        <div class="footer">
            <p>This is an automated message from ATOMS. Please do not reply to this email.</p>
            <p>&copy; {{ date('Y') }} AirNav Indonesia. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
