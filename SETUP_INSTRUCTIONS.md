# 🚀 PANDUAN SETUP LENGKAP AIRNAV BACKEND

## ⚡ Quick Start (3 Langkah)

### 1️⃣ Install Laravel Sanctum

```bash
composer require laravel/sanctum
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
```

### 2️⃣ Setup Database

Buat database PostgreSQL:

```sql
CREATE DATABASE airnav;
```

Atau via psql command line:

```bash
psql -U postgres -c "CREATE DATABASE airnav;"
```

Update password PostgreSQL di `.env`:

```env
DB_PASSWORD=your_postgres_password
```

### 3️⃣ Jalankan Migrasi

```bash
php artisan migrate
php artisan db:seed
```

**✅ SELESAI!** Server siap dijalankan:

```bash
php artisan serve
```

---

## 📝 Detail Setup Step by Step

### Langkah 1: Check Requirements

Pastikan sudah terinstall:
- ✅ PHP 8.2 atau lebih tinggi
- ✅ PostgreSQL 12+ 
- ✅ Composer
- ✅ Extension PHP: pdo_pgsql

Check versi:

```bash
php -v
psql --version
composer -v
```

### Langkah 2: Clone/Download Project

```bash
cd c:\projekflutter\backend_atoms
```

### Langkah 3: Install Composer Dependencies

```bash
composer install
```

Jika belum ada file `.env`, copy dari `.env.example`:

```bash
copy .env.example .env
```

Generate app key:

```bash
php artisan key:generate
```

### Langkah 4: Install Laravel Sanctum

```bash
composer require laravel/sanctum
```

Publish konfigurasi Sanctum:

```bash
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
```

### Langkah 5: Setup PostgreSQL Database

**Windows (psql):**

```powershell
# Login ke PostgreSQL
psql -U postgres

# Buat database
CREATE DATABASE airnav;

# Keluar
\q
```

**Atau via pgAdmin:**
1. Buka pgAdmin
2. Klik kanan Databases → Create → Database
3. Nama: `airnav`
4. Save

### Langkah 6: Konfigurasi .env

Edit file `.env`:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=airnav
DB_USERNAME=postgres
DB_PASSWORD=your_password_here  # ⚠️ GANTI INI!
```

### Langkah 7: Jalankan Migrations

```bash
php artisan migrate
```

Expected output:
```
✓ 2024_01_01_000000_create_users_table
✓ 2026_01_14_000001_create_account_tokens_table
✓ 2026_01_14_000002_create_employees_table
...
```

### Langkah 8: Seed Data Awal

```bash
php artisan db:seed
```

Output:
```
✅ Default shifts created: pagi, siang, malam
✅ Admin user created: admin@airnav.com / admin123
```

### Langkah 9: Start Development Server

```bash
php artisan serve
```

Server akan berjalan di: **http://127.0.0.1:8000**

---

## 🧪 Testing API

### Test Login

Buka Postman/Insomnia/Thunder Client dan test:

**Request:**
```http
POST http://127.0.0.1:8000/api/auth/login
Content-Type: application/json

{
  "email": "admin@airnav.com",
  "password": "admin123"
}
```

**Expected Response:**
```json
{
  "access_token": "1|abcdef123456...",
  "token_type": "Bearer",
  "user": {
    "id": 1,
    "name": "Administrator",
    "email": "admin@airnav.com",
    "role": "admin",
    "is_active": true
  }
}
```

✅ Jika berhasil, simpan `access_token` untuk request selanjutnya.

### Test Create User (Protected Route)

```http
POST http://127.0.0.1:8000/api/admin/users
Authorization: Bearer YOUR_ACCESS_TOKEN
Content-Type: application/json

{
  "name": "Test CNS",
  "email": "cns@test.com",
  "role": "cns",
  "employee_type": "CNS",
  "is_active": true
}
```

---

## 🔧 Troubleshooting

### Error: "could not find driver"

**Solusi:** Install PHP PostgreSQL extension

```bash
# Windows: Enable di php.ini
extension=pdo_pgsql
extension=pgsql
```

Restart web server setelah edit php.ini.

### Error: "SQLSTATE[08006] connection refused"

**Solusi:** 
1. Pastikan PostgreSQL service running
2. Check port 5432 tidak dipakai aplikasi lain
3. Verify username & password di `.env`

```bash
# Check PostgreSQL service (Windows)
Get-Service postgresql*

# Start service jika belum running
Start-Service postgresql-x64-*
```

### Error: "Class 'Laravel\Sanctum\...' not found"

**Solusi:** Install Sanctum

```bash
composer require laravel/sanctum
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
```

### Error: "Base table or view not found"

**Solusi:** Run migrations

```bash
php artisan migrate:fresh --seed
```

⚠️ **Warning:** `migrate:fresh` akan DROP semua table!

### Port 8000 sudah dipakai

**Solusi:** Gunakan port lain

```bash
php artisan serve --port=8080
```

---

## 📦 Database Schema Overview

```
users (11 columns)
├── id, name, email, role, password
├── is_active, created_at, updated_at, deleted_at
└── created_by, updated_by, deleted_by

employees (7 columns)
├── id, user_id, employee_type
└── is_active, timestamps, audit fields

shifts (5 columns)
├── id, name (pagi/siang/malam)
└── timestamps, audit fields

roster_periods (7 columns)
├── id, month, year, status
└── timestamps, audit fields

roster_days (5 columns)
├── id, roster_period_id, work_date
└── timestamps, audit fields

shift_assignments (6 columns)
├── id, roster_day_id, shift_id, employee_id
└── timestamps, audit fields

manager_duties (5 columns)
├── id, roster_day_id, employee_id
└── timestamps, audit fields

shift_requests (14 columns)
├── id, requester_id, target_id
├── from_day_id, to_day_id, shift_id
├── reason, status
├── approved_by_target, approved_by_from_manager, approved_by_to_manager
└── timestamps, audit fields

notifications (7 columns)
├── id, user_id, title, message
├── is_read, created_at, deleted_at

activity_logs (7 columns)
├── id, user_id, action, module
└── reference_id, description, created_at
```

---

## 🎯 Next Steps

Setelah setup berhasil, kamu bisa:

1. ✅ **Baca dokumentasi API lengkap** → [AIRNAV_README.md](AIRNAV_README.md)
2. ✅ **Test semua endpoints** dengan Postman
3. ✅ **Buat user dummy** untuk testing
4. ✅ **Buat roster** untuk bulan ini
5. ✅ **Test shift request flow**

---

## 💡 Tips Development

### Auto-reload dengan Laravel Pail

```bash
php artisan pail
```

### Clear cache saat development

```bash
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

### Check routes yang tersedia

```bash
php artisan route:list --path=api
```

### Generate ERD dari database

```bash
# Install package
composer require beyondcode/laravel-er-diagram-generator --dev

# Generate
php artisan generate:erd output.png
```

---

## 📞 Need Help?

Jika ada error atau pertanyaan, cek:

1. ✅ Log file: `storage/logs/laravel.log`
2. ✅ PostgreSQL log
3. ✅ PHP error log

---

**Setup Guide Version**: 1.0  
**Last Updated**: 14 Januari 2026
