# AIRNAV Backend API

Sistem Rostering & Shift Management untuk AIRNAV menggunakan Laravel 12 + PostgreSQL.

## 🚀 Fitur Utama

✅ **Authentication & Authorization**
- Login dengan email & password
- Token-based activation & reset password
- Role-based access control (Admin, CNS, Support, Manager, GM)

✅ **User & Employee Management**
- CRUD user & employee (Admin only)
- Soft delete dengan audit trail
- Generate activation/reset code

✅ **Rostering System**
- Roster bulanan dengan validasi
- Shift assignment (pagi, siang, malam)
- Manager duty assignment
- Validasi minimum employee per shift

✅ **Shift Request & Approval**
- Request tukar shift antar employee
- Multi-level approval (Target → Manager)
- Notification system

✅ **Audit & Activity Log**
- Track semua aksi user
- Created by, Updated by, Deleted by

## 📋 Tech Stack

- **Framework**: Laravel 12
- **Database**: PostgreSQL
- **Authentication**: Laravel Sanctum
- **API**: REST API

## 🛠️ Setup & Installation

### 1. Install Dependencies

```bash
composer install
```

### 2. Install Laravel Sanctum

```bash
composer require laravel/sanctum
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
```

### 3. Setup Database

Pastikan PostgreSQL sudah running, lalu buat database:

```sql
CREATE DATABASE airnav;
```

Update `.env`:
```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=airnav
DB_USERNAME=postgres
DB_PASSWORD=your_password
```

### 4. Run Migrations & Seeders

```bash
php artisan migrate
php artisan db:seed
```

**Default Admin:**
- Email: `admin@airnav.com`
- Password: `admin123`

### 5. Start Server

```bash
php artisan serve
```

API akan berjalan di: `http://localhost:8000`

## 📡 API Endpoints

### 🔐 Authentication

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/api/auth/login` | ❌ | Login |
| POST | `/api/auth/verify-token` | ❌ | Verify activation/reset token |
| POST | `/api/auth/set-password` | ❌ | Set password dengan token |
| POST | `/api/auth/logout` | ✅ | Logout |

### 👤 Admin - User Management

| Method | Endpoint | Role | Description |
|--------|----------|------|-------------|
| GET | `/api/admin/users` | Admin | List users |
| POST | `/api/admin/users` | Admin | Create user + employee |
| PUT | `/api/admin/users/{id}` | Admin | Update user + employee |
| DELETE | `/api/admin/users/{id}` | Admin | Soft delete user |
| POST | `/api/admin/users/{id}/restore` | Admin | Restore user |
| POST | `/api/admin/users/{id}/generate-token` | Admin | Generate activation/reset token |

### 📅 Rostering

| Method | Endpoint | Role | Description |
|--------|----------|------|-------------|
| POST | `/api/rosters` | Admin, Manager | Create roster bulanan |
| GET | `/api/rosters/{id}` | Admin, Manager | View roster detail |
| POST | `/api/rosters/{id}/publish` | Admin, Manager | Publish roster |

### 🔁 Shift Request

| Method | Endpoint | Role | Description |
|--------|----------|------|-------------|
| POST | `/api/shift-requests` | All | Create shift request |
| POST | `/api/shift-requests/{id}/approve-target` | Target Employee | Approve as target |
| POST | `/api/shift-requests/{id}/approve-manager` | Manager | Approve as manager |
| POST | `/api/shift-requests/{id}/reject` | Target/Manager | Reject request |

### 📬 Notifications

| Method | Endpoint | Role | Description |
|--------|----------|------|-------------|
| GET | `/api/notifications` | All | List notifications |
| POST | `/api/notifications/{id}/read` | All | Mark as read |

## 📝 Request Examples

### Login

```bash
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "admin@airnav.com",
    "password": "admin123"
  }'
```

Response:
```json
{
  "access_token": "1|abc123...",
  "token_type": "Bearer",
  "user": {
    "id": 1,
    "name": "Administrator",
    "email": "admin@airnav.com",
    "role": "admin"
  }
}
```

### Create User + Employee

```bash
curl -X POST http://localhost:8000/api/admin/users \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Andi Pratama",
    "email": "andi@airnav.com",
    "role": "cns",
    "employee_type": "CNS",
    "is_active": true
  }'
```

### Generate Activation Token

```bash
curl -X POST http://localhost:8000/api/admin/users/2/generate-token \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "type": "activation"
  }'
```

Response:
```json
{
  "token": "ABC-XYZ123",
  "expired_at": "2026-01-21 23:59:59"
}
```

### Create Roster

```bash
curl -X POST http://localhost:8000/api/rosters \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "month": 1,
    "year": 2026,
    "days": [
      {
        "date": "2026-01-01",
        "manager_id": 30,
        "shifts": {
          "pagi": [1,2,3,4,5,6],
          "siang": [7,8,9,10,11,12],
          "malam": [13,14,15,16,17,18]
        }
      }
    ]
  }'
```

### Request Shift Swap

```bash
curl -X POST http://localhost:8000/api/shift-requests \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "target_employee_id": 12,
    "from_roster_day_id": 5,
    "to_roster_day_id": 8,
    "shift_id": 2,
    "reason": "Keperluan keluarga"
  }'
```

## 🗄️ Database Schema

### Tables

- **users**: User accounts dengan role
- **account_tokens**: Token aktivasi & reset password
- **employees**: Employee profile (CNS, Support, Manager)
- **shifts**: Master shift (pagi, siang, malam)
- **roster_periods**: Periode roster bulanan
- **roster_days**: Detail hari dalam roster
- **shift_assignments**: Assignment employee ke shift
- **manager_duties**: Assignment manager per hari
- **shift_requests**: Permintaan tukar shift
- **notifications**: Notifikasi user
- **activity_logs**: Audit trail

## 🔒 Security Features

✅ **Authentication**: Token-based (Laravel Sanctum)
✅ **Authorization**: Role-based middleware
✅ **Soft Delete**: Data tidak dihapus permanen
✅ **Audit Trail**: Track created_by, updated_by, deleted_by
✅ **Password Hashing**: Bcrypt
✅ **Token Expiration**: Activation/reset tokens expire dalam 7 hari

## 📊 Validasi Business Rules

✅ **Minimum CNS per shift**: 4 orang
✅ **Minimum Support per shift**: 2 orang
✅ **Minimum Manager per hari**: 1 orang
✅ **Unique roster period**: 1 roster per month/year
✅ **Multi-level approval**: Target → From Manager → To Manager

## 🧪 Testing

```bash
php artisan test
```

## 📦 Production Deployment

```bash
# Set environment
APP_ENV=production
APP_DEBUG=false

# Optimize
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Run migrations
php artisan migrate --force
```

## 📞 Support

Untuk pertanyaan atau issue, silakan hubungi tim development.

---

**Version**: 1.0.0  
**Last Updated**: 14 Januari 2026
