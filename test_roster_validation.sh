#!/bin/bash

# ==============================================================================
# ATOMS Roster Validation Test Script
# ==============================================================================
# This script tests the complete roster validation workflow
# Including: Create, Assign, Validate, and Publish with validation checks
# ==============================================================================

# Configuration
BASE_URL="http://localhost:8000/api"
TOKEN=""
ROSTER_ID=""
EMPLOYEE_IDS=()
SHIFT_IDS=(1 2 3)

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# ==============================================================================
# Helper Functions
# ==============================================================================

print_header() {
    echo -e "\n${BLUE}========================================${NC}"
    echo -e "${BLUE}$1${NC}"
    echo -e "${BLUE}========================================${NC}\n"
}

print_success() {
    echo -e "${GREEN}✓ $1${NC}"
}

print_error() {
    echo -e "${RED}✗ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠ $1${NC}"
}

print_info() {
    echo -e "${BLUE}ℹ $1${NC}"
}

# ==============================================================================
# Step 1: Login
# ==============================================================================
login() {
    print_header "Step 1: Login"
    
    read -p "Enter email: " email
    read -sp "Enter password: " password
    echo
    
    response=$(curl -s -X POST "${BASE_URL}/auth/login" \
        -H "Content-Type: application/json" \
        -d "{\"email\":\"$email\",\"password\":\"$password\"}")
    
    TOKEN=$(echo $response | jq -r '.access_token')
    
    if [ "$TOKEN" != "null" ] && [ ! -z "$TOKEN" ]; then
        print_success "Login successful"
        print_info "Token: ${TOKEN:0:20}..."
    else
        print_error "Login failed"
        echo $response | jq '.'
        exit 1
    fi
}

# ==============================================================================
# Step 2: Create Roster Template
# ==============================================================================
create_roster() {
    print_header "Step 2: Create Roster Template"
    
    read -p "Enter month (1-12): " month
    read -p "Enter year (e.g., 2025): " year
    
    response=$(curl -s -X POST "${BASE_URL}/rosters" \
        -H "Authorization: Bearer $TOKEN" \
        -H "Content-Type: application/json" \
        -d "{\"month\":$month,\"year\":$year}")
    
    ROSTER_ID=$(echo $response | jq -r '.data.id')
    
    if [ "$ROSTER_ID" != "null" ] && [ ! -z "$ROSTER_ID" ]; then
        print_success "Roster created with ID: $ROSTER_ID"
        
        total_days=$(echo $response | jq '.data.roster_days | length')
        print_info "Generated $total_days days"
    else
        print_error "Failed to create roster"
        echo $response | jq '.'
        exit 1
    fi
}

# ==============================================================================
# Step 3: Get Available Employees
# ==============================================================================
get_employees() {
    print_header "Step 3: Get Available Employees"
    
    response=$(curl -s -X GET "${BASE_URL}/admin/users" \
        -H "Authorization: Bearer $TOKEN")
    
    # Parse employees by type
    echo -e "\n${YELLOW}CNS Employees:${NC}"
    echo $response | jq -r '.data[] | select(.employee.employee_type == "CNS") | "ID: \(.employee.id) - \(.name)"'
    
    echo -e "\n${YELLOW}Support Employees:${NC}"
    echo $response | jq -r '.data[] | select(.employee.employee_type == "Support") | "ID: \(.employee.id) - \(.name)"'
    
    echo -e "\n${YELLOW}Manager Teknik:${NC}"
    echo $response | jq -r '.data[] | select(.employee.employee_type == "Manager Teknik") | "ID: \(.employee.id) - \(.name)"'
}

# ==============================================================================
# Step 4: Assign Employees to a Single Day (Example)
# ==============================================================================
assign_single_day() {
    print_header "Step 4: Assign Employees to Day"
    
    read -p "Enter day ID to assign: " day_id
    
    # Get CNS employees (need 4 per shift)
    print_info "Enter 4 CNS employee IDs for Shift 1 (space-separated):"
    read -a cns_shift1
    
    print_info "Enter 2 Support employee IDs for Shift 1 (space-separated):"
    read -a support_shift1
    
    print_info "Enter 1 Manager Teknik employee ID:"
    read manager_id
    
    # Build JSON
    assignments='{"shift_assignments":['
    
    # Add CNS for shift 1
    for emp_id in "${cns_shift1[@]}"; do
        assignments+="{\"employee_id\":$emp_id,\"shift_id\":1},"
    done
    
    # Add Support for shift 1
    for emp_id in "${support_shift1[@]}"; do
        assignments+="{\"employee_id\":$emp_id,\"shift_id\":1},"
    done
    
    # Remove trailing comma and close array
    assignments=${assignments%,}
    assignments+='],"manager_duties":[{"employee_id":'$manager_id',"duty_type":"Manager Teknik"}]}'
    
    echo -e "\nSending request..."
    
    response=$(curl -s -X POST "${BASE_URL}/rosters/${ROSTER_ID}/days/${day_id}/assignments" \
        -H "Authorization: Bearer $TOKEN" \
        -H "Content-Type: application/json" \
        -d "$assignments")
    
    echo $response | jq '.'
    
    if [ "$(echo $response | jq -r '.message')" != "null" ]; then
        print_success "Assignments added successfully"
        
        # Show validation summary
        echo -e "\n${YELLOW}Validation Summary:${NC}"
        echo $response | jq '.validation'
    else
        print_error "Failed to assign employees"
    fi
}

# ==============================================================================
# Step 5: Auto-fill All Days (Quick Test)
# ==============================================================================
auto_fill_roster() {
    print_header "Step 5: Auto-fill All Days (Test Mode)"
    
    print_warning "This will assign the same employees to ALL days for testing"
    read -p "Continue? (y/n): " confirm
    
    if [ "$confirm" != "y" ]; then
        return
    fi
    
    # Get roster details to get all day IDs
    response=$(curl -s -X GET "${BASE_URL}/rosters/${ROSTER_ID}" \
        -H "Authorization: Bearer $TOKEN")
    
    day_ids=$(echo $response | jq -r '.data.roster_days[].id')
    
    # Get employee IDs from user
    print_info "Enter 4 CNS employee IDs (space-separated):"
    read -a cns_ids
    
    print_info "Enter 2 Support employee IDs (space-separated):"
    read -a support_ids
    
    print_info "Enter 1 Manager Teknik employee ID:"
    read manager_id
    
    # Loop through all days
    for day_id in $day_ids; do
        print_info "Assigning day ID: $day_id"
        
        # Build assignments for all 3 shifts
        assignments='{"shift_assignments":['
        
        # For each shift (1, 2, 3)
        for shift_id in 1 2 3; do
            # Add CNS
            for emp_id in "${cns_ids[@]}"; do
                assignments+="{\"employee_id\":$emp_id,\"shift_id\":$shift_id},"
            done
            
            # Add Support
            for emp_id in "${support_ids[@]}"; do
                assignments+="{\"employee_id\":$emp_id,\"shift_id\":$shift_id},"
            done
        done
        
        # Remove trailing comma
        assignments=${assignments%,}
        assignments+='],"manager_duties":[{"employee_id":'$manager_id',"duty_type":"Manager Teknik"}]}'
        
        # Send request
        curl -s -X POST "${BASE_URL}/rosters/${ROSTER_ID}/days/${day_id}/assignments" \
            -H "Authorization: Bearer $TOKEN" \
            -H "Content-Type: application/json" \
            -d "$assignments" > /dev/null
        
        echo -n "."
    done
    
    echo
    print_success "All days filled with assignments"
}

# ==============================================================================
# Step 6: Validate Roster Before Publish
# ==============================================================================
validate_roster() {
    print_header "Step 6: Validate Roster Before Publish"
    
    response=$(curl -s -X GET "${BASE_URL}/rosters/${ROSTER_ID}/validate" \
        -H "Authorization: Bearer $TOKEN")
    
    is_valid=$(echo $response | jq -r '.validation.is_valid')
    total_days=$(echo $response | jq -r '.validation.total_days')
    valid_days=$(echo $response | jq -r '.validation.valid_days')
    invalid_count=$(echo $response | jq -r '.validation.invalid_days | length')
    
    echo -e "\n${YELLOW}Validation Results:${NC}"
    echo "Total Days: $total_days"
    echo "Valid Days: $valid_days"
    echo "Invalid Days: $invalid_count"
    
    if [ "$is_valid" == "true" ]; then
        print_success "Roster is VALID and ready to publish!"
    else
        print_error "Roster is INVALID - cannot publish"
        
        echo -e "\n${YELLOW}Invalid Days Details:${NC}"
        echo $response | jq '.validation.invalid_days[] | {date: .date, errors: .errors}'
    fi
    
    # Full response
    echo -e "\n${YELLOW}Full Validation Response:${NC}"
    echo $response | jq '.'
}

# ==============================================================================
# Step 7: Publish Roster
# ==============================================================================
publish_roster() {
    print_header "Step 7: Publish Roster"
    
    print_warning "This will publish the roster and lock it from further edits"
    read -p "Continue? (y/n): " confirm
    
    if [ "$confirm" != "y" ]; then
        return
    fi
    
    response=$(curl -s -X POST "${BASE_URL}/rosters/${ROSTER_ID}/publish" \
        -H "Authorization: Bearer $TOKEN")
    
    echo $response | jq '.'
    
    message=$(echo $response | jq -r '.message')
    
    if [[ "$message" == *"successfully"* ]]; then
        print_success "Roster published successfully!"
    else
        print_error "Failed to publish roster"
        
        # Show validation errors if any
        echo -e "\n${YELLOW}Validation Errors:${NC}"
        echo $response | jq '.validation.invalid_days[] | {date: .date, errors: .errors}'
    fi
}

# ==============================================================================
# Step 8: Try to Modify Published Roster (Should Fail)
# ==============================================================================
test_modify_published() {
    print_header "Step 8: Test Modifying Published Roster (Should Fail)"
    
    # Get first day ID
    response=$(curl -s -X GET "${BASE_URL}/rosters/${ROSTER_ID}" \
        -H "Authorization: Bearer $TOKEN")
    
    first_day_id=$(echo $response | jq -r '.data.roster_days[0].id')
    
    print_info "Attempting to modify day ID: $first_day_id"
    
    # Try to add assignment
    response=$(curl -s -X POST "${BASE_URL}/rosters/${ROSTER_ID}/days/${first_day_id}/assignments" \
        -H "Authorization: Bearer $TOKEN" \
        -H "Content-Type: application/json" \
        -d '{"shift_assignments":[{"employee_id":1,"shift_id":1}]}')
    
    echo $response | jq '.'
    
    message=$(echo $response | jq -r '.message')
    
    if [[ "$message" == *"Cannot modify published roster"* ]]; then
        print_success "Correctly prevented modification of published roster"
    else
        print_error "Unexpected: Should not allow modification"
    fi
}

# ==============================================================================
# Main Menu
# ==============================================================================
main_menu() {
    while true; do
        print_header "ATOMS Roster Validation Test Menu"
        echo "1. Login"
        echo "2. Create Roster Template"
        echo "3. View Available Employees"
        echo "4. Assign Single Day (Manual)"
        echo "5. Auto-fill All Days (Quick Test)"
        echo "6. Validate Roster"
        echo "7. Publish Roster"
        echo "8. Test Modifying Published Roster"
        echo "9. Full Automated Test"
        echo "0. Exit"
        echo
        read -p "Select option: " choice
        
        case $choice in
            1) login ;;
            2) create_roster ;;
            3) get_employees ;;
            4) assign_single_day ;;
            5) auto_fill_roster ;;
            6) validate_roster ;;
            7) publish_roster ;;
            8) test_modify_published ;;
            9) full_test ;;
            0) exit 0 ;;
            *) print_error "Invalid option" ;;
        esac
    done
}

# ==============================================================================
# Full Automated Test
# ==============================================================================
full_test() {
    print_header "Running Full Automated Test"
    
    if [ -z "$TOKEN" ]; then
        login
    fi
    
    create_roster
    auto_fill_roster
    validate_roster
    publish_roster
    test_modify_published
    
    print_success "Full test completed!"
}

# ==============================================================================
# Start Script
# ==============================================================================
clear
echo "================================================================"
echo "  ATOMS Roster Validation Test Script"
echo "================================================================"
echo "  This script tests roster validation features:"
echo "  - Create monthly roster"
echo "  - Assign employees (4 CNS + 2 Support per shift)"
echo "  - Assign Manager Teknik per day"
echo "  - Validate before publish"
echo "  - Publish with validation enforcement"
echo "================================================================"
echo

main_menu
