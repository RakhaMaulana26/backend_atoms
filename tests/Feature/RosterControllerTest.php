<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Employee;
use App\Models\RosterPeriod;
use App\Models\RosterDay;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ManagerDuty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\DB;

class RosterControllerTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $user;
    protected $employee;

    protected function setUp(): void
    {
        parent::setUp();

        // Rollback any active transactions
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        // Create test user with Manager role
        $this->user = User::factory()->create([
            'role' => 'Manager Teknik',
            'is_active' => true,
        ]);

        // Create corresponding employee
        $this->employee = Employee::factory()->create([
            'user_id' => $this->user->id,
            'employee_type' => Employee::TYPE_MANAGER_TEKNIK,
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        // Clean up any remaining transactions
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        
        parent::tearDown();
    }

    /** @test */
    public function it_can_list_all_rosters()
    {
        // Create test rosters
        RosterPeriod::factory()->count(3)->create();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/rosters');

        $response->assertStatus(200)
            ->assertJsonCount(3);
    }

    /** @test */
    public function it_can_filter_rosters_by_month_and_year()
    {
        RosterPeriod::factory()->create(['month' => 1, 'year' => 2026]);
        RosterPeriod::factory()->create(['month' => 2, 'year' => 2026]);
        RosterPeriod::factory()->create(['month' => 1, 'year' => 2025]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/rosters?month=1&year=2026');

        $response->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonFragment(['month' => 1, 'year' => 2026]);
    }

    /** @test */
    public function it_can_create_roster_period()
    {
        $data = [
            'month' => 3,
            'year' => 2026,
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/rosters', $data);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'month' => 3,
                'year' => 2026,
                'status' => 'draft',
            ]);

        // Verify roster days were auto-generated
        $roster = RosterPeriod::first();
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, 3, 2026);
        $this->assertEquals($daysInMonth, $roster->rosterDays()->count());
    }

    /** @test */
    public function it_prevents_duplicate_roster_period()
    {
        RosterPeriod::factory()->create(['month' => 3, 'year' => 2026]);

        $data = [
            'month' => 3,
            'year' => 2026,
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/rosters', $data);

        $response->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Roster period already exists for this month and year'
            ]);
    }

    /** @test */
    public function it_validates_roster_creation_input()
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/rosters', [
                'month' => 13, // Invalid month
                'year' => 2020, // Invalid year (too old)
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['month', 'year']);
    }

    /** @test */
    public function it_can_show_roster_with_relationships()
    {
        $roster = RosterPeriod::factory()->create();
        $day = RosterDay::factory()->create([
            'roster_period_id' => $roster->id,
        ]);

        // Create shift and assignments
        $shift = Shift::factory()->create();
        $cnsEmployee = Employee::factory()->create(['employee_type' => Employee::TYPE_CNS]);
        
        ShiftAssignment::factory()->create([
            'roster_day_id' => $day->id,
            'employee_id' => $cnsEmployee->id,
            'shift_id' => $shift->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/rosters/{$roster->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'id',
                'month',
                'year',
                'status',
                'roster_days' => [
                    '*' => [
                        'id',
                        'work_date',
                        'shift_assignments' => [
                            '*' => [
                                'employee' => ['user'],
                                'shift',
                            ]
                        ],
                        'manager_duties',
                    ]
                ]
            ]);
    }

    /** @test */
    public function it_can_show_specific_roster_day()
    {
        $roster = RosterPeriod::factory()->create();
        $day = RosterDay::factory()->create([
            'roster_period_id' => $roster->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/rosters/{$roster->id}/days/{$day->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'id',
                'work_date',
                'shift_assignments',
                'manager_duties',
            ]);
    }

    /** @test */
    public function it_can_return_roster_today_with_shift_periods_and_assignments()
    {
        $roster = RosterPeriod::factory()->create(['status' => 'published', 'month' => now()->month, 'year' => now()->year]);
        $day = RosterDay::factory()->create(['roster_period_id' => $roster->id, 'work_date' => now()->toDateString()]);

        $shift = Shift::factory()->create(['name' => 'pagi', 'start_time' => '07:00:00', 'end_time' => '13:00:00']);
        $teamMember = Employee::factory()->create(['employee_type' => Employee::TYPE_CNS]);

        ShiftAssignment::factory()->create([
            'roster_day_id' => $day->id,
            'employee_id' => $teamMember->id,
            'shift_id' => $shift->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/roster/today');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'date',
                'shift_periods' => [
                    '*' => ['key', 'name', 'start', 'end'],
                ],
                'assignments' => [
                    '*' => ['id', 'user_id', 'shift_id', 'shift_key', 'employee_name', 'status', 'assigned_at'],
                ],
            ]);

        $this->assertCount(3, $response->json('shift_periods'));
        $this->assertNotEmpty($response->json('assignments'));
    }

    /** @test */
    public function it_can_add_shift_assignments_to_roster_day()
    {
        $roster = RosterPeriod::factory()->create(['status' => 'draft']);
        $day = RosterDay::factory()->create(['roster_period_id' => $roster->id]);
        
        $shift = Shift::factory()->create();
        $cnsEmployee = Employee::factory()->create(['employee_type' => Employee::TYPE_CNS]);
        $supportEmployee = Employee::factory()->create(['employee_type' => Employee::TYPE_SUPPORT]);

        $data = [
            'shift_assignments' => [
                ['employee_id' => $cnsEmployee->id, 'shift_id' => $shift->id],
                ['employee_id' => $supportEmployee->id, 'shift_id' => $shift->id],
            ],
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/rosters/{$roster->id}/days/{$day->id}/assignments", $data);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'data',
                'validation',
                'summary' => ['added', 'skipped'],
            ]);

        $this->assertEquals(2, ShiftAssignment::where('roster_day_id', $day->id)->count());
    }

    /** @test */
    public function it_prevents_duplicate_shift_assignments()
    {
        $roster = RosterPeriod::factory()->create(['status' => 'draft']);
        $day = RosterDay::factory()->create(['roster_period_id' => $roster->id]);
        
        $shift = Shift::factory()->create();
        $employee = Employee::factory()->create(['employee_type' => Employee::TYPE_CNS]);

        // Create existing assignment
        ShiftAssignment::factory()->create([
            'roster_day_id' => $day->id,
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
        ]);

        $data = [
            'shift_assignments' => [
                ['employee_id' => $employee->id, 'shift_id' => $shift->id],
            ],
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/rosters/{$roster->id}/days/{$day->id}/assignments", $data);

        $response->assertStatus(201)
            ->assertJsonFragment(['skipped' => 1]);

        // Still only 1 assignment
        $this->assertEquals(1, ShiftAssignment::where('roster_day_id', $day->id)->count());
    }

    /** @test */
    public function it_auto_assigns_manager_duty_when_manager_is_assigned_to_shift()
    {
        $roster = RosterPeriod::factory()->create(['status' => 'draft']);
        $day = RosterDay::factory()->create(['roster_period_id' => $roster->id]);
        
        $shift = Shift::factory()->create();
        $managerUser = User::factory()->create(['role' => 'Manager Teknik']);
        $managerEmployee = Employee::factory()->create([
            'user_id' => $managerUser->id,
            'employee_type' => Employee::TYPE_MANAGER_TEKNIK,
        ]);

        $data = [
            'shift_assignments' => [
                ['employee_id' => $managerEmployee->id, 'shift_id' => $shift->id],
            ],
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/rosters/{$roster->id}/days/{$day->id}/assignments", $data);

        $response->assertStatus(201);

        // Check both shift assignment and manager duty were created
        $this->assertEquals(1, ShiftAssignment::where('roster_day_id', $day->id)->count());
        $this->assertEquals(1, ManagerDuty::where('roster_day_id', $day->id)->count());
    }

    /** @test */
    public function it_prevents_modifications_to_published_roster()
    {
        $roster = RosterPeriod::factory()->create(['status' => 'published']);
        $day = RosterDay::factory()->create(['roster_period_id' => $roster->id]);
        
        $shift = Shift::factory()->create();
        $employee = Employee::factory()->create(['employee_type' => Employee::TYPE_CNS]);

        $data = [
            'shift_assignments' => [
                ['employee_id' => $employee->id, 'shift_id' => $shift->id],
            ],
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/rosters/{$roster->id}/days/{$day->id}/assignments", $data);

        $response->assertStatus(400)
            ->assertJsonFragment(['message' => 'Cannot modify published roster']);
    }

    /** @test */
    public function it_can_update_roster_day_assignments()
    {
        $roster = RosterPeriod::factory()->create(['status' => 'draft']);
        $day = RosterDay::factory()->create(['roster_period_id' => $roster->id]);
        
        $shift = Shift::factory()->create();
        $employee1 = Employee::factory()->create(['employee_type' => Employee::TYPE_CNS]);
        $employee2 = Employee::factory()->create(['employee_type' => Employee::TYPE_CNS]);

        // Create initial assignment
        ShiftAssignment::factory()->create([
            'roster_day_id' => $day->id,
            'employee_id' => $employee1->id,
            'shift_id' => $shift->id,
        ]);

        // Update with new assignment
        $data = [
            'shift_assignments' => [
                ['employee_id' => $employee2->id, 'shift_id' => $shift->id],
            ],
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/rosters/{$roster->id}/days/{$day->id}/assignments", $data);

        $response->assertStatus(200);

        // Old assignment should be replaced
        $this->assertEquals(1, ShiftAssignment::where('roster_day_id', $day->id)->count());
        $this->assertEquals($employee2->id, ShiftAssignment::where('roster_day_id', $day->id)->first()->employee_id);
    }

    /** @test */
    public function it_can_delete_shift_assignment()
    {
        $roster = RosterPeriod::factory()->create(['status' => 'draft']);
        $day = RosterDay::factory()->create(['roster_period_id' => $roster->id]);
        
        $shift = Shift::factory()->create();
        $employee = Employee::factory()->create(['employee_type' => Employee::TYPE_CNS]);
        
        $assignment = ShiftAssignment::factory()->create([
            'roster_day_id' => $day->id,
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/rosters/{$roster->id}/days/{$day->id}/assignments/{$assignment->id}");

        $response->assertStatus(200);
        $this->assertEquals(0, ShiftAssignment::where('roster_day_id', $day->id)->count());
    }

    /** @test */
    public function it_can_validate_roster_before_publish()
    {
        $roster = RosterPeriod::factory()->create(['status' => 'draft']);
        $day = RosterDay::factory()->create(['roster_period_id' => $roster->id]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/rosters/{$roster->id}/validate");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'validation' => [
                    'is_valid',
                    'total_days',
                    'valid_days',
                    'invalid_days',
                    'errors',
                ]
            ]);
    }

    /** @test */
    public function it_fails_validation_when_roster_is_incomplete()
    {
        // Create 3 shifts
        Shift::factory()->create(['name' => 'Shift 1']);
        Shift::factory()->create(['name' => 'Shift 2']);
        Shift::factory()->create(['name' => 'Shift 3']);

        $roster = RosterPeriod::factory()->create(['status' => 'draft']);
        $day = RosterDay::factory()->create(['roster_period_id' => $roster->id]);

        // Only add 2 CNS (need 4) and 1 Support (need 2)
        $shift = Shift::first();
        $cnsEmployee1 = Employee::factory()->create(['employee_type' => Employee::TYPE_CNS]);
        $cnsEmployee2 = Employee::factory()->create(['employee_type' => Employee::TYPE_CNS]);
        $supportEmployee = Employee::factory()->create(['employee_type' => Employee::TYPE_SUPPORT]);

        ShiftAssignment::factory()->create(['roster_day_id' => $day->id, 'employee_id' => $cnsEmployee1->id, 'shift_id' => $shift->id]);
        ShiftAssignment::factory()->create(['roster_day_id' => $day->id, 'employee_id' => $cnsEmployee2->id, 'shift_id' => $shift->id]);
        ShiftAssignment::factory()->create(['roster_day_id' => $day->id, 'employee_id' => $supportEmployee->id, 'shift_id' => $shift->id]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/rosters/{$roster->id}/validate");

        $response->assertStatus(200)
            ->assertJsonFragment(['is_valid' => false]);
    }

    /** @test */
    public function it_can_publish_valid_roster()
    {
        // Create 3 shifts
        $shift1 = Shift::factory()->create(['name' => 'Shift 1']);
        $shift2 = Shift::factory()->create(['name' => 'Shift 2']);
        $shift3 = Shift::factory()->create(['name' => 'Shift 3']);

        $roster = RosterPeriod::factory()->create(['status' => 'draft', 'month' => 3, 'year' => 2026]);
        
        // Create only 1 day for faster test
        $day = RosterDay::factory()->create([
            'roster_period_id' => $roster->id,
            'work_date' => '2026-03-01',
        ]);

        // Add Manager Teknik
        $managerEmployee = Employee::factory()->create(['employee_type' => Employee::TYPE_MANAGER_TEKNIK]);
        ManagerDuty::factory()->create([
            'roster_day_id' => $day->id,
            'employee_id' => $managerEmployee->id,
            'duty_type' => 'Manager Teknik',
        ]);

        // Add valid assignments for each shift (4 CNS + 2 Support)
        foreach ([$shift1, $shift2, $shift3] as $shift) {
            for ($i = 0; $i < 4; $i++) {
                $cnsEmployee = Employee::factory()->create(['employee_type' => Employee::TYPE_CNS]);
                ShiftAssignment::factory()->create([
                    'roster_day_id' => $day->id,
                    'employee_id' => $cnsEmployee->id,
                    'shift_id' => $shift->id,
                ]);
            }
            
            for ($i = 0; $i < 2; $i++) {
                $supportEmployee = Employee::factory()->create(['employee_type' => Employee::TYPE_SUPPORT]);
                ShiftAssignment::factory()->create([
                    'roster_day_id' => $day->id,
                    'employee_id' => $supportEmployee->id,
                    'shift_id' => $shift->id,
                ]);
            }
        }

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/rosters/{$roster->id}/publish");

        $response->assertStatus(200)
            ->assertJsonFragment(['status' => 'published']);

        $this->assertEquals('published', $roster->fresh()->status);
    }

    /** @test */
    public function it_prevents_publishing_invalid_roster()
    {
        Shift::factory()->count(3)->create();

        $roster = RosterPeriod::factory()->create(['status' => 'draft']);
        $day = RosterDay::factory()->create(['roster_period_id' => $roster->id]);

        // No assignments added

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/rosters/{$roster->id}/publish");

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'Roster validation failed. Cannot publish incomplete roster.']);
    }

    /** @test */
    public function it_prevents_republishing_already_published_roster()
    {
        $roster = RosterPeriod::factory()->create(['status' => 'published']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/rosters/{$roster->id}/publish");

        $response->assertStatus(400)
            ->assertJsonFragment(['message' => 'Roster is already published']);
    }

    /** @test */
    public function it_requires_authentication()
    {
        $response = $this->getJson('/api/rosters');
        $response->assertStatus(401);
    }

    /** @test */
    public function it_validates_empty_request_body()
    {
        $roster = RosterPeriod::factory()->create(['status' => 'draft']);
        $day = RosterDay::factory()->create(['roster_period_id' => $roster->id]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/rosters/{$roster->id}/days/{$day->id}/assignments", []);

        $response->assertStatus(400)
            ->assertJsonFragment(['message' => 'Request body is empty. Check your JSON syntax (remove trailing commas).']);
    }
}
