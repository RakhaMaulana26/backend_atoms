# 📊 Complete Example - Shift-Aware Roster Tasks

## 🎬 Scenario: Daily Operations 26 Mar 2026

Sebuah perusahaan IT services dengan 3 shift:
- **Pagi:** 7 pagi - 1 siang (07-13)
- **Siang:** 1 siang - 7 malam (13-19)
- **Malam:** 7 malam - 7 pagi berikutnya (19-07)

Setiap shift ada berbagai departemen yang perlu assign tugas daily.

---

## 👥 Sample Users (Database)

```sql
INSERT INTO users VALUES
(1, 'Admin.Name', 'admin@company.com', password, 'admin', '2026-01-01'),
(2, 'Manager.Teknik', 'manager@company.com', password, 'manager_teknik', '2026-01-01'),
(3, 'IT.Staff1', 'it1@company.com', password, 'user', '2026-01-01'),
(4, 'IT.Staff2', 'it2@company.com', password, 'user', '2026-01-01'),
(5, 'CNS.Tech1', 'cns1@company.com', password, 'user', '2026-01-01'),
(6, 'CNS.Tech2', 'cns2@company.com', password, 'user', '2026-01-01'),
(7, 'Security.Guard', 'sec1@company.com', password, 'user', '2026-01-01'),
(8, 'Maintenance.Staff', 'maint@company.com', password, 'user', '2026-01-01'),
(...);
```

---

## 🎯 Schedule for 26 Mar 2026

### Morning Shift (Pagi - 07-13)

#### Task 1: AC Maintenance
```bash
POST /api/roster/tasks
Authorization: Bearer MANAGER_TOKEN

{
  "date": "2026-03-26",
  "shift_key": "07-13",
  "role": "Maintenance",
  "assigned_to": [5, 6, 8],
  "title": "Perbaikan AC Ruang Server",
  "description": "AC tidak dingin di ruang server building A. Check refrigerant level dan test thermostat.",
  "priority": "high",
  "status": "pending"
}
```

**Response:**
```json
{
  "data": {
    "id": 1,
    "date": "2026-03-26",
    "shift_key": "07-13",
    "role": "Maintenance",
    "assigned_to": [5, 6, 8],
    "title": "Perbaikan AC Ruang Server",
    "priority": "high",
    "status": "pending",
    "created_at": "2026-03-26T08:00:00Z"
  },
  "message": "Task berhasil dibuat",
  "shift_info": {
    "shift_name": "Pagi (07.00-13.00)",
    "assigned_count": 3
  }
}
```

**Notifications Created:**
```sql
-- For user 5
INSERT INTO notifications VALUES
(101, 5, 2, 'Task Baru Shift Pagi (07.00-13.00)', 
 'Anda mendapat tugas baru: Perbaikan AC Ruang Server pada tanggal 2026-03-26 (Pagi (07.00-13.00))',
 'roster', 'roster', 1, {...}, false, false, '2026-03-26T08:00:00Z');

-- For user 6
INSERT INTO notifications VALUES
(102, 6, 2, 'Task Baru Shift Pagi (07.00-13.00)',
 'Anda mendapat tugas baru: Perbaikan AC Ruang Server pada tanggal 2026-03-26 (Pagi (07.00-13.00))',
 'roster', 'roster', 1, {...}, false, false, '2026-03-26T08:00:00Z');

-- For user 8
INSERT INTO notifications VALUES
(103, 8, 2, 'Task Baru Shift Pagi (07.00-13.00)',
 'Anda mendapat tugas baru: Perbaikan AC Ruang Server pada tanggal 2026-03-26 (Pagi (07.00-13.00))',
 'roster', 'roster', 1, {...}, false, false, '2026-03-26T08:00:00Z');
```

---

#### Task 2: Cleaning & Inspection
```bash
POST /api/roster/tasks
Authorization: Bearer MANAGER_TOKEN

{
  "date": "2026-03-26",
  "shift_key": "07-13",
  "role": "Cleaning",
  "assigned_to": [10, 11],
  "title": "Pembersihan & Inspeksi Lantai 3",
  "description": "Vacuum carpet, wipe desk, cek HVAC outlet, inspect fire extinguisher",
  "priority": "medium",
  "status": "pending"
}
```

Database entry:
```sql
INSERT INTO roster_tasks VALUES
(2, '2026-03-26', '07-13', 'Cleaning', '[10,11]', 'Pembersihan & Inspeksi Lantai 3',
 'Vacuum carpet, wipe desk, cek HVAC outlet, inspect fire extinguisher',
 'medium', 'pending', NOW(), NOW());
```

---

### Afternoon Shift (Siang - 13-19)

#### Task 3: IT Software Updates
```bash
POST /api/roster/tasks
Authorization: Bearer MANAGER_TOKEN

{
  "date": "2026-03-26",
  "shift_key": "13-19",
  "role": "IT Support",
  "assigned_to": [3, 4],
  "title": "Update Antivirus & OS Patches",
  "description": "Download latest antivirus definitions, install Windows/Linux security patches on all workstations in zone A & B",
  "priority": "high",
  "status": "pending"
}
```

**Notifications to users 3 & 4:**
```json
{
  "title": "Task Baru Shift Siang (13.00-19.00)",
  "message": "Anda mendapat tugas baru: Update Antivirus & OS Patches pada tanggal 2026-03-26 (Siang (13.00-19.00))",
  "category": "roster"
}
```

---

### Night Shift (Malam - 19-07)

#### Task 4: Security Patrol & Monitoring
```bash
POST /api/roster/tasks
Authorization: Bearer MANAGER_TOKEN

{
  "date": "2026-03-26",
  "shift_key": "19-07",
  "role": "Security",
  "assigned_to": [7, 9],
  "title": "Patrol & CCTV Monitoring",
  "description": "Jaga keliling area building, monitor CCTV di 4 lokasi utama, cek semua pintu terkunci, log semua kejadian",
  "priority": "high",
  "status": "pending"
}
```

---

## ⏰ Timeline: How Staff Works Through the Day

### 08:00 AM - Morning Shift Starts
```
Manager creates 2 tasks for Pagi shift
        ↓
Notifikasi dikirim ke 5 users
        ↓
Staff melihat di phone: "Task Baru Shift Pagi (07.00-13.00)"
```

### 08:30 AM - First Check In
**Staff User #5 (CNS Tech):**
```bash
# Check my tasks
GET /api/roster/tasks?assigned_to=5&date=2026-03-26

Response:
{
  "data": [
    {
      "id": 1,
      "title": "Perbaikan AC Ruang Server",
      "shift_key": "07-13",
      "status": "pending",
      "priority": "high"
    }
  ]
}

# Click notification → See full details
GET /api/roster/tasks/1

# Start working: Mark as IN_PROGRESS
PUT /api/roster/tasks/1
{ "status": "in_progress" }
```

### 10:15 AM - Update Status
```
User #5 & #6 working on AC
        ↓
They test refrigerant, fix thermostat
        ↓
Mark as COMPLETED
```

**Database State:**
```sql
UPDATE roster_tasks SET status = 'completed', updated_at = NOW() WHERE id = 1;
```

### 12:45 PM - End of Morning Shift
```
Task 1: COMPLETED ✅
Task 2: IN_PROGRESS (cleaning still ongoing)

Manager checks:
GET /api/roster/tasks?shift_key=07-13&date=2026-03-26

See progress in table format:
```

---

## 📊 Database Final State After All Operations

### roster_tasks table
```sql
id │ date       │ shift_key │ role         │ assigned_to  │ title                    │ priority │ status
───┼────────────┼───────────┼──────────────┼──────────────┼──────────────────────────┼──────────┼─────────────
 1 │ 2026-03-26 │ 07-13     │ Maintenance  │ [5,6,8]      │ Perbaikan AC Server      │ high     │ completed
 2 │ 2026-03-26 │ 07-13     │ Cleaning     │ [10,11]      │ Pembersihan Lantai 3     │ medium   │ in_progress
 3 │ 2026-03-26 │ 13-19     │ IT Support   │ [3,4]        │ Update Antivirus & Patch │ high     │ pending
 4 │ 2026-03-26 │ 19-07     │ Security     │ [7,9]        │ Patrol & CCTV Monitoring │ high     │ pending
```

### notifications table (sample entries)
```sql
id  │ user_id │ sender_id │ title                                  │ category │ reference_id │ is_read
────┼─────────┼───────────┼────────────────────────────────────────┼──────────┼──────────────┼─────────
123 │ 5       │ 2         │ Task Baru Shift Pagi (07.00-13.00)     │ roster   │ 1            │ false
124 │ 6       │ 2         │ Task Baru Shift Pagi (07.00-13.00)     │ roster   │ 1            │ false
125 │ 8       │ 2         │ Task Baru Shift Pagi (07.00-13.00)     │ roster   │ 1            │ false
126 │ 10      │ 2         │ Task Baru Shift Pagi (07.00-13.00)     │ roster   │ 2            │ true
127 │ 11      │ 2         │ Task Baru Shift Pagi (07.00-13.00)     │ roster   │ 2            │ false
128 │ 3       │ 2         │ Task Baru Shift Siang (13.00-19.00)    │ roster   │ 3            │ false
129 │ 4       │ 2         │ Task Baru Shift Siang (13.00-19.00)    │ roster   │ 3            │ false
130 │ 7       │ 2         │ Task Baru Shift Malam (19.00-07.00)    │ roster   │ 4            │ false
131 │ 9       │ 2         │ Task Baru Shift Malam (19.00-07.00)    │ roster   │ 4            │ false
```

---

## 📱 Frontend Table Rendering

**Manager Dashboard - 26 Mar 2026**

```
┌───────────────────────────────────────────────────────────────┐
│ 🔄 Roster Tasks - 26 Mar 2026                                 │
├───┬────────┬──────────┬────────┬────────────┬───────────────────┤
│ID │ Shift  │ Status   │ Priori │ Assigned   │ Title             │
├───┼────────┼──────────┼────────┼────────────┼───────────────────┤
│1  │07-13🌅│✅ Done   │🔴HIGH │ 3 people   │ AC Maintenance    │
│2  │07-13🌅│⏳Working │🟡MED  │ 2 people   │ Cleaning Lantai 3 │
│3  │13-19🌤 │⏳Pending │🔴HIGH │ 2 people   │ Antivirus Update  │
│4  │19-07🌙 │⏳Pending │🔴HIGH │ 2 people   │ Security Patrol   │
└───┴────────┴──────────┴────────┴────────────┴───────────────────┘
```

**Staff Notification & Task View**

```
📬 Inbox (4 new)
├─ 🔔 Task Baru Shift Pagi (07.00-13.00)
│  "Anda mendapat tugas baru: Perbaikan AC..."
│  👤 Assigned: You, CNS.Tech2, Maintenance.Staff
│  ⏰ Date: 26 Mar 2026
│  [View Task] [Mark as Read]
│
├─ 🔔 Task Baru Shift Pagi (07.00-13.00)  
│  "Anda mendapat tugas baru: Pembersihan..."
│  👤 Assigned: You, Cleaning.Contractor
│  [View Task] [Mark as Read]
│
└─ 🔔 Task Baru Shift Siang (13.00-19.00)
   "Anda mendapat tugas baru: Update Antivirus..."
   👤 Assigned: You, IT.Staff2
   [View Task] [Mark as Read]
```

**Update Workflow**

```
📋 Task: Perbaikan AC Ruang Server

Current Status: ⏳ IN PROGRESS
Updated by: CNS.Tech1
Last update: 10:15 AM

[← Back] [Mark Completed] [Cancel Task] [Comment]

Progress Log:
├─ 08:00 - Task created by Manager.Teknik
├─ 08:30 - Marked as IN_PROGRESS by CNS.Tech1
└─ 10:15 - AC refrigerant refilled, testing...
```

---

## 🎓 Key Learnings

### 1. **Shift is Critical**
- Same role on different shifts = different tasks
- 07-13 CNS ≠ 13-19 CNS
- Each shift runs independently

### 2. **Assigned_to is Array**
- One task can go to multiple users
- Each gets their own notification
- Each can update status independently

### 3. **Notifications Trigger Automatically**
- No separate notification API call needed
- System auto-creates in `notifications` table
- Frontend polls `/notifications?category=roster`

### 4. **Table Format Helps UI**
- API returns both `data` (raw) and `table` (formatted)
- Frontend can use `table.rows` for instant rendering
- Format consistency across all responses

### 5. **Status Updates Are Flexible**
- Any direction allowed: pending↔in_progress↔completed↔cancelled
- No state machine restrictions
- Good for real-world flexibility

---

## ✅ Verification Checklist

After deployment, test these scenarios:

```
[ ] Admin can create task
[ ] Manager can create task
[ ] Staff CANNOT create task
[ ] Task notifications sent to all assigned_to users
[ ] Notifications appear in /notifications?category=roster
[ ] Staff can update task status
[ ] Manager can view all tasks
[ ] Filter by date works
[ ] Filter by shift_key works (07-13, 13-19, 19-07)
[ ] Filter by role works
[ ] Filter by status works
[ ] Pagination works (per_page parameter)
[ ] Table format in response
[ ] Error 403 for non-manager create
[ ] Error 422 for invalid shift_key
[ ] Error 422 for empty assigned_to array
```

---

## 🚀 Going Live Checklist

- [ ] All migrations run: `php artisan migrate`
- [ ] Routes configured correctly
- [ ] Middleware auth enabled
- [ ] Test with Postman/Hoppscotch
- [ ] Load testing (10+ concurrent)
- [ ] Error messages clear & helpful
- [ ] Logs configured
- [ ] Documentation shared with team
- [ ] Frontend integrated & tested
- [ ] Database backups in place

---

**Status:** ✅ Production Ready  
**Last Updated:** 2026-03-26  
**Version:** 1.0
