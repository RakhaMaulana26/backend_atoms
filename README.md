# ATOMS - Air Traffic Operational Management System

Backend API untuk sistem manajemen roster dan shift karyawan CNS/Support.

---

## 🚀 Quick Start

### 1. Installation
```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed
```

### 2. Run Development Server
```bash
php artisan serve
```

API akan berjalan di: `http://localhost:8000`

---

## 📚 Dokumentasi API

### Interactive Documentation (Swagger UI) ⭐
Buka browser: **http://localhost:8000/api-docs.html**

Fitur:
- ✅ Try endpoints secara interaktif
- ✅ Lihat contoh request/response
- ✅ Test dengan token authentication
- ✅ Export to Postman/cURL

### Dokumentasi Lengkap
- **[API_DOCUMENTATION.md](./API_DOCUMENTATION.md)** - Complete API reference
- **[ASSIGNMENT_WORKFLOW_GUIDE.md](./ASSIGNMENT_WORKFLOW_GUIDE.md)** - ⭐ Cara assign karyawan
- **[QUICK_REFERENCE.md](./QUICK_REFERENCE.md)** - Quick reference card
- **[RINGKASAN_PERBAIKAN.md](./RINGKASAN_PERBAIKAN.md)** - 🇮🇩 Panduan Bahasa Indonesia

---

## 🎯 Fitur Utama

### Roster Management
- ✅ Buat roster bulanan (auto-generate 31 hari)
- ✅ Assign karyawan ke shift (satu per satu atau batch)
- ✅ Assign manager teknik per hari
- ✅ Validasi otomatis (4 CNS + 2 Support per shift)
- ✅ Publish roster dengan enforce validation

### User Management
- ✅ Role-based access control (Admin, Manager, Employee)
- ✅ Authentication dengan Laravel Sanctum
- ✅ CRUD employees

### Shift Requests
- ✅ Karyawan request shift off
- ✅ Approval workflow untuk manager
- ✅ Email notifications

---

## 🔧 Tech Stack

- **Framework:** Laravel 10.x
- **Database:** PostgreSQL
- **Authentication:** Laravel Sanctum
- **API Documentation:** OpenAPI 3.0 / Swagger UI
- **Mail:** Laravel Mail

---

## 📋 Requirement per Hari

### Shift Requirements (3 shifts per day):
- **Shift 1 (Pagi):** 4 CNS + 2 Support = 6 orang
- **Shift 2 (Malam):** 4 CNS + 2 Support = 6 orang  
- **Shift 3 (Full Day):** 4 CNS + 2 Support = 6 orang

### Manager Requirement:
- **Manager Teknik:** 1 orang per hari

---

## 🎓 Cara Assign Karyawan

### Method 1: POST - Add One by One (Recommended)
Menambah karyawan **tanpa menghapus** yang sudah ada.

```bash
# Tambah 1 karyawan
POST /api/rosters/1/days/1/assignments
{
  "shift_assignments": [
    {"employee_id": 1, "shift_id": 1}
  ]
}

# Tambah karyawan lagi (yang sebelumnya tidak hilang)
POST /api/rosters/1/days/1/assignments
{
  "shift_assignments": [
    {"employee_id": 2, "shift_id": 1}
  ]
}
```

### Method 2: PUT - Replace All (Hati-hati!)
**Mengganti semua** assignment (hapus dulu, buat baru).

```bash
PUT /api/rosters/1/days/1/assignments
{
  "shift_assignments": [...],  // Semua yang baru
  "manager_duties": [...]
}
```

**⚠️ Warning:** PUT akan menghapus semua assignment yang ada!

**Baca panduan lengkap:** [ASSIGNMENT_WORKFLOW_GUIDE.md](./ASSIGNMENT_WORKFLOW_GUIDE.md)

---

## 🧪 Testing

### 1. Menggunakan Swagger UI (Termudah)
1. Buka: http://localhost:8000/api-docs.html
2. Klik **Authorize** → Masukkan: `Bearer {token}`
3. Try endpoint secara interaktif

### 2. Menggunakan Script
```bash
# Edit token dulu
nano test_assignment_workflow.sh

# Run test
bash test_assignment_workflow.sh
```

---

## 📊 Database Schema

### ERD (Entity Relationship Diagram)
File: **[database_erd.dbml](./database_erd.dbml)**

**View di dbdiagram.io:**
1. Copy isi file `database_erd.dbml`
2. Buka: https://dbdiagram.io/d
3. Paste ke editor
4. Export as PNG/PDF

**Panduan:** [DATABASE_ERD_GUIDE.md](./DATABASE_ERD_GUIDE.md)

---

## 📡 API Endpoints

### Authentication
- `POST /auth/login` - Login
- `POST /auth/logout` - Logout
- `GET /auth/me` - Get current user

### Roster Management
- `POST /rosters` - Create roster (auto-generate days)
- `GET /rosters` - List rosters
- `GET /rosters/{id}` - Get roster details
- `GET /rosters/{id}/validate` - ⭐ Validate before publish
- `POST /rosters/{id}/publish` - Publish roster

### Roster Day Assignments
- `GET /rosters/{roster_id}/days/{day_id}` - Get day details
- `POST /rosters/{roster_id}/days/{day_id}/assignments` - ⭐ Add assignments (incremental)
- `PUT /rosters/{roster_id}/days/{day_id}/assignments` - Replace all assignments
- `DELETE /rosters/{roster_id}/days/{day_id}/assignments/{id}` - Delete assignment

### Shift Requests
- `POST /shift-requests` - Create shift request
- `GET /shift-requests` - List requests
- `PUT /shift-requests/{id}/approve` - Approve request
- `PUT /shift-requests/{id}/reject` - Reject request

**Full documentation:** [API_DOCUMENTATION.md](./API_DOCUMENTATION.md)

---

## 🔐 Authentication

Semua endpoint (kecuali login) memerlukan Bearer token:

```bash
Authorization: Bearer {access_token}
```

**Cara dapat token:**
```bash
POST /api/auth/login
{
  "email": "admin@example.com",
  "password": "password123"
}
```

---

## 🆕 Update Terbaru (22 Januari 2026)

### Fixed:
- ✅ Database error: column 'duty_type' tidak ada
- ✅ Migration berhasil dijalankan

### Enhanced:
- ✅ Dokumentasi assignment (POST vs PUT)
- ✅ Swagger UI dengan 3 contoh request
- ✅ Workflow guide lengkap
- ✅ Quick reference card

**Detail lengkap:** [UPDATE_SUMMARY_2026_01_22.md](./UPDATE_SUMMARY_2026_01_22.md)

---

## 📁 File Structure

```
backend_atoms/
├── app/
│   ├── Http/Controllers/Api/
│   │   ├── RosterController.php      # Roster & assignments
│   │   ├── ShiftRequestController.php
│   │   └── ...
│   ├── Models/
│   │   ├── RosterPeriod.php
│   │   ├── RosterDay.php
│   │   ├── ShiftAssignment.php
│   │   └── ...
├── database/
│   ├── migrations/                    # Database migrations
│   └── seeders/                       # Test data
├── routes/
│   └── api.php                        # API routes
├── public/
│   ├── api-docs.html                  # Swagger UI
│   └── swagger.json                   # OpenAPI 3.0 spec
└── [Documentation Files]
    ├── API_DOCUMENTATION.md           # ⭐ Complete API reference
    ├── ASSIGNMENT_WORKFLOW_GUIDE.md   # ⭐ Assignment guide
    ├── QUICK_REFERENCE.md             # Quick reference
    ├── RINGKASAN_PERBAIKAN.md         # 🇮🇩 Indonesian guide
    ├── SWAGGER_QUICK_START.md         # Swagger guide
    ├── database_erd.dbml              # Database ERD
    └── test_assignment_workflow.sh    # Test script
```

---

## 💡 Tips & Best Practices

### ✅ Do:
1. Gunakan **POST** untuk menambah karyawan
2. Validate sebelum publish: `GET /rosters/{id}/validate`
3. Test dengan Swagger UI dulu
4. Baca validation feedback di response

### ❌ Don't:
1. Jangan pakai PUT untuk menambah (akan hapus semua)
2. Jangan publish roster yang belum valid (akan error 422)
3. Jangan lupa authentication token

---

## 📞 Support

**Dokumentasi Lengkap:**
- [ASSIGNMENT_WORKFLOW_GUIDE.md](./ASSIGNMENT_WORKFLOW_GUIDE.md) - ⭐ Must read!
- [API_DOCUMENTATION.md](./API_DOCUMENTATION.md)
- [RINGKASAN_PERBAIKAN.md](./RINGKASAN_PERBAIKAN.md) - 🇮🇩 Bahasa Indonesia

**Testing:**
- Swagger UI: http://localhost:8000/api-docs.html
- Test Script: `bash test_assignment_workflow.sh`

---

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
