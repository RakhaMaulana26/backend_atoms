# Update: Admin Users API - Employee Type Filter

## Ringkasan Perubahan
Endpoint `/api/admin/users` telah diupdate untuk mendukung filter berdasarkan `employee_type` (CNS, SUPPORT, MANAGER) dan menampilkan semua jenis karyawan dengan lebih lengkap.

## Perubahan Backend

### AdminUserController.php
**Lokasi:** `backend_atoms/app/Http/Controllers/Api/AdminUserController.php`

#### Method: `index()`
**Perubahan:**
1. ✅ Menambahkan filter `employee_type` 
2. ✅ Load relasi `employee` dengan `withTrashed()`
3. ✅ Menambahkan ordering berdasarkan `created_at DESC`
4. ✅ Menambahkan `employee_type` langsung ke response untuk kemudahan frontend
5. ✅ Improved query dengan `filled()` check untuk parameter yang tidak kosong

**Query Parameters Supported:**
- `search` - Cari berdasarkan name atau email
- `role` - Filter berdasarkan role (admin, cns, support, manager, gm)
- `employee_type` - Filter berdasarkan tipe karyawan (CNS, SUPPORT, MANAGER) ⭐ BARU
- `is_active` - Filter berdasarkan status aktif
- `per_page` - Limit pagination (default: 15)

## Perubahan Frontend

### UsersPage.tsx
**Lokasi:** `frontend_atoms/src/modules/admin/pages/UsersPage.tsx`

**Perubahan:**
1. ✅ Menambahkan state `selectedEmployeeType`
2. ✅ Menambahkan dropdown filter "All Employee Types" dengan opsi CNS, SUPPORT, MANAGER
3. ✅ Update `fetchUsers()` untuk mengirim parameter `employee_type`
4. ✅ UI sekarang menampilkan 3 filter: Search, Role, Employee Type

### adminService.ts
**Lokasi:** `frontend_atoms/src/modules/admin/repository/adminService.ts`

**Perubahan:**
1. ✅ Update interface `getUsers()` untuk accept parameter `employee_type`

## Cara Penggunaan

### API Endpoint Examples:

1. **Menampilkan semua user:**
   ```
   GET http://localhost:8000/api/admin/users
   ```

2. **Filter hanya CNS employees:**
   ```
   GET http://localhost:8000/api/admin/users?employee_type=CNS
   ```

3. **Filter hanya SUPPORT employees:**
   ```
   GET http://localhost:8000/api/admin/users?employee_type=SUPPORT
   ```

4. **Filter hanya MANAGER employees:**
   ```
   GET http://localhost:8000/api/admin/users?employee_type=MANAGER
   ```

5. **Kombinasi filter (search + employee_type):**
   ```
   GET http://localhost:8000/api/admin/users?search=john&employee_type=CNS
   ```

6. **Filter by role dan employee_type:**
   ```
   GET http://localhost:8000/api/admin/users?role=cns&employee_type=CNS
   ```

### UI Filter Usage:

Di halaman User Management (`/admin/users`), sekarang ada 3 filter:
1. **Search Box** - Cari berdasarkan nama atau email
2. **Role Dropdown** - Filter: All Roles, Admin, Manager, GM, CNS, Support
3. **Employee Type Dropdown** - Filter: All Employee Types, CNS, SUPPORT, MANAGER ⭐ BARU

## Response Format

```json
{
  "current_page": 1,
  "data": [
    {
      "id": 1,
      "name": "John Doe",
      "email": "john@example.com",
      "role": "cns",
      "is_active": true,
      "employee_type": "CNS",  // ⭐ Ditambahkan untuk kemudahan
      "employee": {
        "id": 1,
        "user_id": 1,
        "employee_type": "CNS",
        "is_active": true,
        "created_at": "2026-01-15T08:00:00.000000Z",
        "updated_at": "2026-01-15T08:00:00.000000Z",
        "deleted_at": null
      },
      "created_at": "2026-01-15T08:00:00.000000Z",
      "updated_at": "2026-01-15T08:00:00.000000Z",
      "deleted_at": null
    }
  ],
  "per_page": 15,
  "total": 1
}
```

## Testing

### Backend Test:
```bash
# Test dengan Postman atau curl
curl -X GET "http://localhost:8000/api/admin/users?employee_type=CNS" \
     -H "Authorization: Bearer YOUR_TOKEN"
```

### Frontend Test:
1. Login sebagai admin
2. Navigate ke `/admin/users`
3. Gunakan dropdown "All Employee Types" untuk filter
4. Pilih CNS, SUPPORT, atau MANAGER
5. Klik "Search" untuk apply filter

## Employee Types Available:
- **CNS** - Communication Navigation Surveillance
- **SUPPORT** - Support Staff
- **MANAGER** - Manager

## Benefits:
✅ Mudah filter karyawan berdasarkan tipe
✅ Kombinasi filter lebih fleksibel (search + role + employee_type)
✅ UI lebih intuitif dengan dropdown terpisah
✅ Response lebih informatif dengan employee_type di level user
✅ Query lebih efisien dengan `filled()` check

## Notes:
- Filter `employee_type` hanya berlaku untuk user yang memiliki relasi dengan tabel `employees`
- User dengan role `admin` atau `gm` tidak memiliki `employee_type` (akan menampilkan null)
- Semua filter bersifat optional, kosongkan untuk menampilkan semua
- Default ordering: newest first (created_at DESC)
