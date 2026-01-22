#!/bin/bash

# Test Script: Roster Assignment Workflow
# Tests incremental assignment (one by one) and batch assignment

API_BASE="http://localhost:8000/api"
TOKEN="1|your_access_token_here"  # Replace with your actual token

echo "========================================"
echo "Roster Assignment Workflow Test"
echo "========================================"
echo ""

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# 1. Create a test roster
echo -e "${YELLOW}1. Creating test roster for January 2026...${NC}"
ROSTER_RESPONSE=$(curl -s -X POST "$API_BASE/rosters" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "month": 1,
    "year": 2026
  }')

ROSTER_ID=$(echo $ROSTER_RESPONSE | jq -r '.data.id')
echo -e "${GREEN}✅ Roster created with ID: $ROSTER_ID${NC}"
echo ""

# 2. Add single CNS employee to Shift 1
echo -e "${YELLOW}2. Adding single CNS employee (ID 1) to Day 1, Shift 1...${NC}"
curl -s -X POST "$API_BASE/rosters/$ROSTER_ID/days/1/assignments" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "shift_assignments": [
      {"employee_id": 1, "shift_id": 1}
    ]
  }' | jq '.message, .validation'
echo ""

# 3. Add another CNS employee to Shift 1
echo -e "${YELLOW}3. Adding another CNS employee (ID 2) to Day 1, Shift 1...${NC}"
curl -s -X POST "$API_BASE/rosters/$ROSTER_ID/days/1/assignments" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "shift_assignments": [
      {"employee_id": 2, "shift_id": 1}
    ]
  }' | jq '.message, .validation'
echo ""

# 4. Add 2 more CNS employees (batch)
echo -e "${YELLOW}4. Adding 2 more CNS employees (batch) to Day 1, Shift 1...${NC}"
curl -s -X POST "$API_BASE/rosters/$ROSTER_ID/days/1/assignments" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "shift_assignments": [
      {"employee_id": 3, "shift_id": 1},
      {"employee_id": 4, "shift_id": 1}
    ]
  }' | jq '.message, .validation'
echo ""

# 5. Add 2 Support employees
echo -e "${YELLOW}5. Adding 2 Support employees to Day 1, Shift 1...${NC}"
curl -s -X POST "$API_BASE/rosters/$ROSTER_ID/days/1/assignments" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "shift_assignments": [
      {"employee_id": 11, "shift_id": 1},
      {"employee_id": 12, "shift_id": 1}
    ]
  }' | jq '.message, .validation'
echo ""

# 6. Add Manager Teknik
echo -e "${YELLOW}6. Adding Manager Teknik to Day 1...${NC}"
curl -s -X POST "$API_BASE/rosters/$ROSTER_ID/days/1/assignments" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "manager_duties": [
      {"employee_id": 7, "duty_type": "Manager Teknik"}
    ]
  }' | jq '.message, .validation'
echo ""

# 7. Complete Shift 2
echo -e "${YELLOW}7. Adding all employees to Day 1, Shift 2 (batch)...${NC}"
curl -s -X POST "$API_BASE/rosters/$ROSTER_ID/days/1/assignments" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "shift_assignments": [
      {"employee_id": 5, "shift_id": 2},
      {"employee_id": 6, "shift_id": 2},
      {"employee_id": 7, "shift_id": 2},
      {"employee_id": 8, "shift_id": 2},
      {"employee_id": 13, "shift_id": 2},
      {"employee_id": 14, "shift_id": 2}
    ]
  }' | jq '.message, .validation'
echo ""

# 8. Complete Shift 3
echo -e "${YELLOW}8. Adding all employees to Day 1, Shift 3 (batch)...${NC}"
curl -s -X POST "$API_BASE/rosters/$ROSTER_ID/days/1/assignments" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "shift_assignments": [
      {"employee_id": 9, "shift_id": 3},
      {"employee_id": 10, "shift_id": 3},
      {"employee_id": 20, "shift_id": 3},
      {"employee_id": 21, "shift_id": 3},
      {"employee_id": 15, "shift_id": 3},
      {"employee_id": 16, "shift_id": 3}
    ]
  }' | jq '.message, .validation'
echo ""

# 9. Get Day 1 details
echo -e "${YELLOW}9. Fetching Day 1 details to verify all assignments...${NC}"
curl -s -X GET "$API_BASE/rosters/$ROSTER_ID/days/1" \
  -H "Authorization: Bearer $TOKEN" | jq '{
    work_date: .data.work_date,
    total_shift_assignments: (.data.shift_assignments | length),
    shift_1_count: (.data.shift_assignments | map(select(.shift_id == 1)) | length),
    shift_2_count: (.data.shift_assignments | map(select(.shift_id == 2)) | length),
    shift_3_count: (.data.shift_assignments | map(select(.shift_id == 3)) | length),
    manager_duties_count: (.data.manager_duties | length)
  }'
echo ""

# 10. Validate roster before publish
echo -e "${YELLOW}10. Validating Day 1 completeness...${NC}"
curl -s -X GET "$API_BASE/rosters/$ROSTER_ID/validate" \
  -H "Authorization: Bearer $TOKEN" | jq '.data.days[] | select(.work_date | startswith("2026-01-01"))'
echo ""

# Summary
echo "========================================"
echo -e "${GREEN}✅ Test Complete!${NC}"
echo "========================================"
echo ""
echo "Expected Results:"
echo "- Shift 1: 6 employees (4 CNS + 2 Support)"
echo "- Shift 2: 6 employees (4 CNS + 2 Support)"
echo "- Shift 3: 6 employees (4 CNS + 2 Support)"
echo "- Manager Teknik: 1 person"
echo "- Day 1 validation: COMPLETE"
echo ""
echo "Verify above output matches these expectations."
echo ""
echo "To test with your token:"
echo "1. Replace TOKEN variable at top of script"
echo "2. Run: bash test_assignment_workflow.sh"
