# Debugging Guide - Notification 404 Error & Scheduled Notifications

## Masalah
API endpoint `PUT /api/notifications/{id}/read` mengembalikan error 404 (Not Found) saat mencoba menandai notifikasi sebagai sudah dibaca.

---

## ✅ SCHEDULED NOTIFICATIONS - IMPLEMENTED

### New Database Fields
```sql
ALTER TABLE notifications ADD COLUMN (
    scheduled_at DATETIME NULL,
    status ENUM('draft', 'pending', 'sent', 'failed') DEFAULT 'draft',
    error_message TEXT NULL,
    recipient_ids JSON NULL
);
```

### New API Endpoints
- `POST /api/notifications/save-scheduled` - Save scheduled notification
- `GET /api/notifications/scheduled` - Get user's scheduled notifications
- `PUT /api/notifications/scheduled/{id}` - Update scheduled notification
- `DELETE /api/notifications/scheduled/{id}` - Delete scheduled notification

### Scheduler Setup
- Command: `php artisan notifications:process-scheduled`
- Runs every 5 minutes via Laravel scheduler
- Processes notifications where `scheduled_at <= now()` and `status = 'pending'`

### Testing Scheduled Notifications

#### 1. Create Test Scheduled Notification
```bash
curl -X POST "http://localhost:8000/api/notifications/debug/create-test-scheduled" \
  -H "Authorization: Bearer TOKEN"
```

#### 2. Check Scheduled Notifications
```bash
curl -X GET "http://localhost:8000/api/notifications/scheduled" \
  -H "Authorization: Bearer TOKEN"
```

#### 3. Process Scheduled Notifications Manually
```bash
php artisan notifications:process-scheduled
```

#### 4. Run Scheduler Manually
```bash
php artisan schedule:run
```

---

## Penyebab Error 404

Error 404 ini bukan karena route backend salah. Saya sudah cek:

- `routes/api.php` memiliki:
  - `Route::match(['put', 'post'], '/{id}/read', [NotificationController::class, 'markAsRead']);`
- `php artisan route:list --path=notifications` mengonfirmasi:
  - `PUT|POST  api/notifications/{id}/read`

Jadi backend menerima `PUT /api/notifications/{id}/read` dengan benar.

---

## Kenapa masih 404?

Karena ID notifikasi yang dipanggil tidak ada di database.

Saya cek database lokal:
- `Total notifications in database: 139`
- `Max notification ID: 168`
- `Notification ID 2000010 NOT FOUND`

Jadi request `PUT /api/notifications/2000010/read` akan selalu 404 di environment ini, karena tidak ada record notifikasi dengan ID tersebut.

---

## Solusi

1. Pastikan frontend menggunakan ID notifikasi yang valid dari response `/api/notifications`
2. Jangan pakai ID lama atau ID dari environment lain
3. Jika perlu, gunakan debug endpoint ini:
   - `GET /api/notifications/debug/2000010`
   - atau buat notifikasi test dengan `POST /api/notifications/debug/create-test`

---

## Ringkas

- Route backend sudah benar
- Method `markAsRead` sudah sesuai
- Error terjadi karena `notification_id` tidak ada di database

Kalau mau, saya bantu lagi cek data apa yang dikirim dari frontend sehingga ID-nya jadi `2000004`.

## Penyebab Kemungkinan

1. **Notifikasi dengan ID tersebut tidak ada di database**
   - Notifikasi mungkin sudah dihapus
   - ID notifikasi salah
   - Notifikasi belum pernah dibuat

2. **User tidak memiliki akses ke notifikasi**
   - Notifikasi dikirim ke user lain
   - User bukan pemilik notifikasi (bukan user_id dan bukan sender_id)

3. **Notifikasi sudah di-delete (soft delete)**
   - Notifikasi berada di trash

## Cara Debugging

### 1. Cek apakah notifikasi ada
Gunakan debug endpoint untuk memeriksa status notifikasi:

```bash
curl -X GET "http://localhost:8000/api/notifications/debug/2000010" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

Response jika notifikasi tidak ditemukan:
```json
{
  "status": "not_found",
  "message": "Notification ID 2000010 does not exist in database",
  "requested_by_user": 1,
  "database_info": {
    "total_notifications": 45,
    "total_with_trashed": 50,
    "max_id": 1250
  }
}
```

Response jika user tidak punya akses:
```json
{
  "status": "forbidden",
  "notification": { ... },
  "access_info": {
    "requested_by_user": 1,
    "notification_user_id": 5,
    "notification_sender_id": 3,
    "has_access": false,
    "is_deleted": false
  }
}
```

### 2. Buat test notification untuk testing
Jika tidak ada notifikasi untuk testing, gunakan endpoint ini:

```bash
curl -X POST "http://localhost:8000/api/notifications/debug/create-test" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

Response:
```json
{
  "message": "Test notification created successfully",
  "notification": {
    "id": 1251,
    "user_id": 1,
    "title": "Test Notification - 2026-04-06 10:30:45",
    "message": "This is a test notification for debugging purposes.",
    "is_read": false,
    "created_at": "2026-04-06T10:30:45.000000Z"
  },
  "endpoint_to_test": "/api/notifications/1251/read",
  "instructions": [
    "Try marking this notification as read using the endpoint shown above",
    "URL: PUT /api/notifications/1251/read"
  ]
}
```

### 3. Test mark as read dengan notifikasi yang baru dibuat
Gunakan ID dari test notification untuk test endpoint mark as read:

```bash
curl -X PUT "http://localhost:8000/api/notifications/1251/read" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

Response jika sukses:
```json
{
  "message": "Notification marked as read",
  "data": {
    "id": 1251,
    "is_read": true,
    "read_at": "2026-04-06T10:30:50.000000Z"
  }
}
```

## Solusi Berdasarkan Error

### Jika error: "notification_not_found"
Kemungkinan:
- ✗ ID notifikasi salah
- ✗ Notifikasi sudah dihapus dari database
- ✗ Notifikasi belum pernah dibuat

**Solusi:**
1. Verifikasi ID notifikasi yang benar
2. Di frontend, gunakan ID dari `getAll()` atau `index()` endpoint
3. Jika perlu, buat test notification baru dengan `debug/create-test`

### Jika error: "unauthorized_notification_access"
Kemungkinan:
- ✓ Notifikasi ada di database
- ✗ User saat ini bukan pemilik notifikasi

**Solusi:**
1. Pastikan user yang login adalah pemilik notifikasi (user_id atau sender_id)
2. Cek authentification token benar
3. Jika multi-user, pastikan request pakai token user yang tepat

## Frontend Integration

### Di NotificationsPage.tsx
Sebelum call markAsRead, pastikan ID notifikasi valid:

```typescript
// Verifikasi notifikasi ada sebelum mark as read
const notification = notifications.find(n => n.id === notificationId);
if (!notification) {
  console.error('Notification not found locally');
  return;
}

// Kemudian baru call API
const result = await notificationService.markAsRead(notificationId);
```

### Di notificationService.ts
Tambahkan error handling yang lebih baik:

```typescript
async markAsRead(id: number) {
  try {
    const response = await this.api.put(
      `/api/notifications/${id}/read`
    );
    return response.data;
  } catch (error) {
    if (error.response?.status === 404) {
      console.error('Notifikasi tidak ditemukan di server');
    } else if (error.response?.status === 403) {
      console.error('Anda tidak memiliki akses ke notifikasi ini');
    }
    throw error;
  }
}
```

## Checking Logs

Lihat Laravel logs untuk detailed error messages:

```bash
tail -f storage/logs/laravel.log
```

Cari entry dengan format:
```
[2026-04-06 10:30:45] local.WARNING: Notification not found: ID 2000010 requested by user 1
```

## Database Query untuk Manual Check

Jika ingin direct query ke database:

```sql
-- Check jika notification ada
SELECT * FROM notifications WHERE id = 2000010;

-- Check total notifications
SELECT COUNT(*) FROM notifications;

-- Check notifications untuk user tertentu
SELECT id, title, user_id, sender_id, is_read, deleted_at 
FROM notifications 
WHERE user_id = 1 OR sender_id = 1 
ORDER BY created_at DESC;

-- Check deleted notifications (soft delete)
SELECT id, title, user_id, deleted_at 
FROM notifications 
WHERE id = 2000010 
OR (user_id = 1 OR sender_id = 1) AND deleted_at IS NOT NULL;
```

## Quick Checklist

- [ ] Verifikasi ID notifikasi dengan `/debug/{id}` endpoint
- [ ] Pastikan user yang login adalah pemilik notifikasi
- [ ] Cek Laravel logs untuk detail error
- [ ] Test dengan notification baru yang dibuat via `/debug/create-test`
- [ ] Verifikasi database connection bekerja
- [ ] Cek apakah migrations sudah dijalankan dengan `php artisan migrate:status`

## Next Steps

Jika masalah masih berlanjut:
1. Jalankan `php artisan migrate:refresh` (hati-hati: akan menghapus semua data)
2. Seed database dengan test data
3. Test endpoint debug untuk memastikan logic bekerja
4. Check network tab di DevTools untuk request/response yang detail
