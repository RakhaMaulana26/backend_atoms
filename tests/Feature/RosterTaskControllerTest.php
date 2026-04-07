<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\RosterTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;

class RosterTaskControllerTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create([
            'role' => User::ROLE_MANAGER_TEKNIK,
            'is_active' => true,
        ]);
    }

    /** @test */
    public function it_creates_task_and_returns_consistent_shape()
    {
        $assignee = User::factory()->create(['role' => User::ROLE_CNS, 'is_active' => true]);

        $payload = [
            'date' => now()->addDay()->toDateString(),
            'shift_key' => '19-07',
            'role' => 'CNS',
            'assigned_to' => [$assignee->id],
            'title' => 'Periksa inventaris',
            'description' => 'cek stok obat',
            'priority' => 'high',
            'status' => 'pending',
        ];

        $response = $this->actingAs($this->manager, 'sanctum')->postJson('/api/roster/tasks', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.id', fn($id) => is_numeric($id))
            ->assertJsonPath('data.shift_key', '19-07')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.assigned_to', [$assignee->id])
            ->assertJsonPath('data.created_by', $this->manager->id)
            ->assertJsonStructure(['data' => ['id', 'date', 'shift_key', 'role', 'assigned_to', 'title', 'description', 'priority', 'status', 'created_by', 'created_at', 'updated_at']]);
    }

    /** @test */
    public function it_creates_task_with_inprogress_and_done_status_variants()
    {
        $assignee = User::factory()->create(['role' => User::ROLE_SUPPORT, 'is_active' => true]);

        $payload = [
            'date' => now()->addDay()->toDateString(),
            'shift_key' => '07-13',
            'role' => 'Support',
            'assigned_to' => [$assignee->id],
            'title' => 'Check sistem',
            'priority' => 'high',
            'status' => 'inProgress',
        ];

        $response = $this->actingAs($this->manager, 'sanctum')->postJson('/api/roster/tasks', $payload);
        $response->assertStatus(201)->assertJsonPath('data.status', 'inProgress');

        $taskId = $response->json('data.id');

        $response2 = $this->actingAs($this->manager, 'sanctum')->putJson("/api/roster/tasks/{$taskId}", ['status' => 'done']);
        $response2->assertStatus(200)->assertJsonPath('data.status', 'done');
    }

    /** @test */
    public function it_lists_tasks_with_filters_date_shift_role_and_assigned_to()
    {
        $userA = User::factory()->create(['role' => User::ROLE_CNS, 'is_active' => true]);
        $userB = User::factory()->create(['role' => User::ROLE_SUPPORT, 'is_active' => true]);

        RosterTask::create([
            'date' => now()->toDateString(),
            'shift_key' => '07-13',
            'role' => 'CNS',
            'assigned_to' => [$userA->id],
            'title' => 'Tugas A',
            'description' => 'desc',
            'priority' => 'medium',
            'status' => 'pending',
            'created_by' => $this->manager->id,
        ]);

        RosterTask::create([ // direct model fillable provides now
            'date' => now()->toDateString(),
            'shift_key' => '13-19',
            'role' => 'Support',
            'assigned_to' => [$userB->id],
            'title' => 'Tugas B',
            'description' => 'desc',
            'priority' => 'low',
            'status' => 'in_progress',
            'created_by' => $this->manager->id,
        ]);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/roster/tasks?date=' . now()->toDateString() . '&shift=07-13&role=CNS&assigned_to=' . $userA->id);

        $response->assertStatus(200);

        $json = $response->json('data');
        $this->assertCount(1, $json);
        $this->assertEquals('CNS', $json[0]['role']);
        $this->assertEquals('07-13', $json[0]['shift_key']);
        $this->assertEquals('pending', $json[0]['status']);
        $this->assertEquals([$userA->id], $json[0]['assigned_to']);
    }

    /** @test */
    public function it_updates_task_status_to_done_and_returns_mapped_status()
    {
        $assignee = User::factory()->create(['role' => User::ROLE_CNS, 'is_active' => true]);

        $task = RosterTask::create([
            'date' => now()->toDateString(),
            'shift_key' => '13-19',
            'role' => 'CNS',
            'assigned_to' => [$assignee->id],
            'title' => 'Tugas update',
            'description' => 'desc',
            'priority' => 'medium',
            'status' => 'pending',
            'created_by' => $this->manager->id,
        ]);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->putJson("/api/roster/tasks/{$task->id}", ['status' => 'done', 'assigned_to' => [$assignee->id]]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.id', $task->id);
    }

    /** @test */
    public function it_generates_roster_tasks_when_roster_is_published()
    {
        $rosterPeriod = \App\Models\RosterPeriod::factory()->create(['status' => 'draft']);
        $rosterDay = \App\Models\RosterDay::factory()->create(['roster_period_id' => $rosterPeriod->id, 'work_date' => now()->addDay()->toDateString()]);

        $managerEmployee = \App\Models\Employee::factory()->create(['employee_type' => \App\Models\Employee::TYPE_MANAGER_TEKNIK]);
        $managerUser = $managerEmployee->user;

        $shift = \App\Models\Shift::factory()->create(['name' => 'pagi', 'start_time' => '07:00:00', 'end_time' => '13:00:00']);

        \App\Models\ManagerDuty::factory()->create([
            'roster_day_id' => $rosterDay->id,
            'employee_id' => $managerEmployee->id,
            'duty_type' => 'Manager Teknik',
            'shift_id' => $shift->id,
        ]);

        $response = $this->actingAs($this->manager, 'sanctum')->postJson("/api/rosters/{$rosterPeriod->id}/publish?skip_validation=1");
        $response->assertStatus(200);

        $this->assertDatabaseHas('roster_tasks', [
            'date' => $rosterDay->work_date,
            'shift_key' => '07-13',
            'role' => 'Manager Teknik',
            'created_by' => $this->manager->id,
        ]);

        $task = \App\Models\RosterTask::whereDate('date', $rosterDay->work_date)->where('shift_key', '07-13')->first();

        $this->assertNotNull($task);
        $this->assertContains($managerUser->id, $task->assigned_to);
    }
}

