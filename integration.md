# 📦 Shift-Aware Roster Notifications - Complete Package

## 🎉 Apa Yang Sudah Selesai

Sistem notifikasi roster yang shift-aware sudah **fully implemented** di frontend dengan fitur-fitur canggih:

### ✅ Fitur Frontend
1. **Shift Tabs** - Pagi, Siang, Malam dengan task count
2. **Date Filter** - Filter tasks by date dalam shift view
3. **Status Badges** - Pending (kuning), In Progress (biru), Done (hijau)
4. **Quick Actions** - ▶ Start, ✓ Done buttons
5. **Smart Filtering** - Auto-filter by user role + direct assignment
6. **Optimistic Updates** - Instant UI update, fallback on error
7. **Error Handling** - Toast notifications + graceful fallbacks
8. **Responsive** - Mobile & desktop friendly
9. **LocalStorage Fallback** - Offline support

---

## 📂 File Structure

### Documentation (COPY & KIRIM KE BACKEND)
```
├── BACKEND_REQUIREMENTS.md  ← Detailed API specs
├── BACKEND_PROMPT.md        ← Simple copy-paste prompt untuk dev
└── FEATURES_SUMMARY.md      ← Feature overview
```

### Code
```
├── src/modules/notifications/pages/NotificationsPage.tsx
│   ├── Shift state management
│   ├── Date filtering
│   ├── Task status updates
│   └── UI with tabs & buttons
├── src/modules/roster/constants/shifts.ts (NEW)
│   └── Shift definitions & helpers
└── src/modules/roster/repository/rosterService.ts
    └── Already has getRosterTasks() & updateRosterTask()
```

---

## 🚀 Cara Kirim ke Backend

### Option 1: Copy Prompt Langsung
1. Buka file: `BACKEND_PROMPT.md`
2. Copy-paste semua isi ke backend dev
3. Backend tinggal implement 3 endpoints

### Option 2: Share Semua Dokumentasi
1. Bagikan folder ini ke backend team
2. Mereka baca `BACKEND_REQUIREMENTS.md` untuk detail
3. Gunakan `BACKEND_PROMPT.md` sebagai checklist

### Option 3: Send Links
Jika menggunakan git/repo:
```bash
# Backend dev bisa clone current branch dan baca:
git clone <repo>
cd frontend_atoms
cat BACKEND_REQUIREMENTS.md
cat BACKEND_PROMPT.md
```

---

## 📋 Backend Implementation Needed

### 3 API Endpoints Utama:

**1. GET /api/roster/tasks**
- Fetch tasks dengan shift, date, status filters
- Auto-filter by user access (role-based + assigned)
- Response: Array of tasks

**2. PUT /api/roster/tasks/:id**
- Update task status (pending/in_progress/done)
- Access control: Assigned user atau Manager
- Response: Updated task

**3. POST /api/roster/tasks** (Optional)
- Create new task
- Manager only
- Response: Created task

### Database Table Needed:
```sql
roster_tasks (
  id, date, shift_key, role, assigned_to (JSON), 
  title, description, priority, status, 
  created_by, created_at, updated_at, deleted_at
)
```

---

## 🔌 How It Works End-to-End

### Frontend Side (✅ DONE)
```
User opens Notifications → Selects "Roster" category
  ↓
Shows 3 shift tabs (Pagi [5], Siang [3], Malam [2])
  ↓
User clicks Siang → Shows date filter if multiple dates
  ↓
User sees tasks with [Pending] badge
  ↓
User clicks ▶ Start → API call to update status
  ↓
Task status becomes [In Progress]
  ↓
User clicks ✓ Done → API call to update status
  ↓
Task status becomes [Done]
```

### Backend Side (❌ TODO)
```
Frontend: GET /api/roster/tasks?shift=13-19&date=2026-03-26
Backend: SELECT * FROM roster_tasks WHERE conditions + access control
Backend: Return filtered tasks
  ↓
Frontend: GET /api/roster/tasks?shift=13-19&date=2026-03-26
  ↓
Frontend shows tasks in UI
  ↓
User clicks "Done"
  ↓
Frontend: PUT /api/roster/tasks/1 { status: 'done' }
  ↓
Backend: Validate permission + update status
  ↓
Backend: Return updated task
  ↓
Frontend: Update UI optimistically (instant)
```

---

## 📊 Data Flow

### Frontend Request/Response Format

**GET Request:**
```
GET /api/roster/tasks?shift=13-19&date=2026-03-26&status=pending
```

**GET Response:**
```json
{
  "data": [
    {
      "id": 1,
      "date": "2026-03-26",
      "shift_key": "13-19",
      "role": "CNS",
      "assigned_to": [5, 7],
      "title": "Perbaikan AC",
      "status": "pending",
      "priority": "high",
      "created_by": 1,
      "created_at": "2026-03-26T08:00:00Z",
      "updated_at": "2026-03-26T08:00:00Z"
    }
  ],
  "total": 1
}
```

**PUT Request:**
```
PUT /api/roster/tasks/1
{ "status": "done" }
```

**PUT Response:**
```json
{
  "message": "Task updated successfully",
  "data": { ...updated task object }
}
```

---

## ⚙️ Build Status

✅ **Frontend Build: SUCCESS**
```
✓ 2146 modules transformed
✓ built in 4.66s
No errors, only warnings for chunk size
```

---

## 🎯 Next Steps for Backend Team

### Immediate (Week 1)
1. [ ] Create migration for `roster_tasks` table
2. [ ] Create Model & relationships
3. [ ] Implement GET endpoint with filtering
4. [ ] Implement PUT endpoint with authorization
5. [ ] Test with Postman/Hoppscotch

### Follow-up (Week 2)
6. [ ] Implement POST endpoint (optional)
7. [ ] Add error handling & validation
8. [ ] Add request logging
9. [ ] Performance optimization
10. [ ] Integration testing dengan frontend

### Polish (Week 3+)
11. [ ] Add webhook notifications
12. [ ] Add metrics API
13. [ ] Add audit logging
14. [ ] Real-time updates (WebSocket)

---

## 📞 Quick Reference

### Shift Keys (Selalu gunakan ini)
- `07-13` = Pagi (07:00-13:00)
- `13-19` = Siang (13:00-19:00)
- `19-07` = Malam (19:00-07:00)

### Status Values
- `pending` = Not started
- `in_progress` = Being worked on
- `done` = Completed

### Priority Values
- `low`, `medium`, `high`

### User Visibility Rules
Task ditampilkan ke user jika:
1. `user.id` ada di `assigned_to` array, ATAU
2. `user.employee_type` matches `role` di task

---

## 📁 Files to Share with Backend

```
BACKEND_REQUIREMENTS.md  ← Full spec with examples
BACKEND_PROMPT.md        ← Copy-paste prompt
FEATURES_SUMMARY.md      ← Feature overview & testing checklist
```

---

## ✨ Special Notes

1. **Shift Tabs Appear Only in Roster Category** - Normal notifications tidak affected
2. **Offline Support** - Frontend fallback to localStorage jika API down
3. **Optimistic UI** - Status update instant, no spinner wait
4. **Role-Based Access** - No need for separate user endpoint, handle di response filter
5. **Soft Delete** - Use `deleted_at` field, don't hard delete

---

## 🎊 You're Ready!

Frontend 100% complete & ready untuk integrate dengan backend.

### To Share Build:
```bash
# Build already done, artifacts in dist/
# Share BACKEND_REQUIREMENTS.md & BACKEND_PROMPT.md ke backend dev
# They implement 3 endpoints
# Done! 🚀
```

📧 **Next: Send `BACKEND_REQUIREMENTS.md` + `BACKEND_PROMPT.md` ke backend developer**
