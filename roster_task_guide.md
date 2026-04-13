# 📋 Roster Task API - Complete Guide

## 🎯 Konsep Dasar

**Roster Tasks** = Tugas harian yang diberikan manajer/admin ke staff pada shift tertentu.

### Shift Definitions
```
07-13  = Pagi   (07:00 - 13:00)
13-19  = Siang  (13:00 - 19:00)  
19-07  = Malam  (19:00 - 07:00) / Sore
```

### Role Examples
- CNS (Center of Network Services)
- IT
- Security
- Maintenance
- etc.

---

## 🔐 Siapa Bisa Bikin Task?

| Role | Can Create | Can Update Own | Can Update Others |
|------|:----------:|:--------------:|:----------------:|
| Admin | ✅ | ✅ | ✅ |
| Manajer Teknik | ✅ | ✅ | ✅ |
| General Manager | ✅ | ✅ | ✅ |
| Staff/Employee | ❌ | ✅ | ❌ |
| User biasa | ❌ | ❌ | ❌ |

---

## 📡 Endpoints

### 1️⃣ GET /api/roster/tasks - List Tasks

**Purpose:** Ambil daftar roster tasks dengan filter

**Query Parameters:**
```
?date=2026-03-26              // Filter tanggal (YYYY-MM-DD)
&shift_key=07-13              // Filter shift
&role=CNS                     // Filter role/posisi
&status=pending               // Filter status (pending, in_progress, completed)
&priority=high                // Filter prioritas
&assigned_to=5                // Filter: hanya tasks assigned ke user ID 5
&per_page=20                  // Pagination (default 20)
```

**Example Request:**
```bash
curl -X GET "http://localhost:8000/api/roster/tasks?date=2026-03-26&shift_key=07-13" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "date": "2026-03-26",
      "shift_key": "07-13",
      "role": "CNS",
      "assigned_to": [5, 7, 12],
      "title": "Perbaikan AC area B",
      "description": "AC tidak dingin di ruang server",
      "priority": "high",
      "status": "pending",
      "created_at": "2026-03-26T08:00:00Z",
      "updated_at": "2026-03-26T08:00:00Z"
    }
  ],
  "table": {
    "headers": [
      {"key": "id", "label": "ID"},
      {"key": "date", "label": "Tanggal"},
      {"key": "shift_key", "label": "Shift"},
      ...
    ],
    "rows": [...]
  }
}
```

---

### 2️⃣ POST /api/roster/tasks - Create New Task

**Purpose:** Admin/Manajer membuat task baru untuk shift tertentu

**Authorization Required:** ✅ (Manager/Admin only)

**Request Body:**
```json
{
  "date": "2026-03-26",
  "shift_key": "07-13",
  "role": "CNS",
  "assigned_to": [5, 7, 12],
  "title": "Perbaikan AC area B",
  "description": "AC tidak dingin di ruang server building A",
  "priority": "high",
  "status": "pending"
}
```

**Required Fields:**
- `date` - Tanggal task (format: YYYY-MM-DD, tidak boleh kemarin)
- `shift_key` - Shift (mandatory: **07-13** | **13-19** | **19-07**)
- `role` - Role/posisi yang akan kerjain (e.g., "CNS", "IT", "Security")
- `assigned_to` - Array user IDs yang ditugaskan (min 1 orang)
- `title` - Judul tugas (max 255 karakter)
- `priority` - Prioritas (low | medium | **high**)

**Optional Fields:**
- `description` - Deskripsi detail tugas (max 1000 karakter)
- `status` - Status awal (default: "pending", opsi: pending | in_progress | completed | cancelled)

**Response Success (201):**
```json
{
  "data": {
    "id": 1,
    "date": "2026-03-26",
    "shift_key": "07-13",
    "role": "CNS",
    "assigned_to": [5, 7, 12],
    "title": "Perbaikan AC area B",
    "description": "AC tidak dingin di ruang server",
    "priority": "high",
    "status": "pending",
    "created_at": "2026-03-26T10:30:45Z",
    "updated_at": "2026-03-26T10:30:45Z"
  },
  "message": "Task berhasil dibuat",
  "shift_info": {
    "shift_name": "Pagi (07.00-13.00)",
    "assigned_count": 3
  },
  "table": {
    "headers": [...],
    "rows": [...]
  }
}
```

**Response Error (403):**
```json
{
  "message": "Hanya admin dan manajer yang bisa membuat task",
  "error": "UNAUTHORIZED"
}
```

**Response Error (422):**
```json
{
  "message": "Validasi gagal",
  "errors": {
    "shift_key": ["The shift key field must be one of: 07-13, 13-19, 19-07"],
    "assigned_to": ["The assigned to field must have at least 1 item"]
  }
}
```

---

### 3️⃣ PUT /api/roster/tasks/{id} - Update Task Status

**Purpose:** Update status task (untuk staff yang assigned)

**Authorization:** ✅ 
- Task owner (assigned user)
- Managers/Admins

**URL:**
```
PUT /api/roster/tasks/1
```

**Request Body (Minimal - hanya ubah status):**
```json
{
  "status": "in_progress"
}
```

**Request Body (Full - lebih lengkap):**
```json
{
  "date": "2026-03-26",
  "shift_key": "07-13",
  "role": "CNS",
  "title": "Perbaikan AC area B - Updated",
  "description": "AC sudah diperbaiki, tinggal testing",
  "priority": "high",
  "status": "completed"
}
```

**Status Flow (any direction allowed):**
```
pending ↔ in_progress ↔ completed
         ↘        ↗
              ↓ cancelled
```

**Response Success (200):**
```json
{
  "message": "Task berhasil diupdate",
  "data": {
    "id": 1,
    "date": "2026-03-26",
    "shift_key": "07-13",
    "role": "CNS",
    "status": "in_progress",
    "updated_at": "2026-03-26T11:15:30Z"
  }
}
```

**Response Error (403):**
```json
{
  "message": "Anda tidak punya akses untuk update task ini"
}
```

---

## 🧪 CURL Test Commands

### Test 1: List All Tasks for Pagi Shift on 2026-03-26
```bash
curl -X GET \
  "http://localhost:8000/api/roster/tasks?date=2026-03-26&shift_key=07-13" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json"
```

### Test 2: Create Task for Pagi Shift (Manager/Admin)
```bash
curl -X POST http://localhost:8000/api/roster/tasks \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "date": "2026-03-26",
    "shift_key": "07-13",
    "role": "CNS",
    "assigned_to": [5, 7],
    "title": "Perbaikan AC Ruang Server",
    "description": "AC tidak dingin, perlu dicek dan diperbaiki",
    "priority": "high",
    "status": "pending"
  }'
```

### Test 3: Create Task for Siang Shift
```bash
curl -X POST http://localhost:8000/api/roster/tasks \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "date": "2026-03-26",
    "shift_key": "13-19",
    "role": "IT Support",
    "assigned_to": [3, 8, 10],
    "title": "Update antivirus semua workstation",
    "description": "Install latest antivirus patches di semua PC",
    "priority": "medium",
    "status": "pending"
  }'
```

### Test 4: Create Task for Malam Shift
```bash
curl -X POST http://localhost:8000/api/roster/tasks \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "date": "2026-03-27",
    "shift_key": "19-07",
    "role": "Security",
    "assigned_to": [2, 4, 6],
    "title": "Patrol dan pemeriksaan CCTV",
    "description": "Jaga dan monitoring area building, cek semua kamera CCTV",
    "priority": "high",
    "status": "pending"
  }'
```

### Test 5: Staff Update Task Status
```bash
curl -X PUT http://localhost:8000/api/roster/tasks/1 \
  -H "Authorization: Bearer STAFF_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "status": "in_progress"
  }'
```

### Test 6: Staff Mark Task as Done
```bash
curl -X PUT http://localhost:8000/api/roster/tasks/1 \
  -H "Authorization: Bearer STAFF_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "status": "completed"
  }'
```

### Test 7: Get Tasks for Specific User
```bash
curl -X GET \
  "http://localhost:8000/api/roster/tasks?assigned_to=5" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json"
```

### Test 8: Get Tasks with Status Filter
```bash
curl -X GET \
  "http://localhost:8000/api/roster/tasks?status=pending&date=2026-03-26" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json"
```

---

## 🔔 Notification Integration

Ketika manajer membuat task:

1. **Notifikasi dibuat** untuk setiap user di `assigned_to`
2. **Category:** `roster` (bukan `inbox` biasa)
3. **Fields:**
   - Title: `"Task Baru Shift [Nama Shift]"`
   - Message: Include tanggal, task title, dan shift info
   - reference_id: Task ID (untuk link ke detail task)
   - data: JSON dengan task metadata

4. **Frontend akan melihat** di `/notifications/all` atau `/notifications?category=roster`

**Example Notification:**
```json
{
  "id": 123,
  "user_id": 5,
  "sender_id": 1,
  "title": "Task Baru Shift Pagi (07.00-13.00)",
  "message": "Anda mendapat tugas baru: Perbaikan AC area B pada tanggal 2026-03-26 (Pagi (07.00-13.00))",
  "category": "roster",
  "type": "roster",
  "reference_id": 1,
  "data": {
    "task_id": 1,
    "shift_key": "07-13",
    "date": "2026-03-26",
    "role": "CNS"
  },
  "is_read": false
}
```

---

## 📊 Workflow Example - Pagi Shift

```
09:00 AM (Pagi - 07-13):
┌─────────────────────────────────────────────────────────┐
│ Admin/Manager membuat task untuk Shift Pagi             │
│ POST /api/roster/tasks                                  │
│ - date: 2026-03-26                                      │
│ - shift_key: 07-13                                      │
│ - assigned_to: [5, 7, 12]  (3 staff)                   │
│ - title: "Perbaikan AC area B"                         │
│ - priority: high                                        │
└─────────────────────────────────────────────────────────┘
              ↓
         ✅ Task Created
         ✅ Notifikasi dikirim ke 3 staff
         ✅ Shift info: "Pagi (07.00-13.00)"

         ↓

10:00 AM:
┌─────────────────────────────────────────────────────────┐
│ Staff menerima notifikasi di Mobile App/Web             │
│ GET /notifications?category=roster                      │
│ Lihat: "Task Baru Shift Pagi (07.00-13.00)"            │
└─────────────────────────────────────────────────────────┘
         ↓
      Click Detail

         ↓

10:15 AM:
┌─────────────────────────────────────────────────────────┐
│ Staff start mengerjakan, update status                  │
│ PUT /api/roster/tasks/1                                 │
│ - status: in_progress                                   │
└─────────────────────────────────────────────────────────┘

         ↓

12:30 PM:
┌─────────────────────────────────────────────────────────┐
│ Staff selesai, mark as done                             │
│ PUT /api/roster/tasks/1                                 │
│ - status: completed                                     │
└─────────────────────────────────────────────────────────┘
```

---

## 🎬 Scenario: Multi-Shift Daily Setup

Misalkan 26 Mar 2026 ada 3 shift dengan banyak task:

### Pagi Shift (07:00 - 13:00)
```bash
# Task 1: Perbaikan AC
curl -X POST http://localhost/api/roster/tasks \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -d '{
    "date": "2026-03-26",
    "shift_key": "07-13",
    "role": "Maintenance",
    "assigned_to": [5, 7],
    "title": "Perbaikan AC area B",
    "priority": "high"
  }'

# Task 2: Cleaning
curl -X POST http://localhost/api/roster/tasks \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -d '{
    "date": "2026-03-26",
    "shift_key": "07-13",
    "role": "Cleaning",
    "assigned_to": [10, 11, 12],
    "title": "Pembersihan lantai 3",
    "priority": "medium"
  }'
```

### Siang Shift (13:00 - 19:00)
```bash
# Task 3: IT Support
curl -X POST http://localhost/api/roster/tasks \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -d '{
    "date": "2026-03-26",
    "shift_key": "13-19",
    "role": "IT Support",
    "assigned_to": [3, 8],
    "title": "Update antivirus workstation",
    "priority": "medium"
  }'
```

### Malam Shift (19:00 - 07:00)
```bash
# Task 4: Security Patrol
curl -X POST http://localhost/api/roster/tasks \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -d '{
    "date": "2026-03-26",
    "shift_key": "19-07",
    "role": "Security",
    "assigned_to": [2, 4, 6],
    "title": "Patrol dan monitoring CCTV",
    "priority": "high"
  }'
```

---

## ❌ Common Errors & Solutions

| Error | Cause | Solution |
|-------|-------|----------|
| `UNAUTHORIZED` (403) | User bukan manager/admin | Gunakan akun admin/manajer teknik |
| `shift_key must be one of...` | Shift key salah | Gunakan: 07-13, 13-19, atau 19-07 |
| `assigned_to.*... exists` | User ID tidak ada | Cek user ID benar di database |
| `date field must be after_or_equal` | Tanggal kemarin | Gunakan tanggal hari ini atau depan |
| `message` = empty | Title/description kosong | Required fields: date, shift_key, role, assigned_to, title |

---

## 🔗 Related Endpoints

- **GET /notifications/all** - Lihat semua notifikasi termasuk roster
- **GET /notifications?category=roster** - Filter hanya notifikasi roster
- **POST /notifications/{id}/read** - Mark notifikasi sudah dibaca
- **GET /roster/tasks** - List tasks (sesuai scope user)

---

## 📝 Database Schema Reference

```sql
Table: roster_tasks
├── id (INT, Primary Key)
├── date (DATE) - When
├── shift_key (VARCHAR) - Which shift (07-13, 13-19, 19-07)
├── role (VARCHAR) - Role/Position (CNS, IT, Security, etc)
├── assigned_to (JSON) - Array of user IDs [5, 7, 12]
├── title (VARCHAR 255) - Task title
├── description (TEXT) - Detailed description
├── priority (ENUM) - low, medium, high
├── status (ENUM) - pending, in_progress, completed, cancelled
├── created_at (TIMESTAMP)
└── updated_at (TIMESTAMP)
```

---

## 🚀 Development Checklist

- [x] Database migration
- [x] RosterTask Model
- [x] RosterTaskController dengan 3 endpoints
- [x] Validation per field
- [x] Permission checks (manager/admin only create)
- [x] Notification integration
- [x] Shift key validation (07-13, 13-19, 19-07)
- [x] Table format response
- [x] Error handling dengan message lokal

---

**Last Updated:** 2026-03-26  
**Version:** 1.0  
**Status:** ✅ Production Ready
