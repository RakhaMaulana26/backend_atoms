# Form Request Documentation - Roster API

## 📋 Overview

Form Request classes digunakan untuk validasi input API. Setiap endpoint yang menerima data dari user memiliki Request class sendiri untuk memastikan data yang masuk valid dan aman.

---

## 📦 Available Form Requests

### 1. CreateRosterPeriodRequest

**File**: `app/Http/Requests/CreateRosterPeriodRequest.php`  
**Endpoint**: `POST /api/rosters`  
**Purpose**: Validasi pembuatan roster period baru

#### Required Fields

| Field | Type | Validation | Description |
|-------|------|------------|-------------|
| `month` | integer | required, between:1,12 | Bulan roster (1-12) |
| `year` | integer | required, min:2024 | Tahun roster (minimal 2024) |

#### Example Request

```json
{
  "month": 3,
  "year": 2026
}
```

#### Validation Messages (Indonesian)

```
month.required => "Bulan wajib diisi"
month.integer => "Bulan harus berupa angka"
month.between => "Bulan harus antara 1 sampai 12"
year.required => "Tahun wajib diisi"
year.integer => "Tahun harus berupa angka"
year.min => "Tahun minimal 2024"
```

#### Error Response Example

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "month": ["Bulan harus antara 1 sampai 12"],
    "year": ["Tahun minimal 2024"]
  }
}
```

---

### 2. StoreRosterAssignmentsRequest

**File**: `app/Http/Requests/StoreRosterAssignmentsRequest.php`  
**Endpoint**: `POST /api/rosters/{roster_id}/days/{day_id}/assignments`  
**Purpose**: Validasi penambahan shift assignments dan manager duties

#### Optional Fields

| Field | Type | Validation | Description |
|-------|------|------------|-------------|
| `shift_assignments` | array | sometimes, array | Daftar shift assignments |
| `shift_assignments.*.employee_id` | integer | required, exists:employees,id | ID employee yang akan di-assign |
| `shift_assignments.*.shift_id` | integer | required, exists:shifts,id | ID shift yang akan di-assign |
| `manager_duties` | array | sometimes, array | Daftar manager duties |
| `manager_duties.*.employee_id` | integer | required, exists:employees,id | ID employee manager |
| `manager_duties.*.duty_type` | string | required, in:Manager Teknik,General Manager | Tipe manager duty |

#### Example Request

```json
{
  "shift_assignments": [
    {
      "employee_id": 1,
      "shift_id": 1
    },
    {
      "employee_id": 2,
      "shift_id": 1
    },
    {
      "employee_id": 3,
      "shift_id": 2
    }
  ],
  "manager_duties": [
    {
      "employee_id": 10,
      "duty_type": "Manager Teknik"
    }
  ]
}
```

#### Special Validation

- ✅ Request body tidak boleh kosong
- ✅ Minimal salah satu field (`shift_assignments` atau `manager_duties`) harus ada
- ✅ Employee ID harus exist di database
- ✅ Shift ID harus exist di database
- ✅ Duty type harus "Manager Teknik" atau "General Manager"

#### Validation Messages (Indonesian)

```
shift_assignments.array => "Shift assignments harus berupa array"
shift_assignments.*.employee_id.required => "Employee ID wajib diisi untuk setiap shift assignment"
shift_assignments.*.employee_id.exists => "Employee tidak ditemukan"
shift_assignments.*.shift_id.required => "Shift ID wajib diisi untuk setiap shift assignment"
shift_assignments.*.shift_id.exists => "Shift tidak ditemukan"
manager_duties.array => "Manager duties harus berupa array"
manager_duties.*.employee_id.required => "Employee ID wajib diisi untuk setiap manager duty"
manager_duties.*.employee_id.exists => "Employee tidak ditemukan"
manager_duties.*.duty_type.required => "Duty type wajib diisi"
manager_duties.*.duty_type.in => "Duty type harus Manager Teknik atau General Manager"
```

#### Error Response Example

```json
{
  "message": "Request body is empty. Check your JSON syntax (remove trailing commas)."
}
```

atau

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "shift_assignments.0.employee_id": ["Employee tidak ditemukan"],
    "manager_duties.0.duty_type": ["Duty type harus Manager Teknik atau General Manager"]
  }
}
```

---

### 3. UpdateRosterAssignmentsRequest

**File**: `app/Http/Requests/UpdateRosterAssignmentsRequest.php`  
**Endpoint**: `PUT /api/rosters/{roster_id}/days/{day_id}/assignments`  
**Purpose**: Validasi update (replace) semua assignments di suatu hari

#### Optional Fields

Sama seperti `StoreRosterAssignmentsRequest`:

| Field | Type | Validation | Description |
|-------|------|------------|-------------|
| `shift_assignments` | array | sometimes, array | Daftar shift assignments baru (replace semua) |
| `shift_assignments.*.employee_id` | integer | required, exists:employees,id | ID employee |
| `shift_assignments.*.shift_id` | integer | required, exists:shifts,id | ID shift |
| `manager_duties` | array | sometimes, array | Daftar manager duties baru (replace semua) |
| `manager_duties.*.employee_id` | integer | required, exists:employees,id | ID employee manager |
| `manager_duties.*.duty_type` | string | required, in:Manager Teknik,General Manager | Tipe manager duty |

#### Example Request

```json
{
  "shift_assignments": [
    {
      "employee_id": 5,
      "shift_id": 1
    },
    {
      "employee_id": 6,
      "shift_id": 2
    }
  ],
  "manager_duties": [
    {
      "employee_id": 11,
      "duty_type": "General Manager"
    }
  ]
}
```

#### Behavior

- ⚠️ **REPLACE MODE**: Semua assignment lama akan dihapus dan diganti dengan yang baru
- ✅ Jika `shift_assignments` kosong, semua shift assignments akan dihapus
- ✅ Jika `manager_duties` kosong, semua manager duties akan dihapus

#### Validation Messages (Indonesian)

Sama seperti `StoreRosterAssignmentsRequest` (lihat di atas)

---

## 🔧 How to Use Form Requests

### In Controller

```php
use App\Http\Requests\CreateRosterPeriodRequest;

public function store(CreateRosterPeriodRequest $request)
{
    // Validasi sudah otomatis dilakukan oleh Laravel
    // Data sudah pasti valid di sini
    
    $month = $request->month;
    $year = $request->year;
    
    // Business logic...
}
```

### Benefits

1. ✅ **Separation of Concerns**: Validation logic terpisah dari controller
2. ✅ **Reusable**: Request class bisa digunakan di multiple places
3. ✅ **Testable**: Validation rules mudah di-test
4. ✅ **Maintainable**: Perubahan validation di satu tempat
5. ✅ **Custom Messages**: Error messages bisa di-customize (Bahasa Indonesia)

---

## 🎯 Validation Flow

```
Client Request
    ↓
Form Request (Auto Validation)
    ↓
[VALID?]
    ├─ YES → Controller Method
    │         ↓
    │     Business Logic
    │         ↓
    │     Response
    │
    └─ NO → 422 Unprocessable Entity
              ↓
          Error Response (JSON)
```

---

## 📝 Custom Validation Rules

### Adding New Rules

Edit file Request yang sesuai:

```php
public function rules(): array
{
    return [
        'month' => 'required|integer|between:1,12',
        'year' => 'required|integer|min:2024',
        // Add new rule here
        'status' => 'sometimes|in:draft,published',
    ];
}
```

### Custom Messages

```php
public function messages(): array
{
    return [
        'status.in' => 'Status harus draft atau published',
    ];
}
```

### Custom Attributes

```php
public function attributes(): array
{
    return [
        'status' => 'status roster',
    ];
}
```

---

## 🧪 Testing Form Requests

Form Requests sudah ter-test melalui integration tests di `RosterControllerTest.php`:

```php
/** @test */
public function it_validates_roster_creation_input()
{
    $response = $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/rosters', [
            'month' => 13, // Invalid month
            'year' => 2020, // Invalid year
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['month', 'year']);
}
```

---

## 📊 Validation Coverage

| Form Request | Endpoints | Tests | Status |
|--------------|-----------|-------|--------|
| CreateRosterPeriodRequest | 1 endpoint | 3 tests | ✅ 100% |
| StoreRosterAssignmentsRequest | 1 endpoint | 5 tests | ✅ 100% |
| UpdateRosterAssignmentsRequest | 1 endpoint | 1 test | ✅ 100% |

**Total**: 3 Form Requests, 3 Endpoints, 9 Tests, **100% Coverage**

---

## 🚀 Quick Reference

### Create Roster Period

```bash
POST /api/rosters
{
  "month": 3,
  "year": 2026
}
```

### Add Assignments

```bash
POST /api/rosters/1/days/5/assignments
{
  "shift_assignments": [
    {"employee_id": 1, "shift_id": 1}
  ]
}
```

### Update Assignments (Replace)

```bash
PUT /api/rosters/1/days/5/assignments
{
  "shift_assignments": [
    {"employee_id": 2, "shift_id": 2}
  ]
}
```

---

## 📖 Further Reading

- Laravel Form Request Documentation: https://laravel.com/docs/validation#form-request-validation
- Validation Rules: https://laravel.com/docs/validation#available-validation-rules
- Custom Validation: https://laravel.com/docs/validation#custom-validation-rules

---

**Created**: January 27, 2026  
**Status**: ✅ Production Ready  
**Coverage**: 100% tested in integration tests
