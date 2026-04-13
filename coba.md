# 📌 BACKEND DEVELOPER - COPY & PASTE PROMPT

---

## 🎯 TASK: Implement Shift-Aware Roster Tasks REST API

### Context
Frontend sudah jadi dengan sistem Shift-Aware Roster Notifications. User bisa lihat, filter, dan update task status per shift (Pagi/Siang/Malam). Backend perlu sediakan 3 API endpoints buat handle roster tasks.

Backend files: Check `/path/to/project/BACKEND_REQUIREMENTS.md` untuk detail lengkap.

---

## ✅ DELIVERABLES

### 1️⃣ Database Table: `roster_tasks`
```sql
CREATE TABLE roster_tasks (
  id INT PRIMARY KEY AUTO_INCREMENT,
  date DATE NOT NULL,
  shift_key ENUM('07-13', '13-19', '19-07') NOT NULL,
  role VARCHAR(50) NOT NULL,
  assigned_to JSON NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT,
  priority ENUM('low', 'medium', 'high') DEFAULT 'medium',
  status ENUM('pending', 'in_progress', 'done') DEFAULT 'pending',
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL,
  
  INDEX idx_date (date),
  INDEX idx_shift_key (shift_key),
  INDEX idx_role (role),
  INDEX idx_status (status),
  FULLTEXT INDEX ft_search (title, description)
);
```

---

### 2️⃣ Endpoint 1: GET /api/roster/tasks

**Objective:** Fetch roster tasks dengan filtering & access control

**Query Params:**
```
date?: string (YYYY-MM-DD)
shift?: string (07-13|13-19|19-07)
role?: string
assigned_to?: number (user id)
status?: string (pending|in_progress|done)
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
      "title": "Perbaikan AC",
      "description": "AC area B",
      "priority": "high",
      "status": "pending",
      "created_by": 1,
      "created_at": "2026-03-26T08:00:00Z",
      "updated_at": "2026-03-26T08:00:00Z"
    }
  ],
  "total": 1
}
```

**Access Control:**
- Authorized: Semua authenticated users
- Filter otomatis:
  - User dapat akses task jika:
    - User's ID ada di `assigned_to` array, OR
    - User's `employee_type` matches task's `role`
  - Managers dapat akses semua tasks
- Response harus filter berdasarkan user context

**Implementation Notes:**
- Response hanya include tasks yang user authorized untuk akses
- Jangan buat separate endpoint untuk user-specific, just handle di query response
- Sort by date DESC

---

### 3️⃣ Endpoint 2: PUT /api/roster/tasks/:id

**Objective:** Update roster task status

**URL:** `/api/roster/tasks/{taskId}`

**Request Body:**
```json
{
  "status": "in_progress"
}
```

**Response:**
```json
{
  "message": "Task updated successfully",
  "data": {
    "id": 1,
    "date": "2026-03-26",
    "shift_key": "07-13",
    "role": "CNS",
    "assigned_to": [5, 7, 12],
    "title": "Perbaikan AC",
    "description": "AC area B",
    "priority": "high",
    "status": "in_progress",
    "created_by": 1,
    "created_at": "2026-03-26T08:00:00Z",
    "updated_at": "2026-03-26T13:45:00Z"
  }
}
```

**Access Control:**
- Authorized: User yang assigned ke task ATAU Managers
- Validation: Cek apakah user has permissions

**Status Flow:**
- pending ↔ in_progress ↔ done (any direction)
- No state restrictions

---

### 4️⃣ Endpoint 3: POST /api/roster/tasks (OPTIONAL but recommended)

**Objective:** Create new roster task

**Request Body:**
```json
{
  "date": "2026-03-26",
  "shift_key": "07-13",
  "role": "CNS",
  "assigned_to": [5, 7, 12],
  "title": "Perbaikan AC",
  "description": "AC area B tidak dingin",
  "priority": "high"
}
```

**Response:**
```json
{
  "message": "Task created successfully",
  "data": { ...same as GET response }
}
```

**Access Control:**
- Authorized: Managers only

---

## 🔐 Authentication & Authorization

### General Rules
1. All endpoints require Authentication (JWT/Session)
2. GET - Filter by user access
3. PUT - Only assigned users + managers
4. POST - Managers only

### User Context Needed
- `user.id` - untuk check di `assigned_to`
- `user.employee.employee_type` - untuk role-based check
- `user.role` - untuk check if manager

---

## 📝 Implementation Checklist

- [ ] Create `roster_tasks` table
- [ ] Implement GET `/api/roster/tasks` dengan access control
- [ ] Implement PUT `/api/roster/tasks/:id` dengan status update
- [ ] Implement POST `/api/roster/tasks` dengan manager check
- [ ] Add proper error handling & HTTP status codes
- [ ] Add request validation
- [ ] Add permission checks
- [ ] Test with different user roles
- [ ] Test filtering parameters
- [ ] Test access control scenarios
- [ ] Add logging untuk debug
- [ ] Document any deviations from spec

---

## 🧪 Quick Test Cases

1. **GET without auth** → 401 Unauthorized
2. **GET with auth as CNS** → Return only CNS tasks + assigned tasks
3. **GET with auth as Manager** → Return all tasks
4. **PUT status by assigned user** → 200 OK, status updated
5. **PUT status by non-assigned user** → 403 Forbidden
6. **POST by non-manager** → 403 Forbidden
7. **Filter by shift** → Correct tasks only
8. **Filter by date** → Correct tasks only
9. **Empty result** → Return `{ data: [], total: 0 }`
10. **Invalid task ID** → 404 Not Found

---

## 📚 Reference Files (if available)

- Backend project: `/BACKEND_REQUIREMENTS.md` - Full API specifications
- Frontend repo: See `NotificationsPage.tsx` untuk understand request/response expectations
- Shift definitions: Pagi (07-13), Siang (13-19), Malam (19-07)

---

## ⚡ Quick Start

1. Create table migration
2. Create Model: `RosterTask`
3. Create Repository/Service layer
4. Create Controller dengan 3 endpoints
5. Add routes: 
   - GET /api/roster/tasks
   - PUT /api/roster/tasks/:id
   - POST /api/roster/tasks
6. Add middleware: auth, permission check
7. Test dengan REST client (Postman, Hoppscotch)
8. Report any issues/deviations

---

## 🚀 Development Priority

1. **Priority 1 (CRITICAL):** GET endpoint with access control
   - Blocking: Frontend cannot load tasks without this
   
2. **Priority 2 (HIGH):** PUT endpoint for status update
   - Blocking: Frontend cannot mark tasks as done without this
   
3. **Priority 3 (MEDIUM):** POST endpoint for creating tasks
   - Nice-to-have: Can add later if time permits

---

## 💬 Questions to Clarify

- Permission model: Is there approval needed for status changes?
- Audit log: Should track who changed task status & when?
- Notifications: Should notify team when task created/completed?
- Soft delete: Using `deleted_at` instead of hard delete?

---

## 📞 When Done

1. Provide API endpoint URLs & response examples
2. Provide auth mechanism (JWT token format, etc)
3. Provide Base URL (for frontend to know where to call)
4. Note any deviations from this spec
5. Provide test data / sample curl commands

---

**Status:** Ready to implement  
**Estimated Time:** 4-6 hours for all 3 endpoints  
**Tech Stack:** (Your stack here - Node.js/Express, Laravel, etc)
