# API Documentation - ATOMS (Air Traffic Operational Management System)

## Base URL
```
Development: http://localhost:8000/api
Production: https://your-domain.com/api
```

## Authentication
All protected endpoints require Bearer Token authentication.

```http
Authorization: Bearer {access_token}
```

---

## Table of Contents
1. [Authentication](#authentication-endpoints)
2. [Admin - User Management](#admin-user-management)
3. [Roster Management](#roster-management)
4. [Shift Requests](#shift-requests)
5. [Notifications](#notifications)
6. [Activity Logs](#activity-logs)

---

## Authentication Endpoints

### 1. Login
**POST** `/auth/login`

Login to the system and get access token.

**Request Body:**
```json
{
  "email": "admin@example.com",
  "password": "password123"
}
```

**Response (200 OK):**
```json
{
  "access_token": "1|laravel_sanctum_token_here",
  "token_type": "Bearer",
  "user": {
    "id": 1,
    "name": "Admin User",
    "email": "admin@example.com",
    "role": "Admin",
    "is_active": true,
    "created_at": "2026-01-01T00:00:00.000000Z",
    "updated_at": "2026-01-01T00:00:00.000000Z"
  }
}
```

**Error Responses:**
- `422 Unprocessable Entity` - Invalid credentials
- `403 Forbidden` - Account is not active

---

### 2. Logout
**POST** `/auth/logout`

Logout from the system (revoke access token).

**Headers:**
```
Authorization: Bearer {access_token}
```

**Response (200 OK):**
```json
{
  "message": "Logged out successfully"
}
```

---

### 3. Forgot Password
**POST** `/auth/forgot-password`

Request password reset code via email.

**Request Body:**
```json
{
  "email": "user@example.com"
}
```

**Response (200 OK):**
```json
{
  "message": "If your email is registered, you will receive a password reset code."
}
```

**Notes:**
- Returns success message even if email doesn't exist (security best practice)
- Sends 6-digit code via email
- Token expires in 24 hours

---

### 4. Verify Token
**POST** `/auth/verify-token`

Verify activation/reset token validity.

**Request Body:**
```json
{
  "token": "123456"
}
```

**Response (200 OK):**
```json
{
  "message": "Token is valid",
  "valid": true,
  "type": "activation",
  "user": {
    "id": 1,
    "name": "User Name",
    "email": "user@example.com"
  }
}
```

**Error Responses:**
- `404 Not Found` - Invalid token
- `400 Bad Request` - Token has expired

---

### 5. Set Password
**POST** `/auth/set-password`

Set password for new user or reset password using token.

**Request Body:**
```json
{
  "token": "123456",
  "password": "newPassword123",
  "password_confirmation": "newPassword123"
}
```

**Response (200 OK):**
```json
{
  "message": "Password set successfully",
  "user": {
    "id": 1,
    "name": "User Name",
    "email": "user@example.com",
    "is_active": true
  }
}
```

---

### 6. Change Password
**POST** `/auth/change-password`

Change password for authenticated user.

**Headers:**
```
Authorization: Bearer {access_token}
```

**Request Body:**
```json
{
  "current_password": "oldPassword123",
  "password": "newPassword123",
  "password_confirmation": "newPassword123"
}
```

**Response (200 OK):**
```json
{
  "message": "Password changed successfully"
}
```

**Error Responses:**
- `400 Bad Request` - Current password is incorrect

---

## Admin - User Management

**Access:** Admin only

All endpoints under `/admin/*` require Admin role.

### 1. Get All Users
**GET** `/admin/users`

Get list of all users with pagination and filters.

**Headers:**
```
Authorization: Bearer {access_token}
```

**Query Parameters:**
- `search` (string, optional) - Search by name or email
- `role` (string, optional) - Filter by role: `Admin`, `Cns`, `Support`, `Manager Teknik`, `General Manager`
- `employee_type` (string, optional) - Filter by employee type: `Administrator`, `CNS`, `Support`, `Manager Teknik`, `General Manager`
- `is_active` (boolean, optional) - Filter by active status
- `per_page` (integer, optional, default: 15) - Items per page

**Example Request:**
```http
GET /admin/users?search=john&role=Cns&per_page=20
```

**Response (200 OK):**
```json
{
  "current_page": 1,
  "data": [
    {
      "id": 1,
      "name": "John Doe",
      "email": "john@example.com",
      "role": "Cns",
      "is_active": true,
      "created_at": "2026-01-01T00:00:00.000000Z",
      "updated_at": "2026-01-01T00:00:00.000000Z",
      "deleted_at": null,
      "employee": {
        "id": 1,
        "user_id": 1,
        "employee_type": "CNS",
        "is_active": true,
        "created_at": "2026-01-01T00:00:00.000000Z",
        "updated_at": "2026-01-01T00:00:00.000000Z",
        "deleted_at": null
      }
    }
  ],
  "first_page_url": "http://localhost:8000/api/admin/users?page=1",
  "from": 1,
  "last_page": 5,
  "last_page_url": "http://localhost:8000/api/admin/users?page=5",
  "next_page_url": "http://localhost:8000/api/admin/users?page=2",
  "path": "http://localhost:8000/api/admin/users",
  "per_page": 15,
  "prev_page_url": null,
  "to": 15,
  "total": 75
}
```

---

### 2. Create User
**POST** `/admin/users`

Create a new user with employee record.

**Headers:**
```
Authorization: Bearer {access_token}
```

**Request Body:**
```json
{
  "name": "Jane Doe",
  "email": "jane@example.com",
  "role": "Cns",
  "employee_type": "CNS",
  "is_active": true
}
```

**Validation Rules:**
- `name`: required, string, max 255 characters
- `email`: required, email, unique
- `role`: required, must be one of: `Admin`, `Cns`, `Support`, `Manager Teknik`, `General Manager`
- `employee_type`: required, must be one of: `Administrator`, `CNS`, `Support`, `Manager Teknik`, `General Manager`
- `is_active`: optional, boolean (default: true)

**Response (201 Created):**
```json
{
  "message": "User created successfully",
  "data": {
    "id": 2,
    "name": "Jane Doe",
    "email": "jane@example.com",
    "role": "Cns",
    "is_active": true,
    "created_at": "2026-01-22T00:00:00.000000Z",
    "updated_at": "2026-01-22T00:00:00.000000Z",
    "employee": {
      "id": 2,
      "user_id": 2,
      "employee_type": "CNS",
      "is_active": true,
      "created_at": "2026-01-22T00:00:00.000000Z",
      "updated_at": "2026-01-22T00:00:00.000000Z"
    }
  }
}
```

**Error Responses:**
- `422 Unprocessable Entity` - Validation failed
- `500 Internal Server Error` - Failed to create user

---

### 3. Update User
**PATCH** `/admin/users/{id}`

Update existing user (partial update).

**Headers:**
```
Authorization: Bearer {access_token}
```

**Request Body:**
```json
{
  "name": "Jane Smith",
  "email": "jane.smith@example.com",
  "role": "Support",
  "employee_type": "Support",
  "is_active": true
}
```

**Notes:**
- All fields are optional (partial update)
- Only include fields you want to update
- If role is changed, employee_type will be automatically updated

**Response (200 OK):**
```json
{
  "message": "User updated successfully",
  "data": {
    "id": 2,
    "name": "Jane Smith",
    "email": "jane.smith@example.com",
    "role": "Support",
    "is_active": true,
    "updated_at": "2026-01-22T10:30:00.000000Z"
  }
}
```

---

### 4. Delete User (Soft Delete)
**DELETE** `/admin/users/{id}`

Soft delete a user (can be restored later).

**Headers:**
```
Authorization: Bearer {access_token}
```

**Response (200 OK):**
```json
{
  "message": "User deleted successfully"
}
```

**Notes:**
- This is a soft delete (sets `deleted_at` timestamp)
- User can be restored using restore endpoint
- Activity log is created automatically

---

### 5. Restore User
**POST** `/admin/users/{id}/restore`

Restore a soft-deleted user.

**Headers:**
```
Authorization: Bearer {access_token}
```

**Response (200 OK):**
```json
{
  "message": "User restored successfully",
  "data": {
    "id": 2,
    "name": "Jane Smith",
    "email": "jane.smith@example.com",
    "deleted_at": null
  }
}
```

---

### 6. Generate Activation/Reset Token
**POST** `/admin/users/{id}/generate-token`

Generate activation or password reset token for user.

**Headers:**
```
Authorization: Bearer {access_token}
```

**Rate Limiting:** 3 requests per minute

**Response (200 OK):**
```json
{
  "message": "Token generated successfully",
  "token": "123456",
  "purpose": "activation",
  "expired_at": "2026-01-29T00:00:00.000000Z"
}
```

**Notes:**
- Returns 6-digit code
- `purpose` can be `activation` (no password set) or `password_reset` (has password)
- Token expires in 7 days
- Previous unused tokens are automatically invalidated

---

### 7. Send Activation Code via Email
**POST** `/admin/users/{id}/send-activation-code`

Send activation/reset code to user's email.

**Headers:**
```
Authorization: Bearer {access_token}
```

**Rate Limiting:** 1 request per user per minute

**Request Body:**
```json
{
  "token": "123456"
}
```

**Response (200 OK):**
```json
{
  "message": "Activation code sent successfully"
}
```

**Error Responses:**
- `400 Bad Request` - Invalid token
- `429 Too Many Requests` - Rate limit exceeded
- `500 Internal Server Error` - Failed to send email

---

## Roster Management

**Access:** Admin, Manager Teknik, General Manager

### 1. Get Roster Periods
**GET** `/rosters`

Get list of roster periods with filters.

**Headers:**
```
Authorization: Bearer {access_token}
```

**Query Parameters:**
- `month` (integer, optional) - Filter by month (1-12)
- `year` (integer, optional) - Filter by year

**Example Request:**
```http
GET /rosters?month=1&year=2026
```

**Response (200 OK):**
```json
[
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
        "updated_at": "2026-01-01T00:00:00.000000Z"
      }
    ]
  }
]
```

---

### 2. Create Roster Template
**POST** `/rosters`

Create a new roster template for a month.

**Headers:**
```
Authorization: Bearer {access_token}
```

**Request Body:**
```json
{
  "month": 2,
  "year": 2026
}
```

**Validation Rules:**
- `month`: required, integer, between 1-12
- `year`: required, integer, minimum 2024

**Response (201 Created):**
```json
{
  "message": "Roster template created successfully. You can now assign managers and shifts to each day.",
  "data": {
    "id": 2,
    "month": 2,
    "year": 2026,
    "status": "draft",
    "created_at": "2026-01-22T00:00:00.000000Z",
    "updated_at": "2026-01-22T00:00:00.000000Z",
    "roster_days": [
      {
        "id": 32,
        "roster_period_id": 2,
        "work_date": "2026-02-01",
        "created_at": "2026-01-22T00:00:00.000000Z",
        "updated_at": "2026-01-22T00:00:00.000000Z"
      },
      {
        "id": 33,
        "roster_period_id": 2,
        "work_date": "2026-02-02",
        "created_at": "2026-01-22T00:00:00.000000Z",
        "updated_at": "2026-01-22T00:00:00.000000Z"
      }
      // ... all days in the month
    ]
  }
}
```

**Notes:**
- Creates roster period with status "draft"
- Auto-generates roster_days for all days in the month
- No shift assignments or manager duties are created yet
- You need to assign shifts separately for each day

**Error Responses:**
- `422 Unprocessable Entity` - Roster period already exists for this month/year
- `500 Internal Server Error` - Failed to create roster

---

### 3. Get Roster Details
**GET** `/rosters/{id}`

Get detailed roster information with all assignments.

**Headers:**
```
Authorization: Bearer {access_token}
```

**Response (200 OK):**
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
            "shift_name": "Shift 1",
            "start_time": "07:00:00",
            "end_time": "19:00:00"
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

---

### 4. Validate Roster Before Publish
**GET** `/rosters/{id}/validate`

Preview validation results before attempting to publish. This endpoint checks all roster validation rules without actually publishing the roster.

**Headers:**
```
Authorization: Bearer {access_token}
```

**Response (200 OK) - Valid Roster:**
```json
{
  "message": "Roster is ready to publish",
  "validation": {
    "is_valid": true,
    "total_days": 31,
    "valid_days": 31,
    "invalid_days": [],
    "errors": []
  }
}
```

**Response (200 OK) - Invalid Roster:**
```json
{
  "message": "Roster has validation errors",
  "validation": {
    "is_valid": false,
    "total_days": 31,
    "valid_days": 25,
    "invalid_days": [
      {
        "date": "2025-01-15",
        "is_valid": false,
        "manager_count": 0,
        "errors": [
          "Missing Manager Teknik (required: minimum 1)",
          "Shift Shift 1 - Pagi (07:00-19:00): Need 4 CNS (current: 2). Need 2 Support (current: 1)."
        ],
        "shifts": [
          {
            "shift_name": "Shift 1 - Pagi (07:00-19:00)",
            "cns_count": 2,
            "support_count": 1,
            "total_count": 3,
            "is_valid": false
          },
          {
            "shift_name": "Shift 2 - Malam (19:00-07:00)",
            "cns_count": 4,
            "support_count": 2,
            "total_count": 6,
            "is_valid": true
          },
          {
            "shift_name": "Shift 3 - Full Day (00:00-23:59)",
            "cns_count": 4,
            "support_count": 2,
            "total_count": 6,
            "is_valid": true
          }
        ]
      }
    ],
    "errors": [
      "6 day(s) failed validation"
    ]
  }
}
```

**Validation Rules:**
1. ✅ Each day must have all 3 shifts filled
2. ✅ Each shift requires minimum 4 CNS + 2 Support employees
3. ✅ Each day requires minimum 1 Manager Teknik
4. ✅ All days in the month must have assignments

---

### 5. Publish Roster
**POST** `/rosters/{id}/publish`

Publish a draft roster to make it official. **Validation is automatically performed** - roster cannot be published if validation fails.

**Headers:**
```
Authorization: Bearer {access_token}
```

**Response (200 OK) - Success:**
```json
{
  "message": "Roster published successfully",
  "data": {
    "id": 1,
    "month": 1,
    "year": 2026,
    "status": "published",
    "updated_at": "2026-01-22T10:00:00.000000Z"
  },
  "validation": {
    "is_valid": true,
    "total_days": 31,
    "valid_days": 31,
    "invalid_days": [],
    "errors": []
  }
}
```

**Error Responses:**
- `400 Bad Request` - Roster is already published
- `422 Unprocessable Entity` - Roster validation failed (incomplete roster)

**Example 422 Response:**
```json
{
  "message": "Roster validation failed. Cannot publish incomplete roster.",
  "validation": {
    "is_valid": false,
    "total_days": 31,
    "valid_days": 20,
    "invalid_days": [...],
    "errors": [
      "11 day(s) failed validation"
    ]
  }
}
```

**Notes:**
- Use `GET /rosters/{id}/validate` first to preview validation results
- Cannot modify roster after it's published
- Activity log is created upon successful publish

- `404 Not Found` - Roster not found

---

### 5. Get Roster Day Details
**GET** `/rosters/{roster_id}/days/{day_id}`

Get detailed information for a specific roster day with all assignments.

**Headers:**
```
Authorization: Bearer {access_token}
```

**Response (200 OK):**
```json
{
  "id": 1,
  "roster_period_id": 1,
  "work_date": "2026-01-22",
  "created_at": "2026-01-01T00:00:00.000000Z",
  "updated_at": "2026-01-22T10:00:00.000000Z",
  "shift_assignments": [
    {
      "id": 1,
      "roster_day_id": 1,
      "employee_id": 5,
      "shift_id": 1,
      "created_at": "2026-01-22T10:00:00.000000Z",
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
        "shift_name": "Shift 1",
        "start_time": "07:00:00",
        "end_time": "19:00:00"
      }
    }
  ],
  "manager_duties": [
    {
      "id": 1,
      "roster_day_id": 1,
      "employee_id": 2,
      "duty_type": "Manager Teknik",
      "created_at": "2026-01-22T10:00:00.000000Z",
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
```

---

### 6. Add Day Assignments (Incremental)
**POST** `/rosters/{roster_id}/days/{day_id}/assignments`

Add shift assignments and/or manager duties **WITHOUT deleting existing assignments**. 

**✨ Key Features:**
- ✅ Add employees **one by one** (incremental assignment)
- ✅ Add multiple employees in batch
- ✅ Add only shift assignments OR only manager duty (both are optional)
- ✅ Build roster gradually by calling this endpoint multiple times

**⚠️ Important:** This endpoint does **NOT** replace existing assignments - it only adds new ones. Use PUT endpoint if you want to replace all assignments.

**Headers:**
```
Authorization: Bearer {access_token}
```

**Request Body Examples:**

**Example 1: Add Single Employee to Shift**
```json
{
  "shift_assignments": [
    {"employee_id": 5, "shift_id": 1}
  ]
}
```

**Example 2: Add Multiple Employees (Batch)**
```json
{
  "shift_assignments": [
    {"employee_id": 5, "shift_id": 1},
    {"employee_id": 6, "shift_id": 1},
    {"employee_id": 7, "shift_id": 1},
    {"employee_id": 8, "shift_id": 1}
  ]
}
```

**Example 3: Add Only Manager Duty**
```json
{
  "manager_duties": [
    {"employee_id": 2, "duty_type": "Manager Teknik"}
  ]
}
```

**Example 4: Add Both Shift Assignments and Manager**
```json
{
  "shift_assignments": [
    {"employee_id": 9, "shift_id": 1},
    {"employee_id": 10, "shift_id": 1}
  ],
  "manager_duties": [
    {"employee_id": 2, "duty_type": "Manager Teknik"}
  ]
}
```

**Validation Rules:**
- `shift_assignments`: optional, array
- `shift_assignments.*.employee_id`: required, must exist in employees table
- `shift_assignments.*.shift_id`: required, must exist in shifts table
- `manager_duties`: optional, array
- `manager_duties.*.employee_id`: required, must exist in employees table
- `manager_duties.*.duty_type`: required, must be "Manager Teknik" or "General Manager"

**Response (201 Created):**
```json
{
  "message": "Assignments added successfully",
  "data": {
    "id": 1,
    "roster_period_id": 1,
    "work_date": "2026-01-22",
    "shift_assignments": [...],
    "manager_duties": [...]
  },
  "validation": {
    "shift_1": {
      "shift_name": "Shift 1",
      "cns_count": 4,
      "support_count": 2,
      "total_count": 6,
      "valid": true,
      "message": "Valid: 4 CNS, 2 Support."
    },
    "shift_2": {
      "shift_name": "Shift 2",
      "cns_count": 0,
      "support_count": 0,
      "total_count": 0,
      "valid": false,
      "message": "Need at least 4 CNS and 2 Support. Current: 0 CNS, 0 Support."
    }
  }
}
```

**Notes:**
- This endpoint adds assignments without deleting existing ones
- Duplicate assignments are automatically prevented
- Returns validation summary for each shift
- Cannot modify published rosters

**Error Responses:**
- `400 Bad Request` - Roster is already published
- `422 Unprocessable Entity` - Validation failed
- `404 Not Found` - Roster or day not found

---

### 7. Update Day Assignments (Replace All)
**PUT** `/rosters/{roster_id}/days/{day_id}/assignments`

**⚠️ WARNING:** Replace **ALL** assignments for a roster day. This endpoint **DELETES** existing assignments and creates new ones.

**When to use:**
- 🔄 Replace entire day's assignments in one operation
- 🔄 Reset day to start fresh
- 🔄 Bulk update from external system

**When NOT to use:**
- ❌ Adding one employee (use POST instead)
- ❌ Adding to existing assignments (use POST instead)

**Headers:**
```
Authorization: Bearer {access_token}
```

**Request Body:**
```json
{
  "shift_assignments": [
    {
      "employee_id": 5,
      "shift_id": 1
    },
    {
      "employee_id": 6,
      "shift_id": 1
    },
    {
      "employee_id": 7,
      "shift_id": 1
    },
    {
      "employee_id": 8,
      "shift_id": 1
    },
    {
      "employee_id": 9,
      "shift_id": 1
    },
    {
      "employee_id": 10,
      "shift_id": 1
    },
    {
      "employee_id": 11,
      "shift_id": 2
    },
    {
      "employee_id": 12,
      "shift_id": 2
    },
    {
      "employee_id": 13,
      "shift_id": 2
    },
    {
      "employee_id": 14,
      "shift_id": 2
    },
    {
      "employee_id": 15,
      "shift_id": 2
    },
    {
      "employee_id": 16,
      "shift_id": 2
    }
  ],
  "manager_duties": [
    {
      "employee_id": 2,
      "duty_type": "Manager Teknik"
    },
    {
      "employee_id": 3,
      "duty_type": "General Manager"
    }
  ]
}
```

**Response (200 OK):**
```json
{
  "message": "Assignments updated successfully",
  "data": {
    "id": 1,
    "roster_period_id": 1,
    "work_date": "2026-01-22",
    "shift_assignments": [...],
    "manager_duties": [...]
  },
  "validation": {
    "shift_1": {
      "shift_name": "Shift 1",
      "cns_count": 4,
      "support_count": 2,
      "total_count": 6,
      "valid": true,
      "message": "Valid: 4 CNS, 2 Support."
    },
    "shift_2": {
      "shift_name": "Shift 2",
      "cns_count": 4,
      "support_count": 2,
      "total_count": 6,
      "valid": true,
      "message": "Valid: 4 CNS, 2 Support."
    }
  }
}
```

**Notes:**
- This endpoint replaces all assignments (DELETE then CREATE)
- Use this when you want to completely redefine the day's assignments
- Returns validation summary for each shift
- Cannot modify published rosters

**Error Responses:**
- `400 Bad Request` - Roster is already published
- `422 Unprocessable Entity` - Validation failed
- `404 Not Found` - Roster or day not found

---

### 8. Delete Assignment
**DELETE** `/rosters/{roster_id}/days/{day_id}/assignments/{assignment_id}`

Delete a specific shift assignment or manager duty.

**Headers:**
```
Authorization: Bearer {access_token}
```

**Response (200 OK):**
```json
{
  "message": "Assignment deleted successfully"
}
```

**Notes:**
- Can delete either shift assignments or manager duties
- The assignment_id can be from shift_assignments table or manager_duties table
- Cannot delete from published rosters

**Error Responses:**
- `400 Bad Request` - Roster is already published
- `404 Not Found` - Assignment not found

---

## Shift Requests

**Access:** All authenticated users

### 1. Create Shift Request
**POST** `/shift-requests`

Request to swap shifts with another employee.

**Headers:**
```
Authorization: Bearer {access_token}
```

**Request Body:**
```json
{
  "target_employee_id": 5,
  "from_roster_day_id": 10,
  "to_roster_day_id": 12,
  "shift_id": 1,
  "reason": "Personal emergency"
}
```

**Validation Rules:**
- `target_employee_id`: required, must exist in employees table
- `from_roster_day_id`: required, must exist in roster_days table
- `to_roster_day_id`: required, must exist in roster_days table
- `shift_id`: required, must exist in shifts table
- `reason`: optional, string

**Response (201 Created):**
```json
{
  "message": "Shift request created successfully",
  "data": {
    "id": 1,
    "requester_employee_id": 3,
    "target_employee_id": 5,
    "from_roster_day_id": 10,
    "to_roster_day_id": 12,
    "shift_id": 1,
    "reason": "Personal emergency",
    "status": "pending",
    "created_at": "2026-01-22T00:00:00.000000Z",
    "updated_at": "2026-01-22T00:00:00.000000Z"
  }
}
```

**Notes:**
- Requester must be an employee
- Target employee receives notification
- Status is set to "pending"

---

### 2. Approve Shift Request (Target)
**POST** `/shift-requests/{id}/approve-target`

Target employee approves the shift swap request.

**Headers:**
```
Authorization: Bearer {access_token}
```

**Response (200 OK):**
```json
{
  "message": "Shift request approved. Waiting for manager approval.",
  "data": {
    "id": 1,
    "status": "approved_by_target",
    "approved_by_target_at": "2026-01-22T10:00:00.000000Z"
  }
}
```

**Error Responses:**
- `403 Forbidden` - Not authorized to approve
- `400 Bad Request` - Request cannot be approved (not pending status)

---

### 3. Approve Shift Request (Manager)
**POST** `/shift-requests/{id}/approve-manager`

Manager approves the shift swap and executes the swap.

**Access:** Manager Teknik, General Manager

**Headers:**
```
Authorization: Bearer {access_token}
```

**Response (200 OK):**
```json
{
  "message": "Shift request approved and shifts swapped successfully",
  "data": {
    "id": 1,
    "status": "approved",
    "approved_by_manager_at": "2026-01-22T11:00:00.000000Z"
  }
}
```

**Notes:**
- Automatically swaps shift assignments in the database
- Both employees receive notifications
- Activity log is created

---

### 4. Reject Shift Request
**POST** `/shift-requests/{id}/reject`

Reject a shift swap request.

**Headers:**
```
Authorization: Bearer {access_token}
```

**Request Body:**
```json
{
  "rejection_reason": "Not enough staff on that day"
}
```

**Response (200 OK):**
```json
{
  "message": "Shift request rejected",
  "data": {
    "id": 1,
    "status": "rejected",
    "rejection_reason": "Not enough staff on that day",
    "rejected_at": "2026-01-22T10:30:00.000000Z"
  }
}
```

**Notes:**
- Can be rejected by target employee or manager
- Requester receives rejection notification

---

## Notifications

**Access:** All authenticated users

### 1. Get Notifications
**GET** `/notifications`

Get list of notifications for authenticated user.

**Headers:**
```
Authorization: Bearer {access_token}
```

**Query Parameters:**
- `is_read` (boolean, optional) - Filter by read status
- `per_page` (integer, optional, default: 20) - Items per page

**Example Request:**
```http
GET /notifications?is_read=false&per_page=10
```

**Response (200 OK):**
```json
{
  "current_page": 1,
  "data": [
    {
      "id": 1,
      "user_id": 3,
      "title": "Permintaan Tukar Shift",
      "message": "John Doe mengajukan tukar shift dengan Anda",
      "is_read": false,
      "created_at": "2026-01-22T09:00:00.000000Z",
      "updated_at": "2026-01-22T09:00:00.000000Z"
    }
  ],
  "per_page": 20,
  "total": 5
}
```

---

### 2. Mark Notification as Read
**POST** `/notifications/{id}/read`

Mark a notification as read.

**Headers:**
```
Authorization: Bearer {access_token}
```

**Response (200 OK):**
```json
{
  "message": "Notification marked as read",
  "data": {
    "id": 1,
    "is_read": true,
    "updated_at": "2026-01-22T10:00:00.000000Z"
  }
}
```

---

### 3. Create Notification
**POST** `/notifications/create`

Create a notification with optional email.

**Access:** Admin, General Manager

**Headers:**
```
Authorization: Bearer {access_token}
```

**Request Body:**
```json
{
  "user_id": 5,
  "title": "Important Announcement",
  "message": "Please check the new roster schedule",
  "send_email": true
}
```

**Validation Rules:**
- `user_id`: required, must exist in users table
- `title`: required, string, max 255 characters
- `message`: required, string
- `send_email`: optional, boolean (default: true)

**Response (201 Created):**
```json
{
  "message": "Notification created successfully",
  "data": {
    "id": 2,
    "user_id": 5,
    "title": "Important Announcement",
    "message": "Please check the new roster schedule",
    "is_read": false,
    "created_at": "2026-01-22T10:00:00.000000Z"
  }
}
```

---

### 4. Resend Notification Email
**POST** `/notifications/{id}/resend-email`

Resend notification email to user.

**Headers:**
```
Authorization: Bearer {access_token}
```

**Response (200 OK):**
```json
{
  "message": "Email sent successfully"
}
```

**Error Responses:**
- `500 Internal Server Error` - Failed to send email

---

## Activity Logs

**Access:** All authenticated users

### 1. Get Activity Logs
**GET** `/activity-logs`

Get activity logs with pagination and filters.

**Headers:**
```
Authorization: Bearer {access_token}
```

**Query Parameters:**
- `module` (string, optional) - Filter by module: `auth`, `user`, `roster`, `shift_request`, etc.
- `action` (string, optional) - Filter by action: `create`, `update`, `delete`, `login`, `logout`, etc.
- `user_id` (integer, optional) - Filter by user ID
- `date_from` (date, optional) - Filter from date (YYYY-MM-DD)
- `date_to` (date, optional) - Filter to date (YYYY-MM-DD)
- `search` (string, optional) - Search in description
- `per_page` (integer, optional, default: 10, max: 50) - Items per page

**Example Request:**
```http
GET /activity-logs?module=user&action=create&date_from=2026-01-01&per_page=20
```

**Response (200 OK):**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "user_id": 1,
      "action": "create",
      "module": "user",
      "reference_id": 5,
      "description": "Created user: john@example.com",
      "created_at": "2026-01-22T10:00:00.000000Z",
      "user": {
        "id": 1,
        "name": "Admin User",
        "email": "admin@example.com"
      }
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 5,
    "per_page": 10,
    "total": 50,
    "from": 1,
    "to": 10
  }
}
```

---

### 2. Get Recent Activities
**GET** `/activity-logs/recent`

Get last 10 recent activities.

**Headers:**
```
Authorization: Bearer {access_token}
```

**Response (200 OK):**
```json
{
  "success": true,
  "data": [
    {
      "id": 50,
      "user_id": 1,
      "action": "login",
      "module": "auth",
      "description": "User logged in",
      "created_at": "2026-01-22T10:00:00.000000Z",
      "user": {
        "id": 1,
        "name": "Admin User",
        "email": "admin@example.com"
      }
    }
  ]
}
```

---

### 3. Get Activity Statistics
**GET** `/activity-logs/statistics`

Get activity statistics summary.

**Headers:**
```
Authorization: Bearer {access_token}
```

**Response (200 OK):**
```json
{
  "success": true,
  "data": {
    "total_activities": 1250,
    "today_activities": 45,
    "week_activities": 320,
    "month_activities": 850,
    "by_module": {
      "auth": 350,
      "user": 200,
      "roster": 450,
      "shift_request": 150,
      "notification": 100
    },
    "by_action": {
      "create": 400,
      "update": 300,
      "delete": 50,
      "login": 300,
      "logout": 200
    }
  }
}
```

---

## Data Models

### User
```typescript
{
  id: number;
  name: string;
  email: string;
  role: "Admin" | "Cns" | "Support" | "Manager Teknik" | "General Manager";
  is_active: boolean;
  created_at: string;
  updated_at: string;
  deleted_at: string | null;
  employee?: Employee;
}
```

### Employee
```typescript
{
  id: number;
  user_id: number;
  employee_type: "Administrator" | "CNS" | "Support" | "Manager Teknik" | "General Manager";
  is_active: boolean;
  created_at: string;
  updated_at: string;
  deleted_at: string | null;
  user?: User;
}
```

### RosterPeriod
```typescript
{
  id: number;
  month: number; // 1-12
  year: number;
  status: "draft" | "published";
  created_at: string;
  updated_at: string;
  roster_days?: RosterDay[];
}
```

### RosterDay
```typescript
{
  id: number;
  roster_period_id: number;
  work_date: string; // YYYY-MM-DD
  created_at: string;
  updated_at: string;
  shift_assignments?: ShiftAssignment[];
  manager_duties?: ManagerDuty[];
}
```

### ShiftAssignment
```typescript
{
  id: number;
  roster_day_id: number;
  employee_id: number;
  shift_id: number;
  created_at: string;
  updated_at: string;
  employee?: Employee;
  shift?: Shift;
}
```

### Shift
```typescript
{
  id: number;
  shift_name: string; // "Shift 1", "Shift 2", "Shift 3"
  start_time: string; // HH:MM:SS
  end_time: string; // HH:MM:SS
  created_at: string;
  updated_at: string;
}
```

### ShiftRequest
```typescript
{
  id: number;
  requester_employee_id: number;
  target_employee_id: number;
  from_roster_day_id: number;
  to_roster_day_id: number;
  shift_id: number;
  reason: string | null;
  status: "pending" | "approved_by_target" | "approved" | "rejected";
  rejection_reason: string | null;
  approved_by_target_at: string | null;
  approved_by_manager_at: string | null;
  rejected_at: string | null;
  created_at: string;
  updated_at: string;
}
```

### Notification
```typescript
{
  id: number;
  user_id: number;
  title: string;
  message: string;
  is_read: boolean;
  created_at: string;
  updated_at: string;
}
```

### ActivityLog
```typescript
{
  id: number;
  user_id: number;
  action: string; // "create", "update", "delete", "login", "logout", etc.
  module: string; // "auth", "user", "roster", "shift_request", etc.
  reference_id: number | null;
  description: string;
  created_at: string;
  user?: User;
}
```

---

## Error Responses

### Standard Error Format
```json
{
  "message": "Error message here",
  "errors": {
    "field_name": ["Error message for this field"]
  }
}
```

### Common HTTP Status Codes
- `200 OK` - Successful request
- `201 Created` - Resource created successfully
- `400 Bad Request` - Invalid request
- `401 Unauthorized` - Authentication required
- `403 Forbidden` - Insufficient permissions
- `404 Not Found` - Resource not found
- `422 Unprocessable Entity` - Validation failed
- `429 Too Many Requests` - Rate limit exceeded
- `500 Internal Server Error` - Server error

---

## Rate Limiting

Certain endpoints have rate limiting to prevent abuse:

- **Generate Token**: 3 requests per minute per user
- **Send Activation Email**: 1 request per minute per user

When rate limit is exceeded, API returns:
```json
{
  "message": "Too Many Requests"
}
```
Status Code: `429 Too Many Requests`

---

## Notes

1. **Authentication**: All protected endpoints require `Authorization: Bearer {token}` header
2. **Timestamps**: All timestamps are in ISO 8601 format (UTC)
3. **Soft Deletes**: Users and employees use soft deletes (can be restored)
4. **Pagination**: Most list endpoints support pagination with `per_page` parameter
5. **Caching**: User list is cached for 5 minutes for performance
6. **Activity Logs**: Most actions automatically create activity logs for audit trail

---

## Postman Collection

You can import this API documentation into Postman for testing. Contact the development team for the Postman collection file.

---

**Last Updated:** January 22, 2026  
**Version:** 1.0.0  
**Contact:** development@atoms.com
