# 🚀 QUICK START - Roster Task API

## ⚡ 30-Second Setup

### 1. Admin/Manager Login & Get Token
```bash
POST /api/login
{
  "email": "manager@company.com",
  "password": "password"
}
# Response: { "token": "eyJ0eXAi..." }
```

### 2. Create Task for Pagi Shift (Copy-Paste)
```bash
curl -X POST http://localhost:8000/api/roster/tasks \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "date": "2026-03-26",
    "shift_key": "07-13",
    "role": "CNS",
    "assigned_to": [5, 7],
    "title": "Perbaikan AC",
    "priority": "high"
  }'
```

### 3. Staff Get Tasks
```bash
GET /api/roster/tasks?assigned_to=5
```

### 4. Staff Update Status
```bash
PUT /api/roster/tasks/1
{ "status": "in_progress" }
```

---

## 🎯 Key Concepts

| Concept | Values | Example |
|---------|--------|---------|
| **Shift** | 07-13<br/>13-19<br/>19-07 | 07-13 = Pagi (7am-1pm) |
| **Status** | pending<br/>in_progress<br/>completed<br/>cancelled | pending (default) |
| **Priority** | low<br/>medium<br/>high | high = urgent |
| **Role** | Any string | "CNS", "IT", "Security" |

---

## 📡 The 3 Endpoints

### GET /api/roster/tasks
List all tasks (with filters)
```
Query params: date, shift_key, role, status, assigned_to
Response: { data: [...], table: {...} }
```

### POST /api/roster/tasks
Create new task (Manager/Admin only)
```
Required: date, shift_key, role, assigned_to, title, priority
Response: { data: {...}, message, table }
✅ Auto-sends notifications!
```

### PUT /api/roster/tasks/{id}
Update task (assigned user or manager)
```
Body: { status: "in_progress/completed/cancelled" }
Response: { data, message }
```

---

## 🔐 Who Can Do What?

```
┌──────────────────┬────────┬────────┬────────┐
│                  │ CREATE │ UPDATE │ DELETE │
├──────────────────┼────────┼────────┼────────┤
│ Admin            │   ✅   │   ✅   │   ✅   │
│ Manager Teknik   │   ✅   │   ✅   │   ✅   │
│ General Manager  │   ✅   │   ✅   │   ✅   │
│ Staff (assigned) │   ❌   │   ✅   │   ❌   │
│ Regular User     │   ❌   │   ❌   │   ❌   │
└──────────────────┴────────┴────────┴────────┘
```

---

## 📱 Mobile/Frontend Integration

### For Task List
```javascript
// Fetch with table format included
GET /api/roster/tasks?date=2026-03-26&shift_key=07-13

// Response includes:
{
  "data": [tasks...],
  "table": {
    "headers": [{key, label}, ...],
    "rows": [{id, date, shift_key, ...}, ...]
  }
}
```

### Render Table
```
Use response.table.headers and response.table.rows
to populate DataTable/Table component
```

---

## 🔔 Notifications Flow

```
Manager creates task
        ↓
System sends notification to assigned users
        ↓
Notification appears in /notifications?category=roster
        ↓
Staff marks as read & can click to detail
        ↓
Staff updates status → system knows progress
```

---

## ❌ Common Issues & Fixes

| Problem | Solution |
|---------|----------|
| Error 401 Unauthorized | Add JWT token in Authorization header |
| Error 403 Forbidden | Use manager/admin account (not staff) |
| Error 422 Validation Failed | Check shift_key (must be 07-13, 13-19, 19-07) |
| No tasks returned | Check date & shift_key filters |
| Task not in my list | Check if your ID is in assigned_to array |

---

## 📋 Checklist Before Going Live

- [ ] Database migrated: `php artisan migrate`
- [ ] Routes registered in `api.php`
- [ ] Controller has auth guards applied
- [ ] Notification table has `category` column
- [ ] JWT token validation working
- [ ] Test with different user roles
- [ ] Test all 3 endpoints
- [ ] Check filtering works
- [ ] Verify notifications sent/received
- [ ] Test table format response
- [ ] Document for frontend team
- [ ] Load test: Can handle 10 concurrent requests?

---

## 🧪 Quick Test (5 minutes)

### Step 1: Get Admin Token
```bash
curl -X POST http://localhost:8000/api/login \
  -d '{"email":"admin@test.com","password":"password"}'
```

### Step 2: Create Task
```bash
curl -X POST http://localhost:8000/api/roster/tasks \
  -H "Authorization: Bearer (token_from_step_1)" \
  -H "Content-Type: application/json" \
  -d '{
    "date":"2026-03-26",
    "shift_key":"07-13",
    "role":"Test",
    "assigned_to":[1],
    "title":"Test Task"
  }'
```

### Step 3: Get Tasks
```bash
curl -X GET http://localhost:8000/api/roster/tasks \
  -H "Authorization: Bearer (token_from_step_1)"
```

---

## 🎓 Understanding Shifts

```
PAGI (Morning)
07:00 ├─────────────────────────────────────┤ 13:00
      └─→ Shift Key: 07-13
      
SIANG (Afternoon)
13:00 ├─────────────────────────────────────┤ 19:00
      └─→ Shift Key: 13-19
      
MALAM (Night/Evening)
19:00 ├─────────────────────────────────────┤ 07:00+1
      └─→ Shift Key: 19-07
```

---

## 📞 Support

**Issue:** Endpoint returns 404
**Check:** Is route in `api.php`?

**Issue:** Token invalid
**Check:** Is API middleware registered?

**Issue:** No notification
**Check:** Is `category` column in `notifications` table?

**Issue:** Shift_key validation fails
**Check:** Use exactly: `07-13`, `13-19`, or `19-07` (not `Pagi`, `Siang`, etc)

---

## 📁 Files Modified/Created

```
app/Http/Controllers/Api/RosterTaskController.php  ✅ Enhanced
app/Models/RosterTask.php                          ✅ Ready
database/migrations/...roster_tasks_table          ✅ Created
ROSTER_TASK_GUIDE.md                               📖 This guide
test_roster_tasks.sh                                🧪 Ready
```

---

**Version:** 1.0  
**Last Update:** 2026-03-26  
**Status:** ✅ Ready for Production
