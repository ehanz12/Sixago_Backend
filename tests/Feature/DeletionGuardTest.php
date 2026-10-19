<?php

namespace Tests\Feature;

use App\Models\Advance;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Notification;
use App\Models\Project;
use App\Models\Reimbursement;
use App\Models\Role;
use App\Models\TravelDocument;
use App\Models\TravelRequest;
use App\Models\User;
use App\Support\TravelStatuses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Otorisasi tulis + jalur pembersihan data.
 *
 * MASALAH: destroy() pada Advance, Reimbursement, dan TravelDocument tidak
 * menerima parameter Request sama sekali, sehingga secara struktur mustahil
 * melakukan cek akses — dan route-nya juga tanpa middleware role. Akibatnya
 * user mana pun yang sudah login bisa menghapus record milik siapa saja,
 * termasuk uang muka berstatus Paid beserta nomor transfer dan buktinya.
 * update() punya masalah serupa: ia menerima `status` sehingga transisi bisa
 * dilakukan tanpa lewat FinanceFlow.
 *
 * Di sisi lain, audit_logs punya 14 sumber tulis dan tidak punya jalur
 * pembersihan apa pun — hanya bisa tumbuh selamanya.
 */
class DeletionGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $sa;
    private User $finance;
    private User $employee;
    private TravelRequest $travel;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Super Admin', 'Finance Approver', 'Division Head', 'Line Manager', 'Employee'] as $name) {
            Role::create(['name' => $name, 'permissions' => []]);
        }

        $dept = Department::create(['code' => 'CC-D', 'name' => 'Ops', 'budget_total' => 1, 'sla' => 'T']);
        $make = fn (string $e, string $r, string $g) => User::create([
            'name' => $e, 'nik' => 'NIK-'.$e, 'email' => $e, 'password' => Hash::make('password123'),
            'department_id' => $dept->id, 'grade' => $g, 'role' => $r, 'status' => 'Aktif',
        ]);
        $this->sa = $make('sa@x.id', 'Super Admin', 'M1');
        $this->finance = $make('fin@x.id', 'Finance Approver', 'M2');
        $this->employee = $make('emp@x.id', 'Employee', 'P4');

        $project = Project::create([
            'code' => 'PRJ-D', 'wbs' => '1', 'name' => 'P', 'pm_id' => $this->sa->id,
            'start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'budget_total' => 1000,
        ]);

        $this->travel = TravelRequest::create([
            'request_no' => 'TR-D-1', 'user_id' => $this->employee->id, 'project_id' => $project->id,
            'route' => 'A → B', 'transport' => 'KA', 'start_date' => '2025-02-01',
            'end_date' => '2025-02-02', 'cost' => 1000000, 'status' => 'Approved',
        ]);
    }

    private function makeAdvance(string $status): Advance
    {
        return Advance::create([
            'request_no' => 'ADV-D-'.$status, 'travel_request_id' => $this->travel->id,
            'amount' => 500000, 'plafon' => 1000000, 'method' => 'Transfer', 'status' => $status,
        ]);
    }

    private function makeReimbursement(string $status): Reimbursement
    {
        return Reimbursement::create([
            'request_no' => 'REIM-D-'.$status, 'travel_request_id' => $this->travel->id,
            'actual_expense' => 1000000, 'advance' => 0, 'ocr_status' => 'Valid',
            'receipt_count' => 1, 'status' => $status,
        ]);
    }

    // ============ Otorisasi hapus advance ============

    public function test_employee_cannot_delete_advance(): void
    {
        $advance = $this->makeAdvance(TravelStatuses::ADV_PENDING);
        Sanctum::actingAs($this->employee);

        $this->deleteJson("/api/advances/{$advance->id}")->assertForbidden();

        $this->assertDatabaseHas('advances', ['id' => $advance->id]);
    }

    public function test_finance_cannot_delete_paid_advance(): void
    {
        // Pencairan sudah terjadi: transfer_ref + bukti transfer adalah catatan
        // keuangan, tidak boleh hilang.
        $advance = $this->makeAdvance(TravelStatuses::ADV_PAID);
        $advance->update(['transfer_ref' => 'TRF-001', 'proof_path' => 'proof.pdf']);
        Sanctum::actingAs($this->finance);

        $this->deleteJson("/api/advances/{$advance->id}")->assertStatus(409);

        $this->assertDatabaseHas('advances', ['id' => $advance->id]);
    }

    public function test_finance_can_delete_pending_advance(): void
    {
        $advance = $this->makeAdvance(TravelStatuses::ADV_PENDING);
        Sanctum::actingAs($this->finance);

        $this->deleteJson("/api/advances/{$advance->id}")->assertNoContent();

        $this->assertDatabaseMissing('advances', ['id' => $advance->id]);
    }

    public function test_advance_update_cannot_write_status_directly(): void
    {
        // `status` sengaja tidak lagi ada di aturan validasi update, jadi
        // field itu diabaikan — yang penting statusnya tidak berubah meski
        // dikirim. Transisi hanya sah lewat decide()/disburse().
        $advance = $this->makeAdvance(TravelStatuses::ADV_PENDING);
        Sanctum::actingAs($this->finance);

        $this->putJson("/api/advances/{$advance->id}", [
            'status' => TravelStatuses::ADV_PAID, 'amount' => 600000,
        ])->assertOk();

        $fresh = $advance->fresh();
        $this->assertSame(TravelStatuses::ADV_PENDING, $fresh->status);
        $this->assertSame(600000.0, (float) $fresh->amount, 'field sah tetap tersimpan');
    }

    public function test_employee_cannot_update_advance(): void
    {
        $advance = $this->makeAdvance(TravelStatuses::ADV_PENDING);
        Sanctum::actingAs($this->employee);

        $this->putJson("/api/advances/{$advance->id}", ['amount' => 1])->assertForbidden();

        $this->assertSame(500000.0, (float) $advance->fresh()->amount);
    }

    // ============ Otorisasi hapus reimbursement ============

    public function test_employee_cannot_delete_reimbursement(): void
    {
        $reim = $this->makeReimbursement(TravelStatuses::REIM_AUDIT);
        Sanctum::actingAs($this->employee);

        $this->deleteJson("/api/reimbursements/{$reim->id}")->assertForbidden();

        $this->assertDatabaseHas('reimbursements', ['id' => $reim->id]);
    }

    public function test_finance_cannot_delete_settled_reimbursement(): void
    {
        $reim = $this->makeReimbursement(TravelStatuses::REIM_APPROVED);
        Sanctum::actingAs($this->finance);

        $this->deleteJson("/api/reimbursements/{$reim->id}")->assertStatus(409);

        $this->assertDatabaseHas('reimbursements', ['id' => $reim->id]);
    }

    public function test_reimbursement_update_cannot_write_status_directly(): void
    {
        $reim = $this->makeReimbursement(TravelStatuses::REIM_AUDIT);
        Sanctum::actingAs($this->finance);

        $this->putJson("/api/reimbursements/{$reim->id}", [
            'status' => TravelStatuses::REIM_APPROVED, 'receipt_count' => 3,
        ])->assertOk();

        $fresh = $reim->fresh();
        $this->assertSame(TravelStatuses::REIM_AUDIT, $fresh->status);
        $this->assertSame(3, $fresh->receipt_count);
    }

    // ============ Travel document ============

    public function test_stranger_cannot_delete_someone_elses_document(): void
    {
        $other = User::create([
            'name' => 'other@x.id', 'nik' => 'NIK-O', 'email' => 'other@x.id',
            'password' => Hash::make('password123'), 'department_id' => $this->employee->department_id,
            'grade' => 'P4', 'role' => 'Employee', 'status' => 'Aktif',
        ]);
        $doc = TravelDocument::create([
            'travel_request_id' => $this->travel->id, 'type' => 'Invoice',
            'title' => 'Tagihan', 'file_url' => '/storage/x.pdf',
        ]);
        Sanctum::actingAs($other);

        $this->deleteJson("/api/travel-documents/{$doc->id}")->assertForbidden();

        $this->assertDatabaseHas('travel_documents', ['id' => $doc->id]);
    }

    public function test_owner_can_update_document_and_old_file_is_removed(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('travel-documents/old.pdf', 'lama');
        $doc = TravelDocument::create([
            'travel_request_id' => $this->travel->id, 'type' => 'Invoice', 'title' => 'Tagihan',
            'file_path' => 'travel-documents/old.pdf', 'file_url' => '/storage/travel-documents/old.pdf',
        ]);
        Sanctum::actingAs($this->employee);

        $new = \Illuminate\Http\UploadedFile::fake()->create('baru.pdf', 12, 'application/pdf');
        $this->putJson("/api/travel-documents/{$doc->id}", [
            'title' => 'Tagihan Revisi', 'file' => $new,
        ])->assertOk();

        $fresh = $doc->fresh();
        $this->assertSame('Tagihan Revisi', $fresh->title);
        // File lama harus dihapus, bukan sekadar ditimpa — kalau tidak, setiap
        // penggantian dokumen menyisakan file yatim di storage.
        Storage::disk('public')->assertMissing('travel-documents/old.pdf');
        $this->assertNotSame('travel-documents/old.pdf', $fresh->file_path);
    }

    // ============ Custom role: hapus & perbarui ============

    public function test_role_in_use_cannot_be_deleted(): void
    {
        // users.role adalah kolom string, bukan FK ke tabel roles — jadi hapus
        // role tidak merusak integritas, tapi user-yatim diam-diam.
        $role = Role::create(['name' => 'Auditor', 'permissions' => []]);
        $this->employee->update(['role' => 'Auditor']);
        Sanctum::actingAs($this->sa);

        $this->deleteJson("/api/roles/{$role->id}")->assertStatus(409);

        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    public function test_unused_role_can_be_deleted_and_updated(): void
    {
        $role = Role::create(['name' => 'Auditor', 'description' => ' lama', 'permissions' => []]);
        Sanctum::actingAs($this->sa);

        $this->putJson("/api/roles/{$role->id}", [
            'name' => 'Auditor Lanjutan', 'description' => 'baru',
        ])->assertOk();
        $this->assertSame('Auditor Lanjutan', $role->fresh()->name);

        $this->deleteJson("/api/roles/{$role->id}")->assertNoContent();
        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function test_custom_role_cannot_reuse_system_role_name(): void
    {
        // Kalau boleh, user bisa membuat role bernama "Super Admin" yang
        // menabrak role kanonik.
        Sanctum::actingAs($this->sa);

        $this->postJson('/api/roles', ['name' => 'Super Admin'])->assertStatus(422);
    }

    public function test_non_super_admin_cannot_manage_roles(): void
    {
        $role = Role::create(['name' => 'Auditor', 'permissions' => []]);
        Sanctum::actingAs($this->finance);

        $this->deleteJson("/api/roles/{$role->id}")->assertForbidden();
    }

    // ============ Approval scheme ============

    public function test_active_scheme_cannot_be_deleted(): void
    {
        $scheme = \App\Models\ApprovalScheme::create([
            'name' => 'Skema Aktif', 'description' => 'x', 'is_active' => true,
        ]);
        Sanctum::actingAs($this->sa);

        $this->deleteJson("/api/approval-schemes/{$scheme->id}")->assertStatus(409);

        $this->assertDatabaseHas('approval_schemes', ['id' => $scheme->id]);
    }

    public function test_inactive_scheme_without_levels_can_be_deleted(): void
    {
        $scheme = \App\Models\ApprovalScheme::create([
            'name' => 'Skema Lama', 'description' => 'x', 'is_active' => false,
        ]);
        Sanctum::actingAs($this->sa);

        $this->deleteJson("/api/approval-schemes/{$scheme->id}")->assertNoContent();

        $this->assertDatabaseMissing('approval_schemes', ['id' => $scheme->id]);
    }

    // ============ Retensi & bulk clear ============

    public function test_audit_prune_only_removes_rows_older_than_retention(): void
    {
        AuditLog::create([
            'user_id' => $this->sa->id, 'action' => 'create',
            'auditable_type' => TravelRequest::class, 'auditable_id' => $this->travel->id,
        ]);
        $fresh = AuditLog::create([
            'user_id' => $this->sa->id, 'action' => 'update',
            'auditable_type' => TravelRequest::class, 'auditable_id' => $this->travel->id,
        ]);

        $old = $fresh->fresh();
        $old->created_at = now()->subDays(120);
        $old->save();

        $this->artisan('audit:prune', ['--days' => 90, '--dry-run' => true])->assertSuccessful();
        $this->assertSame(2, AuditLog::count(), 'Dry-run tidak boleh menghapus apa pun');

        $this->artisan('audit:prune', ['--days' => 90])->assertSuccessful();

        $this->assertDatabaseMissing('audit_logs', ['id' => $fresh->id]);
        // Yang masih dalam masa retensi harus tetap utuh.
        $this->assertDatabaseHas('audit_logs', ['action' => 'create']);
    }

    public function test_audit_logs_have_no_delete_endpoint(): void
    {
        // Audit log sengaja append-only: hapus per-item akan merusak jejaknya.
        $log = AuditLog::create([
            'user_id' => $this->sa->id, 'action' => 'create',
            'auditable_type' => TravelRequest::class, 'auditable_id' => $this->travel->id,
        ]);
        Sanctum::actingAs($this->sa);

        $this->deleteJson("/api/audit-logs/{$log->id}")->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['id' => $log->id]);
    }

    public function test_notifications_prune_only_removes_read_old_rows(): void
    {
        $readOld = Notification::create([
            'user_id' => $this->employee->id, 'title' => 'A', 'message' => 'a',
            'type' => 'info', 'read' => true,
        ]);
        $unreadOld = Notification::create([
            'user_id' => $this->employee->id, 'title' => 'B', 'message' => 'b',
            'type' => 'info', 'read' => false,
        ]);
        $readNew = Notification::create([
            'user_id' => $this->employee->id, 'title' => 'C', 'message' => 'c',
            'type' => 'info', 'read' => true,
        ]);

        foreach ([$readOld, $unreadOld] as $n) {
            $row = $n->fresh();
            $row->created_at = now()->subDays(120);
            $row->save();
        }

        $this->artisan('notifications:prune', ['--days' => 90])->assertSuccessful();

        $this->assertDatabaseMissing('notifications', ['id' => $readOld->id]);
        // Belum dibaca tidak pernah dihapus otomatis — badge tidak boleh tiba-
        // tiba kosong tanpa jejak.
        $this->assertDatabaseHas('notifications', ['id' => $unreadOld->id]);
        $this->assertDatabaseHas('notifications', ['id' => $readNew->id]);
    }

    public function test_clear_read_notifications_only_affects_own_read_rows(): void
    {
        Notification::create([
            'user_id' => $this->employee->id, 'title' => 'A', 'message' => 'a',
            'type' => 'info', 'read' => true,
        ]);
        $unread = Notification::create([
            'user_id' => $this->employee->id, 'title' => 'B', 'message' => 'b',
            'type' => 'info', 'read' => false,
        ]);
        $otherRead = Notification::create([
            'user_id' => $this->sa->id, 'title' => 'C', 'message' => 'c',
            'type' => 'info', 'read' => true,
        ]);

        Sanctum::actingAs($this->employee);
        $this->deleteJson('/api/notifications/read')->assertOk()->assertJson(['deleted' => 1]);

        $this->assertDatabaseMissing('notifications', ['title' => 'A', 'user_id' => $this->employee->id]);
        $this->assertDatabaseHas('notifications', ['id' => $unread->id]);
        $this->assertDatabaseHas('notifications', ['id' => $otherRead->id]);
    }
}