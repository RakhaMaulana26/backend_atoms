<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $purpose === 'reset_password' ? 'Password Reset Code' : 'Account Activation Code' }}</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background-color: #f5f5f5;
        }
        .email-container {
            max-width: 600px;
            margin: 40px auto;
            background-color: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        .header {
            background: linear-gradient(to right, #454D7C, #222E6A);
            color: white;
            padding: 30px 40px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 600;
        }
        .content {
            padding: 40px;
        }
        .greeting {
            font-size: 16px;
            color: #333;
            margin-bottom: 20px;
        }
        .message {
            font-size: 15px;
            color: #555;
            line-height: 1.6;
            margin-bottom: 30px;
        }
        .code-container {
            background-color: #f8f9fa;
            border: 2px solid #454D7C;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            margin: 30px 0;
        }
        .code-label {
            font-size: 14px;
            color: #666;
            margin-bottom: 10px;
        }
        .code {
            font-size: 32px;
            font-weight: bold;
            color: #222E6A;
            letter-spacing: 4px;
            font-family: 'Courier New', monospace;
        }
        .expiry-info {
            background-color: #fff8e1;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 20px 0;
            font-size: 14px;
            color: #666;
        }
        .button {
            display: inline-block;
            padding: 14px 32px;
            background-color: #222E6A;
            color: white !important;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 500;
            margin-top: 20px;
            transition: background-color 0.2s;
        }
        .button:hover {
            background-color: #1a2550;
        }
        .footer {
            background-color: #D8DAED;
            padding: 20px 40px;
            text-align: center;
            font-size: 13px;
            color: #666;
        }
        .instructions {
            background-color: #f8f9fa;
            border-radius: 6px;
            padding: 20px;
            margin: 20px 0;
        }
        .instructions h3 {
            margin: 0 0 15px 0;
            color: #333;
            font-size: 16px;
        }
        .instructions ol {
            margin: 0;
            padding-left: 20px;
            color: #555;
        }
        .instructions li {
            margin-bottom: 8px;
            line-height: 1.5;
        }
        @media only screen and (max-width: 600px) {
            .email-container {
                margin: 20px;
            }
            .header, .content, .footer {
                padding: 20px;
            }
            .code {
                font-size: 24px;
                letter-spacing: 2px;
            }
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="header">
            <h1>{{ $purpose === 'reset_password' ? '🔐 Password Reset Code' : '🎉 Welcome to AIRNAV!' }}</h1>
        </div>
        
        <div class="content">
            <div class="greeting">
                Hello {{ $userName }},
            </div>
            
            <div class="message">
                @if($purpose === 'reset_password')
                    We received a request to reset your password. Use the code below to set your new password.
                @else
                    Welcome to AIRNAV System! Your account has been created successfully. Use the code below to activate your account and set your password.
                @endif
            </div>

            <div class="code-container">
                <div class="code-label">Your {{ $purpose === 'reset_password' ? 'Reset' : 'Activation' }} Code:</div>
                <div class="code">{{ $token }}</div>
            </div>

            <div class="expiry-info">
                ⏰ <strong>Important:</strong> This code will expire on <strong>{{ $expiredAt }}</strong>. Please use it before it expires.
            </div>

            <div class="instructions">
                <h3>How to use this code:</h3>
                <ol>
                    <li>Go to the AIRNAV application</li>
                    @if($purpose === 'reset_password')
                        <li>Navigate to the password reset page</li>
                        <li>Enter this code along with your new password</li>
                    @else
                        <li>Navigate to the account activation page</li>
                        <li>Enter this code to activate your account</li>
                        <li>Set your password and complete the setup</li>
                    @endif
                    <li>You'll be able to log in with your credentials</li>
                </ol>
            </div>

            <div style="text-align: center;">
                <a href="{{ $appUrl }}" class="button">Open AIRNAV Application</a>
            </div>

            <div class="message" style="margin-top: 30px; font-size: 14px; color: #888;">
                @if($purpose === 'reset_password')
                    If you didn't request a password reset, please ignore this email or contact support if you have concerns.
                @else
                    If you received this email but didn't expect it, please contact your administrator.
                @endif
            </div>
        </div>
        
        <div class="footer">
            <p style="margin: 0;">© {{ date('Y') }} AIRNAV System. All rights reserved.</p>
            <p style="margin: 10px 0 0 0;">This is an automated message, please do not reply to this email.</p>
        </div>
    </div>
</body>
</html>
