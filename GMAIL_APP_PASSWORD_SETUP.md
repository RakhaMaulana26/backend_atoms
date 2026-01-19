# URGENT: Gmail App Password Required

## ❌ Current Error
```
Application-specific password required
```

**Problem:** You entered your regular Gmail password. Gmail requires a special "App Password" for third-party applications.

## ✅ Solution: Get Gmail App Password

### Step-by-Step Guide:

1. **Enable 2-Step Verification** (if not already enabled)
   - Go to: https://myaccount.google.com/security
   - Find "2-Step Verification" and turn it ON
   - Follow the setup instructions

2. **Generate App Password**
   - Go to: https://myaccount.google.com/apppasswords
   - Or: Google Account → Security → 2-Step Verification → App passwords
   - Sign in if prompted
   - Under "Select app", choose **"Mail"**
   - Under "Select device", choose **"Windows Computer"**
   - Click **"Generate"**
   
3. **Copy the 16-character password**
   - Gmail will show something like: `abcd efgh ijkl mnop`
   - **Important:** Remove all spaces: `abcdefghijklmnop`
   - This is your App Password (NOT your Gmail password)

4. **Update .env file**
   - Open `backend_atoms/.env`
   - Find line: `MAIL_PASSWORD=`
   - Replace with: `MAIL_PASSWORD=abcdefghijklmnop` (your 16-char app password)
   - Save the file

5. **Clear cache and test**
   ```bash
   cd backend_atoms
   php artisan config:clear
   ```

## Example .env Configuration

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=rakhamkp@gmail.com
MAIL_PASSWORD=abcdefghijklmnop    ← YOUR APP PASSWORD HERE (16 chars, no spaces)
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="rakhamkp@gmail.com"
MAIL_FROM_NAME="ATOMS"
```

## Alternative: Use Mailtrap (No Gmail Setup Needed)

If you don't want to setup Gmail, use **Mailtrap** for testing:

1. Sign up free at: https://mailtrap.io
2. Go to: Email Testing → Inboxes → Show Credentials
3. Copy SMTP settings
4. Update `.env`:

```env
MAIL_MAILER=smtp
MAIL_HOST=sandbox.smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=your_mailtrap_username
MAIL_PASSWORD=your_mailtrap_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="atoms@example.com"
MAIL_FROM_NAME="ATOMS"
```

Mailtrap will catch all emails in a fake inbox (perfect for testing).

## Quick Check

After updating password, test with:
```bash
php artisan tinker
Mail::raw('Test', function($m) { $m->to('rakhamkp@gmail.com')->subject('Test'); });
exit
```

Should see: No error = Success! ✅
