# Backend Requirements: Shift-Aware Roster Notifications API

## Gambaran Umum
Frontend memerlukan API endpoints untuk mendukung sistem notifikasi roster yang shift-aware. User dapat melihat, memfilter, dan update status tugas berdasarkan shift (Pagi/Siang/Malam).

---

## 📌 API Endpoints yang Diperlukan

### 1. **GET /api/roster/tasks** - Ambil Daftar Roster Tasks
**Deskripsi:** Mengambil daftar tugas roster dengan support untuk filtering

**Method:** GET

**URL:** `/api/roster/tasks`

**Query Parameters:**
```
- date?: string (YYYY-MM-DD) - Filter by tanggal tugas
- shift?: string (07-13 | 13-19 | 19-07) - Filter by shift
- role?: string (CNS | Support | Manager Teknik) - Filter by role
- assigned_to?: number - Filter by user ID yang di-assign
- status?: string (pending | in_progress | done) - Filter by status
```

**Response Success (200):**
```json
{
  "data": [
    {
      "id": 1,
      "date": "2026-03-26",
      "shift_key": "07-13",
      "role": "CNS",
      "assigned_to": [5, 7, 12],
      "title": "Perbaikan AC Area B",
      "description": "AC di area B tidak dingin, perlu maintenance",
      "priority": "high",
      "status": "pending",
      "created_by": 1,
      "created_at": "2026-03-26T08:00:00Z",
      "updated_at": "2026-03-26T08:00:00Z"
    },
    {
      "id": 2,
      "date": "2026-03-26",
      "shift_key": "13-19",
      "role": "Support",
      "assigned_to": [3, 8],
      "title": "Cleaning Area C & D",
      "description": "Kebersihan area produksi perlu dijaga",
      "priority": "medium",
      "status": "in_progress",
      "created_by": 2,
      "created_at": "2026-03-26T08:30:00Z",
      "updated_at": "2026-03-26T14:00:00Z"
    }
  ],
  "total": 2
}
```

**Error Response (400/500):**
```json
{
  "message": "Failed to fetch roster tasks",
  "error": "error details"
}
```

---

### 2. **PUT /api/roster/tasks/:id** - Update Status Roster Task
**Deskripsi:** Update status tugas roster (pending → in_progress → done)

**Method:** PUT

**URL:** `/api/roster/tasks/{taskId}`

**Request Body:**
```json
{
  "status": "in_progress",
  "title?": "Perbaikan AC Area B (Optional - untuk update title)",
  "description?": "Updated description (Optional)",
  "priority?": "high",
  "assigned_to?": [5, 7, 12]
}
```

**Response Success (200):**
```json
{
  "message": "Task updated successfully",
  "data": {
    "id": 1,
    "date": "2026-03-26",
    "shift_key": "07-13",
    "role": "CNS",
    "assigned_to": [5, 7, 12],
    "title": "Perbaikan AC Area B",
    "description": "AC di area B tidak dingin, perlu maintenance",
    "priority": "high",
    "status": "in_progress",
    "created_by": 1,
    "created_at": "2026-03-26T08:00:00Z",
    "updated_at": "2026-03-26T13:45:00Z"
  }
}
```

**Error Response (404/400/500):**
```json
{
  "message": "Task not found or update failed",
  "error": "error details"
}
```

---

### 3. **POST /api/roster/tasks** - Buat Roster Task Baru
**Deskripsi:** Membuat tugas roster baru

**Method:** POST

**URL:** `/api/roster/tasks`

**Request Body:**
```json
{
  "date": "2026-03-26",
  "shift_key": "07-13",
  "role": "CNS",
  "assigned_to": [5, 7, 12],
  "title": "Perbaikan AC Area B",
  "description": "AC di area B tidak dingin, perlu maintenance",
  "priority": "high"
}
```

**Response Success (201):**
```json
{
  "message": "Task created successfully",
  "data": {
    "id": 1,
    "date": "2026-03-26",
    "shift_key": "07-13",
    "role": "CNS",
    "assigned_to": [5, 7, 12],
    "title": "Perbaikan AC Area B",
    "description": "AC di area B tidak dingin, perlu maintenance",
    "priority": "high",
    "status": "pending",
    "created_by": 1,
    "created_at": "2026-03-26T08:00:00Z",
    "updated_at": "2026-03-26T08:00:00Z"
  }
}
```

---

## 📋 Data Model Requirement

### Roster Task (table: `roster_tasks`)
```sql
CREATE TABLE roster_tasks (
  id INT PRIMARY KEY AUTO_INCREMENT,
  date DATE NOT NULL,
  shift_key ENUM('07-13', '13-19', '19-07') NOT NULL,
  role VARCHAR(50) NOT NULL, -- CNS, Support, Manager Teknik, etc
  assigned_to JSON NOT NULL, -- Array of user IDs: [5, 7, 12]
  title VARCHAR(255) NOT NULL,
  description TEXT,
  priority ENUM('low', 'medium', 'high') DEFAULT 'medium',
  status ENUM('pending', 'in_progress', 'done') DEFAULT 'pending',
  created_by INT NOT NULL REFERENCES users(id),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL,
  
  INDEX idx_date (date),
  INDEX idx_shift_key (shift_key),
  INDEX idx_role (role),
  INDEX idx_status (status),
  INDEX idx_created_by (created_by),
  FULLTEXT INDEX ft_title_description (title, description)
);
```

---

## 🔍 Filtering & Querying Rules

### Shift Values
- `07-13` = Shift Pagi (07:00 - 13:00)
- `13-19` = Shift Siang (13:00 - 19:00)
- `19-07` = Shift Malam (19:00 - 07:00 hari berikutnya)

### Task Visibility
Task ditampilkan ke user jika:
1. User ID ada di dalam `assigned_to` array, ATAU
2. User's `employee_type` matches task's `role`

**Contoh:**
```
Task: {
  id: 1,
  role: "CNS",
  assigned_to: [5, 7]
}

User 5 (role: CNS) → Visible ✓ (ada di assigned_to)
User 7 (role: Support) → Visible ✓ (ada di assigned_to)
User 10 (role: CNS) → Visible ✓ (role matches)
User 15 (role: Support) → Not visible ✗
```

---

## ✅ Status Flow

Tasks dapat transition antar status:
```
pending → in_progress → done
         ↑             ↓
         └─────────────┘ (bisa kembali)

pending dapat langsung → done (skip in_progress)
done dapat kembali ke pending/in_progress jika perlu
```

---

## 🔐 Authorization & Permissions

### GET /api/roster/tasks
- **Authorized:** Semua authenticated users
- **Filter:** Backend harus otomatis filter berdasarkan:
  - User's assigned tasks
  - User's role-based tasks
  - **Jangan tampilkan** tasks untuk role/user lain kecuali user adalah manager

### PUT /api/roster/tasks/:id
- **Authorized:** 
  - User yang di-assign ke task
  - Managers (Manager Teknik, General Manager)
- **Validation:** 
  - Task date harus >= hari ini (tidak bisa edit task di masa lalu lebih dari X hari)
  - Status transition harus valid

### POST /api/roster/tasks
- **Authorized:** Managers only (Manager Teknik, General Manager)

---

## 📝 Example Flow

### 1. User membuka Notifications → Roster Category
```
Frontend: GET /api/roster/tasks (user context: user_id=5, employee_type='CNS')
Backend: SELECT * FROM roster_tasks WHERE 
  (assigned_to LIKE '%5%' OR role='CNS') 
  AND deleted_at IS NULL
  ORDER BY date DESC
Response: Array of tasks user dapat akses
```

### 2. User filter by shift & date
```
Frontend: GET /api/roster/tasks?shift=07-13&date=2026-03-26
Backend: Filter yang sama + shift_key='07-13' AND date='2026-03-26'
Response: Tasks untuk shift pagi tanggal itu saja
```

### 3. User klik "Done" button pada task
```
Frontend: PUT /api/roster/tasks/1 
  Body: { status: 'done' }
Backend: 
  1. Cek apakah user authorized (assigned ke task atau manager)
  2. Update status ke 'done'
  3. Trigger optional: notification email ke creator? Log to activity log?
  4. Return updated task
Response: Task dengan status updated
```

---

## 🚀 Optional Enhancements

### 1. Task Completion Webhook
Trigger POST ke frontend atau log service saat task selesai:
```json
{
  "event": "task_completed",
  "task_id": 1,
  "completed_by": 5,
  "completed_at": "2026-03-26T13:45:00Z"
}
```

### 2. Task Metrics API
```
GET /api/roster/tasks/stats
- Total tasks per shift
- Completed tasks percentage
- Overdue tasks
- By role breakdown
```

### 3. Task History/Audit Log
Track status changes:
```sql
CREATE TABLE roster_task_history (
  id INT PRIMARY KEY AUTO_INCREMENT,
  task_id INT,
  old_status VARCHAR(50),
  new_status VARCHAR(50),
  changed_by INT,
  changed_at TIMESTAMP,
  FOREIGN KEY (task_id) REFERENCES roster_tasks(id),
  FOREIGN KEY (changed_by) REFERENCES users(id)
);
```

---

## 📌 Notes

1. **JSON Array Storage:** `assigned_to` disimpan sebagai JSON array, ensure backend handle dengan baik
2. **Timezone:** Gunakan UTC untuk semua timestamps, frontend akan handle local timezone
3. **Soft Delete:** Gunakan `deleted_at` untuk soft delete, jangan hard delete
4. **Caching:** Consider cache GET requests per user per shift untuk performance
5. **Real-time:** Optional: Gunakan WebSocket/SSE untuk push task updates ke frontend real-time

---

## 🔗 Integrasi dengan Frontend

Frontend sudah siap dengan:
- ✅ Shift tabs UI (Pagi, Siang, Malam)
- ✅ Date filtering
- ✅ Status badges (pending/in_progress/done)
- ✅ Quick action buttons (Start, Done)
- ✅ Optimistic updates
- ✅ Error handling & toast notifications
- ✅ LocalStorage fallback

Backend tinggal sediakan endpoints di atas dan integrase sesuai dengan sistem existing!
