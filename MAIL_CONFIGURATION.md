# Mail Configuration for ATOMS

## Problem
Email tidak terkirim karena `MAIL_MAILER=log` hanya mencatat email ke log file, tidak mengirim sungguhan.

## Solution: Gmail SMTP Setup

### Step 1: Enable Gmail App Password
1. Login ke Gmail account Anda
2. Buka https://myaccount.google.com/security
3. Enable **2-Step Verification** (jika belum aktif)
4. Setelah 2FA aktif, buka **App passwords**: https://myaccount.google.com/apppasswords
5. Pilih "Mail" dan device "Windows Computer"
6. Copy 16-character app password (contoh: `abcd efgh ijkl mnop`)

### Step 2: Update .env Configuration

Ganti konfigurasi MAIL di `.env` dengan:

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-email@gmail.com
MAIL_PASSWORD=your-16-char-app-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="your-email@gmail.com"
MAIL_FROM_NAME="ATOMS"
```

**Penting:**
- `MAIL_USERNAME`: Email Gmail Anda
- `MAIL_PASSWORD`: App password (bukan password Gmail biasa!)
- `MAIL_FROM_ADDRESS`: Harus sama dengan MAIL_USERNAME
- Jangan tambahkan spasi di app password

### Step 3: Clear Config Cache

Setelah update .env, jalankan:
```bash
cd backend_atoms
php artisan config:clear
php artisan cache:clear
```

### Step 4: Test Email

Test forgot password dari frontend, atau manual test:
```bash
php artisan tinker
Mail::raw('Test email', function ($message) {
    $message->to('test@example.com')->subject('Test');
});
```

## Alternative: Mailtrap (For Development)

Untuk testing tanpa kirim email sungguhan:

1. Buat account gratis di https://mailtrap.io
2. Copy SMTP credentials
3. Update `.env`:

```env
MAIL_MAILER=smtp
MAIL_HOST=sandbox.smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=your-mailtrap-username
MAIL_PASSWORD=your-mailtrap-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="atoms@example.com"
MAIL_FROM_NAME="ATOMS"
```

Mailtrap akan menangkap semua email untuk testing, tidak terkirim ke email sungguhan.

## Troubleshooting

### Error: "Connection could not be established"
- Cek firewall/antivirus tidak blocking port 587
- Pastikan 2FA dan App Password sudah setup
- Coba ganti `MAIL_PORT=465` dan `MAIL_ENCRYPTION=ssl`

### Error: "Authentication failed"
- Pastikan menggunakan App Password, bukan password Gmail
- App password tidak boleh ada spasi
- Pastikan MAIL_USERNAME adalah email lengkap

### Email masuk ke Spam
- Normal untuk testing, email produksi perlu domain verification
- Setup SPF, DKIM records di domain DNS (untuk production)

## Current Status

Saat ini menggunakan:
- `MAIL_MAILER=log` ← Email hanya dicatat di `storage/logs/laravel.log`
- Perlu diganti ke `smtp` untuk kirim email sungguhan
