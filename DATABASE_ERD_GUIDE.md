# Database ERD Documentation - ATOMS

## Overview
File **`database_erd.dbml`** berisi Entity Relationship Diagram (ERD) lengkap untuk sistem ATOMS dalam format DBML (Database Markup Language).

---

## 📊 Quick View ERD

### Cara Melihat ERD Secara Visual:

1. **Buka dbdiagram.io**
   ```
   https://dbdiagram.io/
   ```

2. **Import DBML**
   - Click **"Go to App"** atau **"Login"** (free account)
   - Click **"New Diagram"**
   - Copy semua isi file `database_erd.dbml`
   - Paste ke editor di dbdiagram.io
   - ERD otomatis ter-generate!

3. **View Options**
   - Drag & drop untuk arrange tables
   - Zoom in/out untuk detail view
   - Export sebagai PNG/PDF/SQL

---

## 🗂️ Database Structure

### Core Tables (5 tables)

#### 1. **users**
- **Purpose:** System users dengan authentication
- **Key Fields:** 
  - `role`: admin, cns, support, manager_teknik, general_manager
  - `is_active`: Status aktif
- **Relationships:** 
  - 1 user → 1 employee
  - 1 user → many notifications
  - 1 user → many activity_logs
  - 1 user → many account_tokens

#### 2. **employees**
- **Purpose:** Employee records dengan type classification
- **Key Fields:**
  - `employee_type`: admin, CNS, Support, Manager Teknik, General Manager
  - `is_active`: Status aktif
- **Relationships:**
  - belongs to 1 user
  - has many shift_assignments
  - has many manager_duties
  - has many shift_requests (as requester/target)

#### 3. **account_tokens**
- **Purpose:** Temporary tokens untuk activation/reset password
- **Key Fields:**
  - `token`: Unique token string
  - `type`: activation, reset_password
  - `is_used`: Flag sudah digunakan
  - `expired_at`: Expiration timestamp
- **Relationships:**
  - belongs to 1 user

#### 4. **sessions**
- **Purpose:** Laravel session storage
- **Relationships:**
  - belongs to 1 user (optional)

#### 5. **personal_access_tokens**
- **Purpose:** Laravel Sanctum authentication tokens
- **Relationships:**
  - polymorphic relation to any model (usually users)

---

### Roster Management Tables (5 tables)

#### 6. **shifts**
- **Purpose:** Master data shift types
- **Data:** 
  - Shift 1 - Pagi (07:00-19:00)
  - Shift 2 - Malam (19:00-07:00)
  - Shift 3 - Full Day (00:00-23:59)
- **Relationships:**
  - has many shift_assignments
  - has many shift_requests

#### 7. **roster_periods**
- **Purpose:** Monthly roster periods
- **Key Fields:**
  - `month`: 1-12
  - `year`: YYYY
  - `status`: draft, published
- **Unique Constraint:** (month, year)
- **Relationships:**
  - has many roster_days (28-31 auto-generated)

#### 8. **roster_days**
- **Purpose:** Individual days dalam roster period
- **Key Fields:**
  - `work_date`: Date of roster day
- **Relationships:**
  - belongs to 1 roster_period
  - has many shift_assignments
  - has many manager_duties
  - referenced by shift_requests

#### 9. **shift_assignments**
- **Purpose:** Employee assignments ke specific shifts
- **Validation:** Each shift requires minimum 4 CNS + 2 Support
- **Relationships:**
  - belongs to 1 roster_day
  - belongs to 1 shift
  - belongs to 1 employee

#### 10. **manager_duties**
- **Purpose:** Manager duty assignments per day
- **Key Fields:**
  - `duty_type`: Manager Teknik, General Manager
- **Validation:** Each day requires minimum 1 Manager Teknik
- **Relationships:**
  - belongs to 1 roster_day
  - belongs to 1 employee

---

### Shift Request Tables (1 table)

#### 11. **shift_requests**
- **Purpose:** Shift swap/change requests
- **Key Fields:**
  - `status`: pending, approved, rejected
  - `approved_by_target`: Target employee approval
  - `approved_by_from_manager`: From manager approval
  - `approved_by_to_manager`: To manager approval
- **Relationships:**
  - requester → employee
  - target → employee
  - from_roster_day → roster_day
  - to_roster_day → roster_day
  - shift → shift

---

### Notification & Logging Tables (2 tables)

#### 12. **notifications**
- **Purpose:** User notifications
- **Key Fields:**
  - `is_read`: Read status
  - `email_sent`: Email notification sent flag
- **Relationships:**
  - belongs to 1 user

#### 13. **activity_logs**
- **Purpose:** System audit trail
- **Key Fields:**
  - `action`: create, update, delete, publish
  - `module`: roster, user, shift_request, notification
  - `reference_id`: ID of related record
- **Relationships:**
  - belongs to 1 user (optional)

---

### Laravel System Tables (5 tables)

#### 14-18. **cache, cache_locks, jobs, job_batches, failed_jobs**
- **Purpose:** Laravel framework tables
- Queue management, cache, failed jobs

---

## 🔗 Key Relationships

### User → Employee (1:1)
```
users.id ←→ employees.user_id
```

### Roster Period → Roster Days (1:Many)
```
roster_periods.id ← roster_days.roster_period_id
```
- 1 roster period = 28-31 days (auto-generated)

### Roster Day → Shift Assignments (1:Many)
```
roster_days.id ← shift_assignments.roster_day_id
```
- Each day has multiple shift assignments
- Each shift assignment links: employee + shift + day

### Roster Day → Manager Duties (1:Many)
```
roster_days.id ← manager_duties.roster_day_id
```
- Each day has 1+ manager duties

### Employee → Assignments (1:Many)
```
employees.id ← shift_assignments.employee_id
employees.id ← manager_duties.employee_id
```

### Shift Request → Multiple Relations
```
employees.id ← shift_requests.requester_employee_id
employees.id ← shift_requests.target_employee_id
roster_days.id ← shift_requests.from_roster_day_id
roster_days.id ← shift_requests.to_roster_day_id
shifts.id ← shift_requests.shift_id
```

---

## 📐 Indexes & Constraints

### Unique Constraints
1. `users.email` - Email must be unique
2. `roster_periods (month, year)` - One roster per month
3. `account_tokens.token` - Token must be unique
4. `personal_access_tokens.token` - Token must be unique

### Foreign Key Constraints
- All relationships use `FOREIGN KEY` with `ON DELETE CASCADE`
- Exception: `activity_logs.user_id` uses `ON DELETE SET NULL`

### Indexes for Performance
- All foreign keys have indexes
- Status fields (is_active, status, is_read)
- Date fields (work_date, created_at)
- Enum fields (role, employee_type, duty_type)

---

## 🎨 dbdiagram.io Tips

### Viewing the Diagram

1. **Auto Layout**
   - Click "Auto Arrange" untuk optimal layout
   - Drag tables untuk custom arrangement

2. **Focus on Specific Area**
   - Click table untuk highlight relationships
   - Use zoom untuk detail view

3. **Table Groups**
   - Tables sudah di-group by category:
     - `core_auth` - User & authentication tables
     - `roster_management` - Roster tables
     - `shift_requests_group` - Shift request tables
     - `notifications_logs` - Notification & audit tables
     - `laravel_system` - Framework tables

### Exporting

1. **Export as Image**
   - Click "Export" → "PNG" atau "SVG"
   - High quality untuk documentation

2. **Export as PDF**
   - Click "Export" → "PDF"
   - Printable version

3. **Export as SQL**
   - Click "Export" → "MySQL" atau "PostgreSQL"
   - Generate CREATE TABLE statements

4. **Share Link**
   - Click "Share" untuk shareable link
   - Team collaboration

---

## 🔧 Generating SQL from DBML

### For MySQL
```bash
# Using dbdocs CLI (optional)
npm install -g dbdocs
dbdocs build database_erd.dbml
```

### For PostgreSQL
Sama seperti di atas, pilih PostgreSQL saat export dari dbdiagram.io

### Manual SQL Generation
Dari dbdiagram.io:
1. Click "Export" → "MySQL" atau "PostgreSQL"
2. Copy generated SQL
3. Use untuk setup new database

---

## 📊 Business Rules Documented in ERD

### Roster Validation Rules
1. **1 Roster = 1 Month**
   - Unique constraint: (month, year)
   - Auto-generates 28-31 days

2. **3 Shifts per Day**
   - Each roster_day should have assignments for all 3 shifts
   - Enforced in application layer (RosterController)

3. **Minimum Staffing per Shift**
   - 4 CNS employees minimum
   - 2 Support employees minimum
   - Enforced in application layer (validation before publish)

4. **Manager Duty per Day**
   - Minimum 1 Manager Teknik per day
   - Stored in manager_duties table
   - Enforced in application layer (validation before publish)

### Shift Request Approval Flow
1. Requester creates request
2. Target employee approves → `approved_by_target = true`
3. From manager approves → `approved_by_from_manager = true`
4. To manager approves → `approved_by_to_manager = true`
5. Status changes to "approved" when all approvals received

---

## 🎯 Table Statistics

| Category | Table Count | Total Rows (Estimate) |
|----------|-------------|----------------------|
| Core Auth | 5 | 100-1000 users |
| Roster Management | 5 | 10K-100K assignments |
| Shift Requests | 1 | 1K-10K requests |
| Notifications | 2 | 10K-100K logs |
| Laravel System | 5 | Varies |
| **TOTAL** | **18 tables** | **20K-200K rows** |

---

## 🔍 Common Queries (for reference)

### Get Complete Roster with Assignments
```sql
SELECT 
  rp.*,
  rd.work_date,
  sa.employee_id,
  e.employee_type,
  s.name as shift_name
FROM roster_periods rp
JOIN roster_days rd ON rd.roster_period_id = rp.id
JOIN shift_assignments sa ON sa.roster_day_id = rd.id
JOIN employees e ON e.id = sa.employee_id
JOIN shifts s ON s.id = sa.shift_id
WHERE rp.id = 1
ORDER BY rd.work_date, s.id;
```

### Check Validation Status
```sql
-- Count employees per shift per day
SELECT 
  rd.work_date,
  s.name as shift_name,
  SUM(CASE WHEN e.employee_type = 'CNS' THEN 1 ELSE 0 END) as cns_count,
  SUM(CASE WHEN e.employee_type = 'Support' THEN 1 ELSE 0 END) as support_count
FROM roster_days rd
JOIN shift_assignments sa ON sa.roster_day_id = rd.id
JOIN employees e ON e.id = sa.employee_id
JOIN shifts s ON s.id = sa.shift_id
WHERE rd.roster_period_id = 1
GROUP BY rd.work_date, s.id, s.name
HAVING cns_count < 4 OR support_count < 2;
```

### Check Manager Duties
```sql
-- Days missing Manager Teknik
SELECT rd.work_date
FROM roster_days rd
LEFT JOIN manager_duties md ON md.roster_day_id = rd.id 
  AND md.duty_type = 'Manager Teknik'
WHERE rd.roster_period_id = 1
  AND md.id IS NULL;
```

---

## 🚀 Next Steps

1. ✅ Copy `database_erd.dbml` content
2. ✅ Paste to dbdiagram.io
3. ✅ View interactive ERD
4. ✅ Export as image untuk documentation
5. ✅ Share link dengan team
6. ✅ Generate SQL jika perlu setup database baru

---

## 📝 Maintenance Notes

### Updating ERD
Jika ada perubahan database structure:
1. Update migrations
2. Update `database_erd.dbml`
3. Re-import ke dbdiagram.io
4. Export updated diagram

### Version Control
- `database_erd.dbml` sudah di-commit ke git
- Changes tracked automatically
- Team selalu punya latest version

---

## 📞 Resources

- **dbdiagram.io:** https://dbdiagram.io/
- **DBML Documentation:** https://www.dbml.org/docs/
- **DBML CLI:** https://www.dbml.org/cli/

---

**ERD Created:** 2026-01-22  
**Total Tables:** 18  
**Total Relationships:** 20+  
**Status:** ✅ Complete & Ready to Use
