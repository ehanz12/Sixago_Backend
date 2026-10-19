<?php

namespace Tests\Feature;

use App\Models\ApprovalQueue;
use App\Models\Department;
use App\Models\Project;
use App\Models\Role;
use App\Models\TravelPolicy;
use App\Models\TravelRequest;
use App\Models\User;
use App\Support\PolicyCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PolicyCheckTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;
    private User $lineManager;
    private User $divisionHead;
    private User $finance;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Super Admin', 'Finance Approver', 'Division Head', 'Line Manager', 'Employee'] as $name) {
            Role::create(['name' => $name, 'permissions' => []]);
        }

        $dept = Department::create(['code' => 'CC-TECH-502', 'name' => 'IT', 'budget_total' => 1000000, 'sla' => 'Tier 1']);

        $make = fn (string $email, string $role, ?int $managerId = null) => User::create([
            'name' => $email, 'nik' => 'NIK-'.$email, 'email' => $email,
            'password' => Hash::make('password123'), 'department_id' => $dept->id,
            'grade' => 'P4', 'role' => $role, 'status' => 'Aktif', 'manager_id' => $managerId,
        ]);

        $this->lineManager = $make('lm@x.id', 'Line Manager');
        $this->employee = $make('emp@x.id', 'Employee', $this->lineManager->id);
        $this->divisionHead = $make('dh@x.id', 'Division Head');
        $this->finance = $make('fin@x.id', 'Finance Approver');
        $dept->update(['leader_id' => $this->divisionHead->id]);

        $this->project = Project::create([
            'code' => 'PRJ-1', 'wbs' => '1', 'name' => 'P', 'pm_id' => $this->lineManager->id,
            'start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'budget_total' => 1000000,
        ]);

        TravelPolicy::create([
            'code' => 'POL-IDN-MGR-03', 'grade' => 'P3 - P5',
            'transport_plafon' => 'Maks Rp 4.500.000', 'hotel_plafon' => 'Maks Rp 1.100.000 / malam',
            'per_diem' => 'Rp 450.000', 'local_transport' => 'Maks Rp 200.000 / hari',
            'sla' => '3 Hari Kerja', 'region' => 'Domestik', 'enabled' => true,
        ]);
    }

    public function test_parse_rupiah(): void
    {
        $this->assertSame(4500000.0, PolicyCheck::parseRupiah('Maks Rp 4.500.000'));
        $this->assertSame(450000.0, PolicyCheck::parseRupiah('Rp 450.000'));
        $this->assertSame(0.0, PolicyCheck::parseRupiah(''));
        $this->assertSame(0.0, PolicyCheck::parseRupiah(null));
    }

    public function test_grade_matches(): void
    {
        $this->assertTrue(PolicyCheck::gradeMatches('P3 - P5', 'P4'));
        $this->assertTrue(PolicyCheck::gradeMatches('P3 - P5', 'P3'));
        $this->assertTrue(PolicyCheck::gradeMatches('P3 - P5', 'P5'));
        $this->assertFalse(PolicyCheck::gradeMatches('P3 - P5', 'P2'));
        $this->assertFalse(PolicyCheck::gradeMatches('P3 - P5', 'M3'));
        $this->assertTrue(PolicyCheck::gradeMatches('M2', 'M2'));
    }

    public function test_check_returns_null_without_matching_policy(): void
    {
        $m1 = User::create([
            'name' => 'm1', 'nik' => 'NIK-m1', 'email' => 'm1@x.id',
            'password' => Hash::make('password123'), 'department_id' => $this->employee->department_id,
            'grade' => 'M1', 'role' => 'Employee', 'status' => 'Aktif',
        ]);

        $this->assertNull(PolicyCheck::check($m1, 999999999, 5));
    }

    public function test_store_sets_sesuai_and_policy_code_for_compliant_cost(): void
    {
        Sanctum::actingAs($this->employee);
        // 3 hari (nights=2): 4,5jt + 1,1jt*2 + 450rb*3 + 200rb*3 = 8,65jt ≥ 3jt.
        $res = $this->postJson('/api/travel-requests', [
            'user_id' => $this->employee->id, 'project_id' => $this->project->id,
            'route' => 'JKT → SBY', 'transport' => 'Pesawat',
            'start_date' => '2025-07-01', 'end_date' => '2025-07-03', 'cost' => 3000000,
        ])->assertCreated()->json();

        $this->assertSame('Sesuai', $res['policy_status']);
        // Policy otomatis dari grade pemohon (tanpa pilih manual).
        $this->assertSame('POL-IDN-MGR-03', $res['policy_code']);
    }

    public function test_store_rejects_over_plafon_cost(): void
    {
        Sanctum::actingAs($this->employee);
        // 5 hari plafon = 12,15jt; 24,75jt → ditolak 422 (bukan lagi badge deviasi).
        $this->postJson('/api/travel-requests', [
            'user_id' => $this->employee->id, 'project_id' => $this->project->id,
            'route' => 'JKT → SBY', 'transport' => 'Pesawat',
            'start_date' => '2025-05-18', 'end_date' => '2025-05-22', 'cost' => 24750000,
        ])->assertStatus(422)->assertJsonValidationErrors('cost');

        $this->assertDatabaseCount('travel_requests', 0);
    }

    public function test_store_no_policy_has_no_limit(): void
    {
        // Grade M1 tak cocok policy mana pun → tanpa pembatas.
        $m1 = User::create([
            'name' => 'm1', 'nik' => 'NIK-m1', 'email' => 'm1@x.id',
            'password' => Hash::make('password123'), 'department_id' => $this->employee->department_id,
            'grade' => 'M1', 'role' => 'Employee', 'status' => 'Aktif',
        ]);
        Sanctum::actingAs($m1);
        $res = $this->postJson('/api/travel-requests', [
            'user_id' => $m1->id, 'project_id' => $this->project->id,
            'route' => 'JKT → SBY', 'transport' => 'Pesawat',
            'start_date' => '2025-05-18', 'end_date' => '2025-05-22', 'cost' => 999000000,
        ])->assertCreated()->json();

        $this->assertNull($res['policy_status']);
        $this->assertNull($res['policy_code']);
    }

    public function test_advance_autofills_plafon_from_policy_and_blocks_over(): void
    {
        $tr = TravelRequest::create([
            'request_no' => 'TR-ADV-1', 'user_id' => $this->employee->id, 'project_id' => $this->project->id,
            'route' => 'JKT → SBY', 'transport' => 'Pesawat',
            'start_date' => '2025-05-18', 'end_date' => '2025-05-22',
            'cost' => 10000000, 'status' => 'Submitted',
        ]);
        Sanctum::actingAs($this->employee);

        // 5 hari plafon = 12,15jt. Nominal 5jt → lolos, plafon terisi otomatis.
        $res = $this->postJson('/api/advances', [
            'travel_request_id' => $tr->id, 'amount' => 5000000, 'method' => 'Bank Transfer',
        ])->assertCreated()->json();
        $this->assertSame(12150000, (int) $res['plafon']);

        // Nominal > plafon → 422.
        $this->postJson('/api/advances', [
            'travel_request_id' => $tr->id, 'amount' => 15000000, 'method' => 'Bank Transfer',
        ])->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    public function test_applicable_endpoint_resolves_policy_by_grade(): void
    {
        Sanctum::actingAs($this->employee);
        $res = $this->getJson('/api/travel-policies/applicable?start_date=2025-05-18&end_date=2025-05-22')
            ->assertOk()->json();

        $this->assertSame('POL-IDN-MGR-03', $res['policy']['policy_code']);
        $this->assertSame('P4', $res['policy']['user_grade']);
        $this->assertSame(5, $res['policy']['days']);
        $this->assertSame(12150000.0, (float) $res['policy']['limit']);
    }

    private function makeSuperAdmin(string $grade = 'P4'): User
    {
        return User::create([
            'name' => 'sa-'.$grade, 'nik' => 'NIK-sa-'.$grade, 'email' => 'sa-'.strtolower($grade).'@x.id',
            'password' => Hash::make('password123'), 'department_id' => $this->employee->department_id,
            'grade' => $grade, 'role' => 'Super Admin', 'status' => 'Aktif',
        ]);
    }

    public function test_applicable_uses_requested_user_id_for_super_admin(): void
    {
        // SA grade M1 (tak cocok policy) menanyakan plafon atas nama employee (P4).
        Sanctum::actingAs($this->makeSuperAdmin('M1'));
        $res = $this->getJson('/api/travel-policies/applicable?user_id='.$this->employee->id.'&start_date=2025-05-18&end_date=2025-05-22')
            ->assertOk()->json();

        $this->assertSame('POL-IDN-MGR-03', $res['policy']['policy_code']);
        $this->assertSame('P4', $res['policy']['user_grade']);
    }

    public function test_applicable_defaults_to_logged_in_user(): void
    {
        // SA grade M1 tanpa user_id → subjek = dirinya sendiri → tak ada policy.
        Sanctum::actingAs($this->makeSuperAdmin('M1'));
        $res = $this->getJson('/api/travel-policies/applicable?start_date=2025-05-18&end_date=2025-05-22')
            ->assertOk()->json();

        $this->assertNull($res['policy']);
    }

    public function test_applicable_forbidden_for_foreign_user_id_as_non_admin(): void
    {
        Sanctum::actingAs($this->employee);
        $this->getJson('/api/travel-policies/applicable?user_id='.$this->finance->id)
            ->assertForbidden();
    }

    public function test_simulate_uses_requested_user_id(): void
    {
        Sanctum::actingAs($this->makeSuperAdmin('M1'));
        $res = $this->postJson('/api/approval-simulate', [
            'amount' => 3000000, 'start_date' => '2025-07-01', 'end_date' => '2025-07-03',
            'user_id' => $this->employee->id,
        ])->assertOk()->json();
        $this->assertSame('POL-IDN-MGR-03', $res['policy']['policy_code']);

        // Non-SA meminta simulasi atas nama user lain → 403.
        Sanctum::actingAs($this->employee);
        $this->postJson('/api/approval-simulate', ['amount' => 3000000, 'user_id' => $this->finance->id])
            ->assertForbidden();
    }

    public function test_finalize_preserves_legacy_deviation_badge(): void
    {
        // Deviasi tak lagi bisa dibuat via store (diblokir 422), jadi simulasikan
        // TR legacy ber-badge deviasi lalu finalisasi lewat rantai.
        $tr = TravelRequest::create([
            'request_no' => 'TR-LEGACY-1', 'user_id' => $this->employee->id, 'project_id' => $this->project->id,
            'route' => 'JKT → SBY', 'transport' => 'Pesawat',
            'start_date' => '2025-05-18', 'end_date' => '2025-05-22',
            'cost' => 3000000, 'status' => 'Submitted', 'policy_status' => 'Deviasi 10%',
        ]);
        \App\Support\ApprovalChain::build($tr->fresh(), request());

        $ordered = ApprovalQueue::where('travel_request_id', $tr->id)->orderBy('sequence')->get();
        $this->assertCount(1, $ordered);

        Sanctum::actingAs($this->lineManager);
        $this->postJson("/api/approval-queue/{$ordered[0]->id}/decide", ['action' => 'approve'])->assertOk();

        $this->assertDatabaseHas('travel_requests', [
            'id' => $tr->id, 'status' => 'Approved', 'policy_status' => 'Policy Approved · Deviasi 10%',
        ]);
    }

    public function test_simulate_includes_policy_block(): void
    {
        Sanctum::actingAs($this->employee);
        $res = $this->postJson('/api/approval-simulate', [
            'amount' => 24750000, 'start_date' => '2025-05-18', 'end_date' => '2025-05-22',
        ])->assertOk()->json();

        $this->assertSame('Deviasi 103.7%', $res['policy']['status']);
        $this->assertSame('POL-IDN-MGR-03', $res['policy']['policy_code']);
        $this->assertSame(5, $res['policy']['days_assumed']);
        $this->assertFalse($res['policy']['within_limit']);
        $this->assertSame(12150000.0, (float) $res['policy']['limit']);
        $this->assertContains('Level 3 - Finance & Treasury', $res['steps']);
    }

    public function test_simulate_policy_null_without_dates_and_no_match(): void
    {
        // Tanpa tanggal days=1; grade M1 tak cocok policy mana pun → null.
        $m1 = User::create([
            'name' => 'm1', 'nik' => 'NIK-m1', 'email' => 'm1@x.id',
            'password' => Hash::make('password123'), 'department_id' => $this->employee->department_id,
            'grade' => 'M1', 'role' => 'Employee', 'status' => 'Aktif',
        ]);
        Sanctum::actingAs($m1);
        $res = $this->postJson('/api/approval-simulate', ['amount' => 1000000])->assertOk()->json();

        $this->assertNull($res['policy']);
    }
}
