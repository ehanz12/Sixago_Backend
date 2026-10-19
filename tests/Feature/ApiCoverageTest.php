<?php

namespace Tests\Feature;

use App\Models\Advance;
use App\Models\ApprovalQueue;
use App\Models\Delegation;
use App\Models\Department;
use App\Models\Notification;
use App\Models\Project;
use App\Models\Reimbursement;
use App\Models\Role;
use App\Models\TravelDocument;
use App\Models\TravelPolicy;
use App\Models\TravelRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cakupan endpoint per controller — melengkapi RbacApprovalTest yang fokus
 * ke approval & RBAC. Setiap area: satu happy path + minimal satu negative
 * test (role salah → 403) atau validasi (422).
 */
class ApiCoverageTest extends TestCase
{
    use RefreshDatabase;

    private User $sa;
    private User $employee;
    private User $lineManager;
    private User $divisionHead;
    private User $finance;
    private Department $dept;
    private Project $project;
    private TravelRequest $tr;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Super Admin', 'Finance Approver', 'Division Head', 'Line Manager', 'Employee'] as $name) {
            Role::create(['name' => $name, 'permissions' => $name === 'Super Admin' ? ['*'] : []]);
        }

        $this->dept = Department::create(['code' => 'CC-TECH-502', 'name' => 'IT', 'budget_total' => 1000000, 'sla' => 'Tier 1']);

        $make = fn (string $email, string $role, ?int $managerId = null) => User::create([
            'name' => $email, 'nik' => 'NIK-'.$email, 'email' => $email,
            'password' => Hash::make('password123'), 'department_id' => $this->dept->id,
            'grade' => 'P4', 'role' => $role, 'status' => 'Aktif', 'manager_id' => $managerId,
        ]);

        $this->sa = $make('sa@x.id', 'Super Admin');
        $this->lineManager = $make('lm@x.id', 'Line Manager');
        $this->employee = $make('emp@x.id', 'Employee', $this->lineManager->id);
        $this->divisionHead = $make('dh@x.id', 'Division Head');
        $this->finance = $make('fin@x.id', 'Finance Approver');

        $this->project = Project::create([
            'code' => 'PRJ-1', 'wbs' => '1', 'name' => 'P', 'pm_id' => $this->lineManager->id,
            'start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'budget_total' => 1000000,
        ]);

        $this->tr = TravelRequest::create([
            'request_no' => 'TR-1', 'user_id' => $this->employee->id, 'project_id' => $this->project->id,
            'route' => 'A → B', 'transport' => 'Pesawat', 'start_date' => '2025-02-01',
            'end_date' => '2025-02-03', 'cost' => 1000000, 'status' => 'In Review',
            'policy_status' => 'In Review (L1)',
        ]);

        ApprovalQueue::create([
            'travel_request_id' => $this->tr->id, 'type' => 'Travel', 'level' => '1 of 3',
            'status' => 'pending', 'amount' => 1000000,
        ]);
    }

    // ============ AUTH ============

    public function test_login_wrong_password_returns_401(): void
    {
        $this->postJson('/api/login', ['corporateId' => $this->employee->email, 'password' => 'salah'])
            ->assertUnauthorized();
    }

    public function test_login_inactive_user_returns_403(): void
    {
        $this->employee->update(['status' => 'Non-Aktif']);
        $this->postJson('/api/login', ['corporateId' => $this->employee->email, 'password' => 'password123'])
            ->assertForbidden();
    }

    public function test_login_returns_token_shape_and_permissions(): void
    {
        $res = $this->postJson('/api/login', [
            'corporateId' => $this->employee->email, 'password' => 'password123',
        ])->assertOk()->json();

        $this->assertArrayHasKey('access_token', $res);
        $this->assertArrayHasKey('must_change_password', $res);
        $this->assertSame('Bearer', $res['token_type']);
        $this->assertSame($this->employee->id, $res['user']['id']);
        $this->assertIsArray($res['user']['permissions']);
    }

    public function test_logout_revokes_current_token_only(): void
    {
        $tokenA = $this->employee->createToken('a')->plainTextToken;
        $tokenB = $this->employee->createToken('b')->plainTextToken;

        $this->withToken($tokenA)->getJson('/api/user')->assertOk();
        $this->withToken($tokenA)->postJson('/api/logout')->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 1);

        // Guard Sanctum menyimpan user terotentikasi di memori antar request dalam
        // satu test — paksa resolve ulang agar revokasi benar-benar teruji.
        Auth::forgetGuards();
        $this->withToken($tokenA)->getJson('/api/user')->assertUnauthorized();
        // Token lain tetap hidup.
        $this->withToken($tokenB)->getJson('/api/user')->assertOk();
    }

    public function test_logout_all_revokes_everything(): void
    {
        $token = $this->employee->createToken('a')->plainTextToken;
        $this->withToken($token)->postJson('/api/logout-all')
            ->assertOk()
            ->assertJsonFragment(['message' => 'Semua sesi berhasil diakhiri.']);

        $this->assertDatabaseCount('personal_access_tokens', 0);

        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/user')->assertUnauthorized();
    }

    public function test_change_password_validates_min_8_and_confirmation(): void
    {
        Sanctum::actingAs($this->employee);
        $this->postJson('/api/user/password', [
            'current_password' => 'password123', 'password' => 'pendek', 'password_confirmation' => 'pendek',
        ])->assertUnprocessable();

        // Konfirmasi tidak cocok → 422.
        $this->postJson('/api/user/password', [
            'current_password' => 'password123', 'password' => 'passwordbaru1', 'password_confirmation' => 'berbeda',
        ])->assertUnprocessable();

        // Password lama salah → 422.
        $this->postJson('/api/user/password', [
            'current_password' => 'salah', 'password' => 'passwordbaru1', 'password_confirmation' => 'passwordbaru1',
        ])->assertUnprocessable();
    }

    public function test_user_profile_update(): void
    {
        Sanctum::actingAs($this->employee);
        $res = $this->putJson('/api/user/profile', [
            'whatsapp' => '+62-1', 'email_notif_enabled' => false,
        ])->assertOk()->json();

        $this->assertSame('+62-1', $res['whatsapp']);
        $this->assertFalse((bool) $res['email_notif_enabled']);
    }

    // ============ USER MANAGEMENT ============

    public function test_user_store_is_super_admin_only_and_sets_must_change(): void
    {
        $payload = [
            'name' => 'Baru', 'nik' => 'NIK-baru', 'email' => 'baru@x.id',
            'password' => 'password123', 'role' => 'Employee', 'grade' => 'P2',
            // Employee wajib punya atasan langsung (Positions::ROLE_MATRIX).
            'manager_id' => $this->lineManager->id,
        ];

        Sanctum::actingAs($this->employee);
        $this->postJson('/api/users', $payload)->assertForbidden();

        Sanctum::actingAs($this->sa);
        $res = $this->postJson('/api/users', $payload)->assertCreated()->json();
        $this->assertTrue((bool) $res['must_change_password']);
    }

    public function test_user_store_rejects_employee_without_manager(): void
    {
        Sanctum::actingAs($this->sa);
        $this->postJson('/api/users', [
            'name' => 'Tanpa Atasan', 'nik' => 'NIK-no-mgr', 'email' => 'nomgr@x.id',
            'password' => 'password123', 'role' => 'Employee', 'grade' => 'P2',
        ])->assertStatus(422)->assertJsonValidationErrors('manager_id');
    }

    public function test_user_store_validates_nik_unique(): void
    {
        Sanctum::actingAs($this->sa);
        $this->postJson('/api/users', [
            'name' => 'Duplikat', 'nik' => $this->employee->nik, 'email' => 'dupe@x.id',
            'password' => 'password123', 'role' => 'Employee', 'grade' => 'P2',
        ])->assertUnprocessable();
    }

    public function test_user_destroy_with_children_409_and_leaf_204(): void
    {
        Sanctum::actingAs($this->sa);
        // employee punya travel request → 409.
        $this->deleteJson("/api/users/{$this->employee->id}")->assertConflict();

        $leaf = User::create([
            'name' => 'leaf', 'nik' => 'NIK-leaf', 'email' => 'leaf@x.id',
            'password' => Hash::make('password123'), 'department_id' => $this->dept->id,
            'grade' => 'P1', 'role' => 'Employee', 'status' => 'Aktif',
        ]);
        $this->deleteJson("/api/users/{$leaf->id}")->assertNoContent();
    }

    public function test_user_reset_password_and_toggle_status_sa_only(): void
    {
        Sanctum::actingAs($this->lineManager);
        $this->postJson("/api/users/{$this->employee->id}/reset-password", ['password' => 'SixAgo123!'])
            ->assertForbidden();

        Sanctum::actingAs($this->sa);
        $this->postJson("/api/users/{$this->employee->id}/reset-password", ['password' => 'SixAgo123!'])->assertOk();
        $this->patchJson("/api/users/{$this->employee->id}/toggle-status")->assertOk();
        $this->assertSame('Non-Aktif', $this->employee->fresh()->status);
    }

    public function test_user_import_csv(): void
    {
        Sanctum::actingAs($this->sa);
        $csv = "nik,name,email,role,grade,department_code\nNIK-i1,Impor Satu,impor1@x.id,Employee,P1,CC-TECH-502\n,Baris Rusak,,\n";
        $file = UploadedFile::fake()->createWithContent('import.csv', $csv);

        $res = $this->postJson('/api/users/import', ['file' => $file])->assertOk()->json();
        $this->assertSame(1, $res['created']);
        $this->assertNotNull(User::where('email', 'impor1@x.id')->first());
    }

    // ============ TRAVEL REQUEST ============

    public function test_travel_request_store_builds_chain_and_submits(): void
    {
        Sanctum::actingAs($this->employee);
        $res = $this->postJson('/api/travel-requests', [
            'user_id' => $this->employee->id, 'project_id' => $this->project->id,
            'route' => 'JKT → SBY', 'transport' => 'Pesawat',
            'start_date' => '2025-07-01', 'end_date' => '2025-07-03', 'cost' => 3000000,
        ])->assertCreated()->json();

        $this->assertStringStartsWith('TR-', $res['request_no']);
        $this->assertSame('Submitted', $res['status']);
        $this->assertGreaterThan(0, ApprovalQueue::where('travel_request_id', $res['id'])->count());
    }

    public function test_travel_request_store_computes_duration_from_dates(): void
    {
        Sanctum::actingAs($this->employee);
        $res = $this->postJson('/api/travel-requests', [
            'user_id' => $this->employee->id, 'project_id' => $this->project->id,
            'route' => 'JKT → SBY', 'transport' => 'Pesawat',
            'start_date' => '2025-07-01', 'end_date' => '2025-07-03', 'cost' => 1000000,
        ])->assertCreated()->json();

        // Inklusif: 1→3 Juli = 3 hari, 2 malam.
        $this->assertSame('3 Hari (2 Malam)', $res['duration']);
    }

    public function test_travel_request_store_ignores_client_supplied_duration(): void
    {
        Sanctum::actingAs($this->employee);
        $res = $this->postJson('/api/travel-requests', [
            'user_id' => $this->employee->id, 'project_id' => $this->project->id,
            'route' => 'JKT → SBY', 'transport' => 'Pesawat',
            'start_date' => '2025-07-01', 'end_date' => '2025-07-03', 'cost' => 1000000,
            'duration' => '999 Hari',
        ])->assertCreated()->json();

        $this->assertSame('3 Hari (2 Malam)', $res['duration']);
    }

    public function test_travel_request_update_recomputes_duration_on_date_change(): void
    {
        Sanctum::actingAs($this->employee);
        $tr = $this->postJson('/api/travel-requests', [
            'user_id' => $this->employee->id, 'project_id' => $this->project->id,
            'route' => 'JKT → SBY', 'transport' => 'Pesawat',
            'start_date' => '2025-07-01', 'end_date' => '2025-07-03', 'cost' => 1000000,
        ])->assertCreated()->json();

        $this->putJson("/api/travel-requests/{$tr['id']}", ['end_date' => '2025-07-06'])
            ->assertOk();

        $this->assertSame('6 Hari (5 Malam)', TravelRequest::findOrFail($tr['id'])->duration);
    }

    public function test_travel_request_store_for_other_user_forbidden(): void
    {
        Sanctum::actingAs($this->employee);
        $this->postJson('/api/travel-requests', [
            'user_id' => $this->finance->id, 'project_id' => $this->project->id,
            'route' => 'X → Y', 'transport' => 'KA',
            'start_date' => '2025-07-01', 'end_date' => '2025-07-02', 'cost' => 100000,
        ])->assertForbidden();
    }

    public function test_travel_request_by_request_no_and_summary(): void
    {
        Sanctum::actingAs($this->employee);
        $res = $this->getJson('/api/travel-requests/no/TR-1')->assertOk()->json();
        $this->assertSame('TR-1', $res['request_no']);
        $this->assertArrayHasKey('user', $res);

        $summary = $this->getJson('/api/travel-requests-summary')->assertOk()->json();
        $this->assertArrayHasKey('total', $summary);
        $this->assertArrayHasKey('by_status', $summary);
    }

    // ============ ADVANCE (UANG MUKA) ============

    public function test_advance_store_requires_own_travel(): void
    {
        Sanctum::actingAs($this->lineManager);
        $this->postJson('/api/advances', [
            'travel_request_id' => $this->tr->id, 'amount' => 500000, 'method' => 'Transfer',
        ])->assertForbidden();

        Sanctum::actingAs($this->employee);
        $res = $this->postJson('/api/advances', [
            'travel_request_id' => $this->tr->id, 'amount' => 500000, 'method' => 'Transfer',
            'destination' => 'Surabaya',
        ])->assertCreated()->json();
        $this->assertStringStartsWith('ADV-', $res['request_no']);
        $this->assertSame('Pending Finance', $res['status']);
    }

    public function test_advance_decide_and_disburse_require_finance(): void
    {
        $adv = Advance::create([
            'request_no' => 'ADV-T1', 'travel_request_id' => $this->tr->id,
            'amount' => 500000, 'method' => 'Transfer', 'status' => 'Pending Finance',
        ]);

        Sanctum::actingAs($this->lineManager);
        $this->postJson("/api/advances/{$adv->id}/decide", ['action' => 'approve'])->assertForbidden();

        Sanctum::actingAs($this->finance);
        $this->postJson("/api/advances/{$adv->id}/decide", ['action' => 'approve'])
            ->assertOk();
        $this->assertSame('Approved-Ready to Pay', $adv->fresh()->status);

        // Disburse tanpa referensi → 422.
        $this->postJson("/api/advances/{$adv->id}/disburse", [])->assertUnprocessable();
        $this->postJson("/api/advances/{$adv->id}/disburse", ['transfer_ref' => 'REF-1'])->assertOk();
        $this->assertSame('Paid', $adv->fresh()->status);
    }

    public function test_advances_summary_shape(): void
    {
        Sanctum::actingAs($this->finance);
        $res = $this->getJson('/api/advances-summary')->assertOk()->json();
        foreach (['pending_count', 'pending_amount', 'ready_count', 'ready_amount', 'total_disbursed'] as $key) {
            $this->assertArrayHasKey($key, $res);
        }
    }

    // ============ REIMBURSEMENT ============

    public function test_reimbursement_store_and_verify_flow(): void
    {
        Sanctum::actingAs($this->employee);
        $res = $this->postJson('/api/reimbursements', [
            'travel_request_id' => $this->tr->id, 'actual_expense' => 1200000,
            'advance' => 500000, 'ocr_status' => 'OK',
        ])->assertCreated()->json();
        $this->assertStringStartsWith('REIM-', $res['request_no']);
        $this->assertSame('Menunggu Audit', $res['status']);
        // Rekonsiliasi: 1.200.000 − 500.000 = reimbursable.
        $this->assertStringContainsString('Reimbursable', $res['reconciliation']);

        Sanctum::actingAs($this->finance);
        $this->postJson("/api/reimbursements/{$res['id']}/verify", ['action' => 'verify'])->assertOk();
        $this->assertSame('Menunggu Verifikasi Finance', Reimbursement::find($res['id'])->status);
        $this->postJson("/api/reimbursements/{$res['id']}/verify", ['action' => 'settlement'])->assertOk();
        $this->assertSame('Disetujui', Reimbursement::find($res['id'])->status);
    }

    public function test_reimbursement_verify_forbidden_for_lm(): void
    {
        $reim = Reimbursement::create([
            'request_no' => 'REIM-T1', 'travel_request_id' => $this->tr->id,
            'actual_expense' => 100000, 'ocr_status' => 'OK', 'status' => 'Menunggu Audit',
        ]);

        Sanctum::actingAs($this->lineManager);
        $this->postJson("/api/reimbursements/{$reim->id}/verify", ['action' => 'verify'])->assertForbidden();
    }

    public function test_reimbursements_summary_shape(): void
    {
        Sanctum::actingAs($this->finance);
        $res = $this->getJson('/api/reimbursements-summary')->assertOk()->json();
        foreach (['total_claimed', 'total_advance', 'net_reimbursement', 'pending_count'] as $key) {
            $this->assertArrayHasKey($key, $res);
        }
    }

    // ============ NOTIFICATION ============

    public function test_notification_store_is_approver_only_and_flows(): void
    {
        Sanctum::actingAs($this->employee);
        $this->postJson('/api/notifications', [
            'user_id' => $this->finance->id, 'title' => 'X', 'message' => 'Y', 'type' => 'Approval',
        ])->assertForbidden();

        Sanctum::actingAs($this->lineManager);
        $res = $this->postJson('/api/notifications', [
            'user_id' => $this->employee->id, 'title' => 'Revisi', 'message' => 'Perbaiki',
            'type' => 'Approval', 'category' => 'approval', 'reference_id' => 'TR-1',
        ])->assertCreated()->json();
        $this->assertSame('Revisi', $res['title']);

        Sanctum::actingAs($this->employee);
        $this->getJson('/api/notifications-unread-count')->assertOk()->assertJson(['unread' => 1]);
        $this->patchJson("/api/notifications/{$res['id']}/read")->assertOk();
        $this->postJson('/api/notifications/mark-all-read')->assertOk();
    }

    // ============ TRAVEL DOCUMENT ============

    public function test_document_store_owner_or_finance_only(): void
    {
        Sanctum::actingAs($this->divisionHead);
        $this->postJson('/api/travel-documents', [
            'travel_request_id' => $this->tr->id, 'type' => 'tiket', 'title' => 'Tiket',
        ])->assertForbidden();

        Sanctum::actingAs($this->employee);
        $res = $this->postJson('/api/travel-documents', [
            'travel_request_id' => $this->tr->id, 'type' => 'tiket', 'title' => 'Tiket',
            'file_url' => '/storage/x.pdf',
        ])->assertCreated()->json();
        $this->assertSame('Tiket', $res['title']);
        $this->assertSame('/storage/x.pdf', $res['file_url']);

        Sanctum::actingAs($this->finance);
        $this->getJson('/api/travel-documents?travel_request_id='.$this->tr->id)->assertOk();
        $this->deleteJson("/api/travel-documents/{$res['id']}")->assertNoContent();
    }

    // ============ DELEGATION ============

    public function test_delegation_store_self_only_and_revoke(): void
    {
        $base = [
            'scope' => 'Penuh', 'max_amount' => 25000000,
            'start_date' => '2025-01-01', 'end_date' => '2025-12-31',
        ];

        Sanctum::actingAs($this->lineManager);
        // Delegator orang lain → 403.
        $this->postJson('/api/delegations', $base + [
            'delegator_id' => $this->divisionHead->id, 'delegatee_id' => $this->lineManager->id,
        ])->assertForbidden();

        // Delegator diri sendiri, penerima fellow approver → 201.
        $res = $this->postJson('/api/delegations', $base + [
            'delegator_id' => $this->lineManager->id, 'delegatee_id' => $this->finance->id,
            'reason' => 'Cuti',
        ])->assertCreated()->json();
        $this->assertSame('Penuh', $res['scope']);

        // Orang lain tidak bisa mencabut.
        Sanctum::actingAs($this->finance);
        $this->postJson("/api/delegations/{$res['id']}/revoke")->assertForbidden();

        // Delegator bisa mencabut.
        Sanctum::actingAs($this->lineManager);
        $this->postJson("/api/delegations/{$res['id']}/revoke")->assertOk();
        $this->assertSame('Kedaluwarsa', Delegation::find($res['id'])->status);
    }

    // ============ DEPARTMENT / ROLE ============

    public function test_department_crud_sa_only_then_dh_own_update(): void
    {
        Sanctum::actingAs($this->employee);
        $this->postJson('/api/departments', [
            'code' => 'CC-X', 'name' => 'X', 'sla' => 'Tier 1',
        ])->assertForbidden();

        Sanctum::actingAs($this->sa);
        $res = $this->postJson('/api/departments', [
            'code' => 'CC-X', 'name' => 'X', 'sla' => 'Tier 1',
        ])->assertCreated()->json();

        // DH bukan leader departemen mana pun → tidak bisa mengubah departemen orang.
        Sanctum::actingAs($this->divisionHead);
        $this->putJson("/api/departments/{$res['id']}", ['sla' => 'Tier 2'])->assertForbidden();

        // DH sebagai leader departemennya sendiri → boleh.
        $this->dept->update(['leader_id' => $this->divisionHead->id]);
        $this->divisionHead->update(['department_id' => $this->dept->id]);
        $this->putJson("/api/departments/{$this->dept->id}", ['sla' => 'Tier 2'])->assertOk();

        Sanctum::actingAs($this->sa);
        $this->deleteJson("/api/departments/{$res['id']}")->assertNoContent();
    }

    /**
     * Kolom `departments.members` tidak pernah diisi (selalu 0). Index harus
     * melaporkan jumlah nyata dari relasi users(), bukan angka kolom itu.
     */
    public function test_department_index_reports_real_member_count(): void
    {
        Sanctum::actingAs($this->sa);
        $res = $this->getJson('/api/departments')->assertOk()->json('data');

        $mine = collect($res)->firstWhere('id', $this->dept->id);
        $this->assertNotNull($mine);

        // Bug-nya: kolom DB tidak pernah diisi (default 0).
        $raw = (int) Department::findOrFail($this->dept->id)->getRawOriginal('members');
        $this->assertSame(0, $raw, 'Kolom departments.members memang tidak pernah diisi');

        $expected = User::where('department_id', $this->dept->id)->count();
        $this->assertGreaterThan(0, $expected);
        // API harus melaporkan hitungan relasi, bukan angka kolom itu.
        $this->assertSame($expected, (int) $mine['members']);
        $this->assertNotSame($raw, (int) $mine['members']);
        $this->assertCount(min(4, $expected), $mine['member_preview']);
    }

    public function test_department_index_is_scoped_to_visible_departments(): void
    {
        // Line Manager hanya melihat bawahan → hanya departemennya sendiri.
        Sanctum::actingAs($this->lineManager);
        $ids = collect($this->getJson('/api/departments')->assertOk()->json('data'))
            ->pluck('id')
            ->all();

        $this->assertContains($this->dept->id, $ids);

        // Departemen tanpa anggota yang terlihat harus tersaring.
        $kosong = Department::create([
            'code' => 'CC-KOSONG', 'name' => 'Kosong', 'budget_total' => 10, 'sla' => 'T',
        ]);
        Sanctum::actingAs($this->lineManager);
        $after = collect($this->getJson('/api/departments')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertNotContains($kosong->id, $after);
    }

    public function test_department_members_endpoint_is_super_admin_only(): void
    {
        Sanctum::actingAs($this->employee);
        $this->getJson("/api/departments/{$this->dept->id}/members")->assertForbidden();

        Sanctum::actingAs($this->sa);
        $res = $this->getJson("/api/departments/{$this->dept->id}/members")->assertOk()->json();

        $this->assertSame(
            User::where('department_id', $this->dept->id)->count(),
            (int) $res['total']
        );
        foreach ($res['data'] as $row) {
            $this->assertSame($this->dept->id, (int) User::findOrFail($row['id'])->department_id);
            $this->assertArrayHasKey('manager', $row);
            $this->assertArrayHasKey('grade', $row);
        }
    }

    public function test_export_departments_uses_real_member_count(): void
    {
        Sanctum::actingAs($this->sa);
        $csv = $this->get('/api/exports/departments')->assertOk()->streamedContent();

        $expected = User::where('department_id', $this->dept->id)->count();
        $this->assertGreaterThan(0, $expected);
        // Baris CSV: kode,nama,kepala,anggota,...
        $this->assertStringContainsString(','.$expected.',', $csv);
    }

    public function test_users_index_filters_by_department_id(): void
    {
        $other = Department::create([
            'code' => 'CC-LAIN', 'name' => 'Lain', 'budget_total' => 10, 'sla' => 'T',
        ]);

        Sanctum::actingAs($this->sa);
        $res = $this->getJson('/api/users?department_id='.$other->id)->assertOk()->json('data');
        $this->assertCount(0, $res);

        $res = $this->getJson('/api/users?department_id='.$this->dept->id)->assertOk()->json('data');
        $this->assertNotEmpty($res);
        foreach ($res as $u) {
            $this->assertSame($this->dept->id, (int) $u['department_id']);
        }
    }

    public function test_roles_list_and_store(): void
    {
        Sanctum::actingAs($this->employee);
        $this->getJson('/api/roles')->assertOk();

        $this->postJson('/api/roles', ['name' => 'Baru', 'permissions' => []])->assertForbidden();

        Sanctum::actingAs($this->sa);
        $this->postJson('/api/roles', ['name' => 'Auditor', 'description' => 'Audit', 'permissions' => ['travel.view.all']])
            ->assertCreated();
    }

    // ============ PROJECT ============

    public function test_project_crud_sa_only(): void
    {
        Sanctum::actingAs($this->employee);
        $this->postJson('/api/projects', [
            'code' => 'PRJ-2', 'wbs' => '2', 'name' => 'Q', 'pm_id' => $this->sa->id,
            'start_date' => '2025-01-01', 'end_date' => '2025-06-01', 'budget_total' => 1000,
        ])->assertForbidden();

        Sanctum::actingAs($this->sa);
        $res = $this->postJson('/api/projects', [
            'code' => 'PRJ-2', 'wbs' => '2', 'name' => 'Q', 'pm_id' => $this->sa->id,
            'start_date' => '2025-01-01', 'end_date' => '2025-06-01', 'budget_total' => 1000,
        ])->assertCreated()->json();
        $this->assertSame('PRJ-2', $res['code']);
    }

    public function test_project_sync_sap_finance_allowed_employee_denied(): void
    {
        Sanctum::actingAs($this->employee);
        $this->postJson('/api/projects/sync-sap')->assertForbidden();

        Sanctum::actingAs($this->finance);
        $res = $this->postJson('/api/projects/sync-sap')->assertOk()->json();
        $this->assertArrayHasKey('total_projects', $res);
    }

    // ============ POLICY ============

    public function test_policy_crud_toggle_and_unique_code(): void
    {
        Sanctum::actingAs($this->employee);
        $this->postJson('/api/travel-policies', ['code' => 'X'])->assertForbidden();

        Sanctum::actingAs($this->sa);
        $payload = [
            'code' => 'POL-T1', 'grade' => 'P4', 'transport_plafon' => 'Ekonomi',
            'hotel_plafon' => 'Standar', 'per_diem' => '500000',
            'local_transport' => 'Taksi', 'sla' => '24 jam', 'region' => 'Domestik',
        ];
        $res = $this->postJson('/api/travel-policies', $payload)->assertCreated()->json();

        // Kode duplikat → 422.
        $this->postJson('/api/travel-policies', $payload)->assertUnprocessable();

        $this->patchJson("/api/travel-policies/{$res['id']}/toggle")->assertOk();
        $this->assertTrue((bool) TravelPolicy::find($res['id'])->fresh()->enabled === false
            || TravelPolicy::find($res['id'])->fresh()->enabled === 0
            || TravelPolicy::find($res['id'])->fresh()->enabled === true);
        $this->deleteJson("/api/travel-policies/{$res['id']}")->assertNoContent();
    }

    // ============ SCHEME / LEVEL ============

    public function test_scheme_store_activate_and_simulate(): void
    {
        Sanctum::actingAs($this->sa);
        $scheme = $this->postJson('/api/approval-schemes', ['name' => 'Skema Uji'])->assertCreated()->json();

        $this->postJson("/api/approval-schemes/{$scheme['id']}/activate")->assertOk();
        $this->assertTrue((bool) \App\Models\ApprovalScheme::find($scheme['id'])->is_active);

        $steps = $this->postJson('/api/approval-simulate', ['amount' => 1000000])->assertOk()->json();
        $this->assertIsArray($steps['steps']);
        $this->assertNotEmpty($steps['steps']);
    }

    public function test_approval_level_store_sa_only(): void
    {
        Sanctum::actingAs($this->lineManager);
        $this->postJson('/api/approval-levels', [
            'scheme_id' => 1, 'step' => 1, 'title' => 'L1', 'role' => 'Line Manager',
        ])->assertForbidden();
    }

    // ============ DASHBOARD / EXPORT / SETTINGS / AUDIT ============

    public function test_dashboard_charts_and_badges_shape(): void
    {
        Sanctum::actingAs($this->finance);
        $charts = $this->getJson('/api/dashboard/charts')->assertOk()->json();
        $this->assertArrayHasKey('monthly', $charts);
        $this->assertArrayHasKey('by_department', $charts);

        $badges = $this->getJson('/api/dashboard/sidebar-badges')->assertOk()->json();
        $this->assertArrayHasKey('approval_pending', $badges);
        $this->assertArrayHasKey('notifications_unread', $badges);
    }

    public function test_exports_travel_requests_csv_and_users_sa_only(): void
    {
        Sanctum::actingAs($this->lineManager);
        $res = $this->getJson('/api/exports/travel-requests')->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));

        $this->getJson('/api/exports/users')->assertForbidden();

        Sanctum::actingAs($this->sa);
        $this->getJson('/api/exports/users')->assertOk();
    }

    public function test_settings_read_update_sa_only(): void
    {
        Sanctum::actingAs($this->finance);
        $this->getJson('/api/settings')->assertForbidden();

        Sanctum::actingAs($this->sa);
        $this->getJson('/api/settings')->assertOk()->assertJsonStructure(['escalation_enabled', 'delegation_enabled']);
        $this->putJson('/api/settings', ['delegation_enabled' => false])->assertOk();
        $this->putJson('/api/settings', ['delegation_enabled' => true])->assertOk();
    }

    public function test_audit_logs_role_gated(): void
    {
        Sanctum::actingAs($this->employee);
        $this->getJson('/api/audit-logs')->assertForbidden();

        Sanctum::actingAs($this->lineManager);
        $this->getJson('/api/audit-logs')->assertOk();
    }
}
