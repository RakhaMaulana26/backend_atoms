# Backend Roster API - JSON Response Structure

## Endpoint: GET /rosters/{id}

### Response Structure
Backend sudah dikonfigurasi untuk mengembalikan data roster lengkap dengan semua relationships dalam snake_case format yang match dengan frontend TypeScript types.

### Example Response:
```json
{
  "id": 1,
  "month": 1,
  "year": 2026,
  "status": "published",
  "created_at": "2026-01-01T00:00:00.000000Z",
  "updated_at": "2026-01-15T00:00:00.000000Z",
  "roster_days": [
    {
      "id": 1,
      "roster_period_id": 1,
      "work_date": "2026-01-01",
      "created_at": "2026-01-01T00:00:00.000000Z",
      "updated_at": "2026-01-01T00:00:00.000000Z",
      "shift_assignments": [
        {
          "id": 1,
          "roster_day_id": 1,
          "employee_id": 5,
          "shift_id": 1,
          "created_at": "2026-01-01T00:00:00.000000Z",
          "employee": {
            "id": 5,
            "user_id": 5,
            "employee_type": "CNS",
            "user": {
              "id": 5,
              "name": "John Doe",
              "email": "john@example.com"
            }
          },
          "shift": {
            "id": 1,
            "shift_name": "Shift 1 - Morning",
            "start_time": "07:00:00",
            "end_time": "15:00:00"
          }
        }
      ],
      "manager_duties": [
        {
          "id": 1,
          "roster_day_id": 1,
          "employee_id": 2,
          "duty_type": "Manager Teknik",
          "created_at": "2026-01-01T00:00:00.000000Z",
          "employee": {
            "id": 2,
            "user_id": 2,
            "employee_type": "Manager Teknik",
            "user": {
              "id": 2,
              "name": "Manager Name",
              "email": "manager@example.com"
            }
          }
        }
      ]
    }
  ]
}
```

## Backend Changes Made

### 1. RosterController.php - `show()` method
Updated to eager load all necessary relationships:
```php
public function show($id)
{
    $rosterPeriod = RosterPeriod::with([
        'rosterDays' => function ($query) {
            $query->orderBy('work_date', 'asc');
        },
        'rosterDays.shiftAssignments.employee.user',
        'rosterDays.shiftAssignments.shift',
        'rosterDays.managerDuties.employee.user',
    ])->findOrFail($id);

    return response()->json($rosterPeriod);
}
```

### 2. Model Relationships
All relationships are properly defined:

**RosterPeriod Model:**
- `rosterDays()` → Returns collection of RosterDay

**RosterDay Model:**
- `shiftAssignments()` → Returns collection of ShiftAssignment
- `managerDuties()` → Returns collection of ManagerDuty

**ShiftAssignment Model:**
- `employee()` → Returns Employee with User
- `shift()` → Returns Shift

**ManagerDuty Model:**
- `employee()` → Returns Employee with User

### 3. JSON Serialization
Laravel automatically converts camelCase relationship names to snake_case in JSON:
- `rosterDays()` → `roster_days`
- `shiftAssignments()` → `shift_assignments`
- `managerDuties()` → `manager_duties`

### 4. Date Formatting
RosterDay model configured to return dates in Y-m-d format:
```php
protected function casts(): array
{
    return [
        'work_date' => 'date:Y-m-d',
    ];
}
```

## Frontend Integration

### TypeScript Types (Already Created)
Frontend sudah memiliki types yang match dengan backend response:
```typescript
interface RosterPeriod {
  id: number;
  month: number;
  year: number;
  status: 'draft' | 'published';
  created_at: string;
  updated_at: string;
  roster_days?: RosterDay[];
}

interface RosterDay {
  id: number;
  roster_period_id: number;
  work_date: string;
  created_at: string;
  updated_at: string;
  shift_assignments?: ShiftAssignment[];
  manager_duties?: ManagerDuty[];
}
```

### Service Layer (Already Implemented)
```typescript
// rosterService.ts
async getRoster(rosterId: number): Promise<RosterPeriod> {
  const response = await apiClient.get<RosterPeriod>(`/rosters/${rosterId}`);
  return response.data;
}
```

### Usage in RosterDetailPage
Data sudah di-fetch dan di-map ke components:
```typescript
// Fetch data
const data = await rosterService.getRoster(Number(id));
setRoster(data);

// Access nested data
roster.roster_days?.forEach(day => {
  // Access shift assignments
  day.shift_assignments?.forEach(assignment => {
    console.log(assignment.employee.user.name);
    console.log(assignment.shift.shift_name);
  });
  
  // Access manager duties
  day.manager_duties?.forEach(duty => {
    console.log(duty.employee.user.name);
  });
});
```

## Data Flow

```
Backend                              Frontend
--------                            ----------
RosterPeriod                   →    RosterPeriod
  └─ roster_days[]             →      └─ roster_days[]
       ├─ shift_assignments[]  →           ├─ shift_assignments[]
       │    ├─ employee        →           │    ├─ employee
       │    │    └─ user       →           │    │    └─ user
       │    └─ shift           →           │    └─ shift
       └─ manager_duties[]     →           └─ manager_duties[]
            └─ employee        →                └─ employee
                 └─ user       →                     └─ user
```

## Testing

### Test Request:
```bash
curl -X GET http://localhost:8000/api/rosters/1 \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

### Expected Behavior:
1. ✅ Returns roster with all roster_days ordered by date
2. ✅ Each roster_day includes shift_assignments array
3. ✅ Each shift_assignment includes employee.user and shift details
4. ✅ Each roster_day includes manager_duties array
5. ✅ Each manager_duty includes employee.user details
6. ✅ All dates formatted as YYYY-MM-DD
7. ✅ All keys in snake_case format

## Notes

- Backend automatically eager loads all relationships in one query (efficient)
- N+1 query problem solved with `with()` eager loading
- Frontend TypeScript types match backend structure exactly
- No additional mapping/transformation needed
- Ready for production use

## Related Files

**Backend:**
- `app/Http/Controllers/Api/RosterController.php` (line 119-135)
- `app/Models/RosterPeriod.php`
- `app/Models/RosterDay.php`
- `app/Models/ShiftAssignment.php`
- `app/Models/ManagerDuty.php`

**Frontend:**
- `src/modules/roster/types/roster.ts`
- `src/modules/roster/repository/rosterService.ts`
- `src/modules/roster/pages/RosterDetailPage.tsx`
- `src/modules/roster/components/RosterWeekView.tsx`
