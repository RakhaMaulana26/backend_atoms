#!/bin/bash

# ============================================================
# ROSTER TASK API - TEST SCRIPT
# ============================================================
# Usage: ./test_roster_tasks.sh
# atau copy-paste individual curl commands ke terminal
# ============================================================

# Configuration
BASE_URL="http://localhost:8000/api"
ADMIN_TOKEN="YOUR_ADMIN_JWT_TOKEN"
MANAGER_TOKEN="YOUR_MANAGER_JWT_TOKEN"
STAFF_TOKEN="YOUR_STAFF_JWT_TOKEN"

echo "🚀 Starting Roster Task API Tests..."
echo "Base URL: $BASE_URL"
echo ""

# ============================================================
# TEST 1: List All Tasks (No Filter)
# ============================================================
echo "📋 TEST 1: GET all roster tasks"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
curl -X GET "$BASE_URL/roster/tasks" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Accept: application/json" \
  -w "\nHTTP Status: %{http_code}\n\n"

# ============================================================
# TEST 2: List Tasks for Specific Date & Shift (Pagi)
# ============================================================
echo "📅 TEST 2: GET tasks for Pagi shift (2026-03-26)"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
curl -X GET "$BASE_URL/roster/tasks?date=2026-03-26&shift_key=07-13" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Accept: application/json" \
  -w "\nHTTP Status: %{http_code}\n\n"

# ============================================================
# TEST 3: Create Task for Pagi Shift (MANAGER)
# ============================================================
echo "✨ TEST 3: CREATE task for Pagi shift"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
curl -X POST "$BASE_URL/roster/tasks" \
  -H "Authorization: Bearer $MANAGER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "date": "2026-03-26",
    "shift_key": "07-13",
    "role": "CNS",
    "assigned_to": [5, 7],
    "title": "Perbaikan AC area B",
    "description": "AC tidak dingin di ruang server, perlu dicek refrigeran dan compressor",
    "priority": "high",
    "status": "pending"
  }' \
  -w "\nHTTP Status: %{http_code}\n\n"

# ============================================================
# TEST 4: Create Task for Siang Shift (ADMIN)
# ============================================================
echo "✨ TEST 4: CREATE task for Siang shift (ITDept)"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
curl -X POST "$BASE_URL/roster/tasks" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "date": "2026-03-26",
    "shift_key": "13-19",
    "role": "IT Support",
    "assigned_to": [3, 8, 10],
    "title": "Update antivirus semua workstation",
    "description": "Install latest antivirus patches dan run full scan",
    "priority": "medium",
    "status": "pending"
  }' \
  -w "\nHTTP Status: %{http_code}\n\n"

# ============================================================
# TEST 5: Create Task for Malam Shift (SECURITY)
# ============================================================
echo "✨ TEST 5: CREATE task for Malam shift (Security)"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
curl -X POST "$BASE_URL/roster/tasks" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "date": "2026-03-27",
    "shift_key": "19-07",
    "role": "Security",
    "assigned_to": [2, 4, 6],
    "title": "Patrol dan pemeriksaan CCTV",
    "description": "Jaga area building, monitoring via CCTV, cek semua pintu dan akses point",
    "priority": "high",
    "status": "pending"
  }' \
  -w "\nHTTP Status: %{http_code}\n\n"

# ============================================================
# TEST 6: Get Task Detail (for ID 1)
# ============================================================
echo "🔍 TEST 6: GET single task detail (ID: 1)"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
curl -X GET "$BASE_URL/roster/tasks/1" \
  -H "Authorization: Bearer $STAFF_TOKEN" \
  -H "Accept: application/json" \
  -w "\nHTTP Status: %{http_code}\n\n"

# ============================================================
# TEST 7: Staff Update Task Status to IN_PROGRESS
# ============================================================
echo "🔄 TEST 7: UPDATE task status (pending → in_progress)"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
curl -X PUT "$BASE_URL/roster/tasks/1" \
  -H "Authorization: Bearer $STAFF_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "status": "in_progress"
  }' \
  -w "\nHTTP Status: %{http_code}\n\n"

# ============================================================
# TEST 8: Staff Mark Task as COMPLETED
# ============================================================
echo "✅ TEST 8: UPDATE task status (in_progress → completed)"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
curl -X PUT "$BASE_URL/roster/tasks/1" \
  -H "Authorization: Bearer $STAFF_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "status": "completed"
  }' \
  -w "\nHTTP Status: %{http_code}\n\n"

# ============================================================
# TEST 9: Get Tasks Assigned to Specific User (ID: 5)
# ============================================================
echo "👤 TEST 9: GET tasks assigned to user ID 5"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
curl -X GET "$BASE_URL/roster/tasks?assigned_to=5" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Accept: application/json" \
  -w "\nHTTP Status: %{http_code}\n\n"

# ============================================================
# TEST 10: Get Pending Tasks Only
# ============================================================
echo "⏳ TEST 10: GET only PENDING tasks"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
curl -X GET "$BASE_URL/roster/tasks?status=pending" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Accept: application/json" \
  -w "\nHTTP Status: %{http_code}\n\n"

# ============================================================
# TEST 11: Get High Priority Tasks
# ============================================================
echo "🔴 TEST 11: GET only HIGH priority tasks"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
curl -X GET "$BASE_URL/roster/tasks?priority=high" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Accept: application/json" \
  -w "\nHTTP Status: %{http_code}\n\n"

# ============================================================
# TEST 12: Error Case - Non-Manager Try to Create Task
# ============================================================
echo "❌ TEST 12: Try create task as STAFF (should fail 403)"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
curl -X POST "$BASE_URL/roster/tasks" \
  -H "Authorization: Bearer $STAFF_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "date": "2026-03-26",
    "shift_key": "07-13",
    "role": "CNS",
    "assigned_to": [5],
    "title": "Test task",
    "priority": "medium"
  }' \
  -w "\nHTTP Status: %{http_code}\n\n"

# ============================================================
# TEST 13: Error Case - Invalid Shift Key
# ============================================================
echo "❌ TEST 13: Create task with INVALID shift_key (should fail 422)"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
curl -X POST "$BASE_URL/roster/tasks" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "date": "2026-03-26",
    "shift_key": "99-99",
    "role": "CNS",
    "assigned_to": [5],
    "title": "Test task"
  }' \
  -w "\nHTTP Status: %{http_code}\n\n"

# ============================================================
# TEST 14: Error Case - No Assigned Users
# ============================================================
echo "❌ TEST 14: Create task with EMPTY assigned_to (should fail 422)"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
curl -X POST "$BASE_URL/roster/tasks" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "date": "2026-03-26",
    "shift_key": "07-13",
    "role": "CNS",
    "assigned_to": [],
    "title": "Test task"
  }' \
  -w "\nHTTP Status: %{http_code}\n\n"

# ============================================================
# TEST 15: Check Notifications for Roster
# ============================================================
echo "🔔 TEST 15: GET roster notifications"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
curl -X GET "$BASE_URL/notifications?category=roster" \
  -H "Authorization: Bearer $STAFF_TOKEN" \
  -H "Accept: application/json" \
  -w "\nHTTP Status: %{http_code}\n\n"

echo ""
echo "✅ All tests completed!"
echo "═══════════════════════════════════════════════════════"
