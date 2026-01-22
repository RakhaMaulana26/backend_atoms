# 🚀 Quick Reference: Roster Assignment

## 📌 Two Ways to Assign

| Method | Endpoint | Behavior | Use When |
|--------|----------|----------|----------|
| **POST** | `POST /rosters/{id}/days/{id}/assignments` | **Adds** without deleting | Adding 1 or more employees |
| **PUT** | `PUT /rosters/{id}/days/{id}/assignments` | **Replaces** all assignments | Starting fresh / bulk update |

---

## ✅ POST - Add One by One (Recommended)

### Add 1 Employee
```json
POST /rosters/1/days/1/assignments
{
  "shift_assignments": [
    {"employee_id": 5, "shift_id": 1}
  ]
}
```

### Add Manager Only
```json
POST /rosters/1/days/1/assignments
{
  "manager_duties": [
    {"employee_id": 7, "duty_type": "Manager Teknik"}
  ]
}
```

### Add Multiple (Batch)
```json
POST /rosters/1/days/1/assignments
{
  "shift_assignments": [
    {"employee_id": 5, "shift_id": 1},
    {"employee_id": 6, "shift_id": 1},
    {"employee_id": 7, "shift_id": 1},
    {"employee_id": 8, "shift_id": 1}
  ]
}
```

---

## 📋 Daily Requirements

### Per Shift (3 shifts per day)
- ✅ Minimum **4 CNS** employees
- ✅ Minimum **2 Support** employees
- ✅ Total: **6 employees per shift**

### Per Day
- ✅ Minimum **1 Manager Teknik**

---

## 🔄 Typical Workflow

```bash
# 1. Create roster (auto-generates 31 days for month)
POST /rosters
{"month": 1, "year": 2026}

# 2. Add employees gradually
POST /rosters/1/days/1/assignments
{"shift_assignments": [{"employee_id": 1, "shift_id": 1}]}

POST /rosters/1/days/1/assignments
{"shift_assignments": [{"employee_id": 2, "shift_id": 1}]}

# ... continue until requirements met

# 3. Validate before publish
GET /rosters/1/validate

# 4. Publish (if valid)
POST /rosters/1/publish
```

---

## 🚨 Common Mistakes

### ❌ Using PUT when you mean POST
```json
PUT /rosters/1/days/1/assignments  // ⚠️ DELETES everything!
{"shift_assignments": [{"employee_id": 1, "shift_id": 1}]}
```
**Fix:** Use POST instead

---

### ❌ Sending empty request
```json
POST /rosters/1/days/1/assignments
{
  // ❌ Must provide at least one array
}
```
**Fix:** Include shift_assignments or manager_duties

---

## 🧪 Test in Swagger UI

1. Open: http://localhost:8000/api-docs.html
2. Click **Authorize** → Enter: `Bearer {token}`
3. Find: `POST /rosters/{roster_id}/days/{day_id}/assignments`
4. Click **Try it out**
5. Use examples above
6. Click **Execute**

---

## 📊 Validation Response

```json
{
  "message": "Assignments added successfully",
  "validation": {
    "is_complete": false,
    "missing_requirements": [
      "Shift 1 requires 3 more CNS employees",
      "Shift 1 requires 2 Support employees"
    ]
  }
}
```

---

## 📚 Full Documentation

- **[ASSIGNMENT_WORKFLOW_GUIDE.md](./ASSIGNMENT_WORKFLOW_GUIDE.md)** - Complete guide
- **[API_DOCUMENTATION.md](./API_DOCUMENTATION.md)** - All endpoints
- **[SWAGGER_QUICK_START.md](./SWAGGER_QUICK_START.md)** - Interactive testing

---

## 💡 Pro Tips

1. **Build Incrementally**: Add employees one by one or in small batches
2. **Validate Often**: Check `GET /rosters/{id}/validate` frequently
3. **Use POST**: Only use PUT when you need to start fresh
4. **Test First**: Use Swagger UI before integrating
5. **Check Response**: Response includes validation feedback

---

**Status:** ✅ duty_type column fixed - system fully functional
