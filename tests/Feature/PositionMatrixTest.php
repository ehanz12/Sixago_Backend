<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Project;
use App\Models\Role;
use App\Models\TravelPolicy;
use App\Models\User;
use App\Support\Positions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Matriks posisi: grade ↔ role ↔ atasan langsung ↔ kepala divisi.
 *
 * Sebelum ada Positions, keempatnya berdiri sendiri-sendiri: grade ngawol
 * diterima, role ngawol diterima, dan manager boleh menunjuk siapa saja.
 */
class PositionMatrixTest extends TestCase
{
    use RefreshDatabase;

    private User $sa;
    private User $lm;
    private User $dh;
    private User $finance;
    private User $employee;
    private Department $dept;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Super Admin', 'Finance Approver', 'Division Head', 'Line Manager', 'Employee'] as $name) {
            Role::create(['name' => $name, 'permissions' => $name === 'Super Admin' ? ['*'] : []]);
        }

        $this->dept = Department::create([
            'code' => 'CC-TECH-502', 'name' => 'IT', 'budget_total' => 1000000, 'sla' => 'Tier 1',
        ]);

        $make = fn (string $email, string $role, string $grade, ?int $manager = null) => User::create([
            'name' => $email, 'nik' => 'NIK-'.$email, 'email' => $email,
            'password' => Hash::make('password123'), 'department_id' => $this->dept->id,
            'grade' => $grade, 'role' => $role, 'status' => 'Aktif', 'manager_id' => $manager,
        ]);

        $this->sa = $make('sa@x.id', 'Super Admin', 'M2');
        $this->lm = $make('lm@x.id', 'Line Manager', 'M3');
        $this->employee = $make('emp@x.id', 'Employee', 'P4');
        $this->dh = $make('dh@x.id', 'Division Head', 'M3');
        $this->finance = $make('fin@x.id', 'Finance Approver', 'M2');
        $this->employee->update(['manager_id' => $this->lm->id]);
        $this->dept->update(['leader_id' => $this->dh->id]);
    }

    // ============ Positions (unit) ============

    public function test_positions_rejects_unknown_role_and_grade(): void
    {
        $this->assertFalse(Positions::isKnownRole('Finance'));
        $this->assertFalse(Positions::isKnownGrade('P9'));
        $this->assertTrue(Positions::isKnownRole('Finance Approver'));
        $this->assertTrue(Positions::isKnownGrade('M3'));
    }

    public function test_positions_grade_range_per_role(): void
    {
        // Division Head minimal M1.
        $this->assertFalse(Positions::roleAllowsGrade('Division Head', 'P2'));
        $this->assertTrue(Positions::roleAllowsGrade('Division Head', 'M1'));
        $this->assertTrue(Positions::roleAllowsGrade('Division Head', 'M3'));

        // Employee maksimal P5.
        $this->assertTrue(Positions::roleAllowsGrade('Employee', 'P1'));
        $this->assertFalse(Positions::roleAllowsGrade('Employee', 'M1'));

        // Line Manager minimal P4.
        $this->assertFalse(Positions::roleAllowsGrade('Line Manager', 'P3'));
        $this->assertTrue(Positions::roleAllowsGrade('Line Manager', 'M3'));
    }

    public function test_positions_manager_requirement(): void
    {
        $this->assertTrue(Positions::requiresManager('Employee'));
        $this->assertFalse(Positions::requiresManager('Division Head'));
        $this->assertFalse(Positions::requiresManager('Super Admin'));
    }

    public function test_positions_detects_manager_cycle(): void
    {
        $a = User::create([
            'name' => 'a', 'nik' => 'NIK-a', 'email' => 'a@x.id', 'password' => Hash::make('x'),
            'department_id' => $this->dept->id, 'grade' => 'P1', 'role' => 'Employee', 'status' => 'Aktif',
        ]);
        $b = User::create([
            'name' => 'b', 'nik' => 'NIK-b', 'email' => 'b@x.id', 'password' => Hash::make('x'),
            'department_id' => $this->dept->id, 'grade' => 'P4', 'role' => 'Line Manager', 'status' => 'Aktif',
            'manager_id' => $a->id,
        ]);
        // a → b → a (loop 2 hop).
        $a->update(['manager_id' => $b->id]);

        $this->assertTrue(Positions::inspectManagerChain($b->fresh(), (int) $a->id)['cycle']);
        $this->assertFalse(Positions::inspectManagerChain($this->lm->fresh(), (int) $a->id)['cycle']);
    }

    public function test_positions_grade_coverage_reflects_policies(): void
    {
        $coverage = Positions::gradeCoverage();
        $this->assertSame([], $coverage['covered']);
        $this->assertCount(8, $coverage['uncovered']);

        TravelPolicy::create([
            'code' => 'POL-P', 'grade' => 'P3 - P5', 'transport_plafon' => '1000000',
            'hotel_plafon' => '100000', 'per_diem' => '10000', 'local_transport' => '5000',
            'sla' => '1', 'region' => 'Domestik', 'enabled' => true,
        ]);

        $coverage = Positions::gradeCoverage();
        $this->assertSame(['P3', 'P4', 'P5'], $coverage['covered']);
        $this->assertContains('M1', $coverage['uncovered']);
    }

    // ============ Endpoint store (integration) ============

    private function payload(array $over = []): array
    {
        return array_merge([
            'name' => 'Orang', 'nik' => 'NIK-' . uniqid(), 'email' => uniqid().'@x.id',
            'password' => 'password123', 'role' => 'Employee', 'grade' => 'P3',
            'manager_id' => $this->lm->id,
        ], $over);
    }

    public function test_store_rejects_grade_outside_role_range(): void
    {
        Sanctum::actingAs($this->sa);
        $this->postJson('/api/users', $this->payload(['role' => 'Division Head', 'grade' => 'P2']))
            ->assertStatus(422)->assertJsonValidationErrors('grade');
    }

    public function test_store_rejects_unknown_grade_and_role(): void
    {
        Sanctum::actingAs($this->sa);
        $this->postJson('/api/users', $this->payload(['grade' => 'Z9']))
            ->assertStatus(422)->assertJsonValidationErrors('grade');

        $this->postJson('/api/users', $this->payload(['role' => 'Finance']))
            ->assertStatus(422)->assertJsonValidationErrors('role');
    }

    public function test_store_accepts_valid_combination(): void
    {
        Sanctum::actingAs($this->sa);
        $this->postJson('/api/users', $this->payload(['role' => 'Division Head', 'grade' => 'M1']))
            ->assertCreated();
    }

    public function test_store_rejects_manager_that_is_self_or_not_approver(): void
    {
        Sanctum::actingAs($this->sa);

        // Atasan bukan role approver.
        $this->postJson('/api/users', $this->payload([
            'manager_id' => $this->finance->fresh()->id,
            'role' => 'Finance Approver', 'grade' => 'M2',
        ]))->assertCreated(); // Finance memang approver-capable.

        $this->postJson('/api/users', $this->payload([
            'role' => 'Finance Approver', 'grade' => 'M2',
            'manager_id' => $this->employee->fresh()->id, // Employee bukan approver
        ]))->assertStatus(422)->assertJsonValidationErrors('manager_id');
    }

    public function test_store_rejects_inactive_manager(): void
    {
        $this->lm->update(['status' => 'Non-Aktif']);
        Sanctum::actingAs($this->sa);
        $this->postJson('/api/users', $this->payload(['manager_id' => $this->lm->fresh()->id]))
            ->assertStatus(422)->assertJsonValidationErrors('manager_id');
    }

    public function test_update_rejects_manager_cycle(): void
    {
        Sanctum::actingAs($this->sa);
        // Employees.manager = LM; set LM.manager = employee → loop.
        $this->putJson("/api/users/{$this->lm->id}", ['manager_id' => $this->employee->id])
            ->assertStatus(422)->assertJsonValidationErrors('manager_id');
    }

    public function test_update_rejects_grade_outside_new_role(): void
    {
        Sanctum::actingAs($this->sa);
        $this->putJson("/api/users/{$this->dh->id}", ['role' => 'Employee'])
            ->assertStatus(422)->assertJsonValidationErrors('grade');
    }

    // ============ Kepala Divisi ============

    public function test_department_leader_must_be_division_head(): void
    {
        Sanctum::actingAs($this->sa);
        $this->postJson('/api/departments', [
            'code' => 'CC-NEW-1', 'name' => 'Baru', 'sla' => 'Tier 2',
            'leader_id' => $this->lm->id, // Line Manager, bukan Division Head
        ])->assertStatus(422)->assertJsonValidationErrors('leader_id');
    }

    public function test_department_leader_must_belong_to_department(): void
    {
        $other = Department::create(['code' => 'CC-OTHER', 'name' => 'Lain', 'budget_total' => 1, 'sla' => 'T']);
        Sanctum::actingAs($this->sa);
        $this->patchJson("/api/departments/{$other->id}", ['leader_id' => $this->dh->id])
            ->assertStatus(422)->assertJsonValidationErrors('leader_id');
    }

    public function test_department_accepts_super_admin_as_leader(): void
    {
        $other = Department::create(['code' => 'CC-OTHER2', 'name' => 'Lain2', 'budget_total' => 1, 'sla' => 'T']);
        Sanctum::actingAs($this->sa);
        $this->patchJson("/api/departments/{$other->id}", ['leader_id' => $this->sa->id])
            ->assertOk();
    }

    // ============ Endpoint grade-coverage ============

    public function test_grade_coverage_endpoint(): void
    {
        TravelPolicy::create([
            'code' => 'POL-X', 'grade' => 'P3 - P5', 'transport_plafon' => '1000000',
            'hotel_plafon' => '100000', 'per_diem' => '10000', 'local_transport' => '5000',
            'sla' => '1', 'region' => 'Domestik', 'enabled' => true,
        ]);

        Sanctum::actingAs($this->sa);
        $res = $this->getJson('/api/travel-policies/grade-coverage')->assertOk()->json();

        $this->assertSame(['P3', 'P4', 'P5'], $res['covered']);
        $this->assertContains('P1', $res['uncovered']);
        $this->assertCount(8, $res['grades']);
    }
}