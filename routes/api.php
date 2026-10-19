<?php

use App\Http\Controllers\Api\AdvanceController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\TravelDocumentController;
use App\Http\Controllers\Api\ApprovalLevelController;
use App\Http\Controllers\Api\ApprovalQueueController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DelegationController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ReimbursementController;
use App\Http\Controllers\Api\TravelPolicyController;
use App\Http\Controllers\Api\TravelRequestController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/logout-all', [AuthController::class, 'logoutAll']);
    Route::post('/user/password', [AuthController::class, 'changePassword']);
    Route::get('/user', [AuthController::class, 'user']);

    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
    Route::get('/dashboard/sidebar-badges', [DashboardController::class, 'sidebarBadges']);
    Route::get('/dashboard/charts', [DashboardController::class, 'charts']);

    // Travel requests + batch + summary (KPI tabs)
    // Batch approve = tindakan approval → hanya L1/L2/SA (+ cek level per item di controller)
    Route::post('/travel-requests/batch-approve', [TravelRequestController::class, 'batchApprove'])
        ->middleware('role:Line Manager,Division Head,Super Admin');
    Route::get('/travel-requests-summary', [TravelRequestController::class, 'summary']);
    Route::get('/travel-requests/no/{requestNo}', [TravelRequestController::class, 'byRequestNo']);
    Route::apiResource('travel-requests', TravelRequestController::class);

    // Approval queue + keputusan + batch (level-aware, cek di controller)
    Route::post('/approval-queue/batch-approve', [ApprovalQueueController::class, 'batchApprove'])
        ->middleware('role:Line Manager,Division Head,Finance Approver,Super Admin');
    Route::post('/approval-queue/{approvalQueue}/decide', [ApprovalQueueController::class, 'decide'])
        ->middleware('role:Line Manager,Division Head,Finance Approver,Super Admin');
    // Baris antrean dibuat oleh ApprovalChain::build, bukan lewat HTTP. store/update/destroy
    // hanya dibiarkan untuk Super Admin sebagai jalur perbaikan manual — tanpa ini user mana
    // pun bisa menghapus/mengubah antrean sehingga hasil approval berubah.
    Route::apiResource('approval-queue', ApprovalQueueController::class)
        ->only(['index', 'show'])
        ->parameters(['approval-queue' => 'approvalQueue']);
    Route::apiResource('approval-queue', ApprovalQueueController::class)
        ->only(['store', 'update', 'destroy'])
        ->parameters(['approval-queue' => 'approvalQueue'])
        ->middleware('role:Super Admin');

    // Reimbursement + verifikasi + summary (verify hanya Finance)
    Route::post('/reimbursements/{reimbursement}/verify', [ReimbursementController::class, 'verify'])
        ->middleware('role:Finance Approver,Super Admin');
    Route::get('/reimbursements-summary', [ReimbursementController::class, 'summary']);
    Route::get('/reimbursements/no/{requestNo}', [ReimbursementController::class, 'byRequestNo']);
    // store tetap terbuka untuk semua karena pemohon sendiri yang mengajukan klaim;
    // update/destroy dipecah ke middleware Finance — sebelumnya keduanya
    // terbuka tanpa cek akses.
    Route::apiResource('reimbursements', ReimbursementController::class)->only(['index', 'show', 'store']);
    Route::put('/reimbursements/{reimbursement}', [ReimbursementController::class, 'update'])
        ->middleware('role:Finance Approver,Super Admin');
    Route::delete('/reimbursements/{reimbursement}', [ReimbursementController::class, 'destroy'])
        ->middleware('role:Finance Approver,Super Admin');

    // Advances + keputusan + disburse + summary (hanya Finance)
    Route::post('/advances/{advance}/decide', [AdvanceController::class, 'decide'])
        ->middleware('role:Finance Approver,Super Admin');
    Route::post('/advances/{advance}/disburse', [AdvanceController::class, 'disburse'])
        ->middleware('role:Finance Approver,Super Admin');
    Route::get('/advances-summary', [AdvanceController::class, 'summary']);
    Route::get('/advances/no/{requestNo}', [AdvanceController::class, 'byRequestNo']);
    // store tetap terbuka untuk semua karena pemohon sendiri yang mengajukan
    // uang muka; update/destroy dipecah ke middleware Finance karena
    // sebelumnya keduanya terbuka tanpa cek akses — siapa pun yang login bisa
    // mengubah atau menghapus advance/klaim milik siapa saja.
    Route::apiResource('advances', AdvanceController::class)->only(['index', 'show', 'store']);
    Route::put('/advances/{advance}', [AdvanceController::class, 'update'])
        ->middleware('role:Finance Approver,Super Admin');
    Route::delete('/advances/{advance}', [AdvanceController::class, 'destroy'])
        ->middleware('role:Finance Approver,Super Admin');

    // Users management (hanya Super Admin yang boleh tulis)
    Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])
        ->middleware('role:Super Admin');
    Route::patch('/users/{user}/toggle-status', [UserController::class, 'toggleStatus'])
        ->middleware('role:Super Admin');
    // Didefinisikan SEBELUM show agar tidak tertangkap parameter {user}.
    Route::get('/users-summary', [UserController::class, 'summary'])
        ->middleware('role:Super Admin');
    Route::apiResource('users', UserController::class)->only(['index', 'show']);
    Route::apiResource('users', UserController::class)->only(['store', 'update', 'destroy'])
        ->middleware('role:Super Admin');
    Route::post('/users/import', [UserController::class, 'import'])
        ->middleware('role:Super Admin');

    // Travel policies (tulis: Super Admin)
    // Didefinisikan SEBELUM apiResource agar tidak tertangkap parameter {travelPolicy}.
    Route::get('/travel-policies/grade-coverage', [TravelPolicyController::class, 'gradeCoverage']);
    Route::get('/travel-policies/applicable', [TravelPolicyController::class, 'applicable']);
    Route::patch('/travel-policies/{travelPolicy}/toggle', [TravelPolicyController::class, 'toggle'])
        ->middleware('role:Super Admin');
    Route::apiResource('travel-policies', TravelPolicyController::class)->only(['index', 'show']);
    Route::apiResource('travel-policies', TravelPolicyController::class)->only(['store', 'update', 'destroy'])
        ->middleware('role:Super Admin');

    // Projects + SAP sync (sync: Finance; tulis: Super Admin)
    Route::post('/projects/sync-sap', [ProjectController::class, 'syncSap'])
        ->middleware('role:Finance Approver,Super Admin');
    Route::apiResource('projects', ProjectController::class)->only(['index', 'show']);
    Route::apiResource('projects', ProjectController::class)->only(['store', 'update', 'destroy'])
        ->middleware('role:Super Admin');

    // Departments + role matrix (roles tulis: Super Admin; dept tulis: SA, budget: DH sendiri dicek di controller)
    Route::get('/roles', [DepartmentController::class, 'roles']);
    Route::post('/roles', [DepartmentController::class, 'storeRole'])
        ->middleware('role:Super Admin');
    Route::put('/roles/{role}', [DepartmentController::class, 'updateRole'])
        ->middleware('role:Super Admin');
    Route::delete('/roles/{role}', [DepartmentController::class, 'destroyRole'])
        ->middleware('role:Super Admin');
    // Daftar anggota — daftar direktori, jadi Super Admin saja (sama dengan
    // store/destroy). Controller tetap memfilter visibleUserIds() sebagai
    // jaring kedua.
    Route::get('/departments/{department}/members', [DepartmentController::class, 'members'])
        ->middleware('role:Super Admin');
    Route::apiResource('departments', DepartmentController::class)->only(['index', 'show']);
    Route::apiResource('departments', DepartmentController::class)->only(['store', 'destroy'])
        ->middleware('role:Super Admin');
    // Update: Super Admin, atau Division Head untuk departemennya sendiri (dicek di controller)
    Route::put('/departments/{department}', [DepartmentController::class, 'update']);
    Route::patch('/departments/{department}', [DepartmentController::class, 'update']);

    // Delegations + revoke
    Route::post('/delegations/{delegation}/revoke', [DelegationController::class, 'revoke']);
    Route::apiResource('delegations', DelegationController::class);

    // Approval schemes & levels + simulasi (tulis: Super Admin)
    Route::get('/approval-schemes', [ApprovalLevelController::class, 'schemes']);
    Route::post('/approval-schemes', [ApprovalLevelController::class, 'storeScheme'])
        ->middleware('role:Super Admin');
    Route::post('/approval-schemes/{scheme}/activate', [ApprovalLevelController::class, 'activateScheme'])
        ->middleware('role:Super Admin');
    Route::put('/approval-schemes/{scheme}', [ApprovalLevelController::class, 'updateScheme'])
        ->middleware('role:Super Admin');
    Route::delete('/approval-schemes/{scheme}', [ApprovalLevelController::class, 'destroyScheme'])
        ->middleware('role:Super Admin');
    Route::post('/approval-simulate', [ApprovalLevelController::class, 'simulate']);
    Route::apiResource('approval-levels', ApprovalLevelController::class)->only(['index', 'show']);
    Route::apiResource('approval-levels', ApprovalLevelController::class)->only(['store', 'update', 'destroy'])
        ->middleware('role:Super Admin');

    // Travel documents (tab Dokumen + bukti nota)
    Route::apiResource('travel-documents', TravelDocumentController::class)->only(['index', 'store', 'show', 'update', 'destroy']);

    // Export CSV (tombol Export di FE)
    Route::get('/exports/travel-requests', [ExportController::class, 'travelRequests']);
    Route::get('/exports/advances', [ExportController::class, 'advances']);
    Route::get('/exports/reimbursements', [ExportController::class, 'reimbursements']);
    Route::get('/exports/users', [ExportController::class, 'users'])
        ->middleware('role:Super Admin');
    Route::get('/exports/departments', [ExportController::class, 'departments']);
    Route::get('/exports/projects', [ExportController::class, 'projects']);
    Route::get('/exports/travel-policies', [ExportController::class, 'travelPolicies']);
    Route::get('/exports/audit-logs', [ExportController::class, 'auditLogs'])
        ->middleware('role:Super Admin');

    // Audit log (approver & finance bisa baca; dipakai stream audit delegasi & riwayat)
    Route::get('/audit-logs', [AuditLogController::class, 'index'])
        ->middleware('role:Line Manager,Division Head,Finance Approver,Super Admin');

    // Pengaturan global eskalasi/delegasi (Super Admin)
    Route::get('/settings', [SettingController::class, 'index'])
        ->middleware('role:Super Admin');
    Route::put('/settings', [SettingController::class, 'update'])
        ->middleware('role:Super Admin');

    // Profil sendiri (nama, whatsapp, preferensi email)
    Route::put('/user/profile', [UserController::class, 'updateProfile']);

    // Notifications
    Route::patch('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllRead']);
    Route::get('/notifications-unread-count', [NotificationController::class, 'unreadCount']);
    // DELETE /notifications/read harus didaftarkan SEBELUM apiResource,
    // kalau tidak akan tertangkap parameter {notification} dan dianggap
    // notifikasi dengan id "read".
    Route::delete('/notifications/read', [NotificationController::class, 'clearRead']);
    Route::apiResource('notifications', NotificationController::class)->only(['index', 'show', 'update', 'destroy']);
    // Notifikasi manual hanya oleh approver (dipakai alur revisi); notifikasi sistem dibuat otomatis
    Route::apiResource('notifications', NotificationController::class)->only(['store'])
        ->middleware('role:Line Manager,Division Head,Finance Approver,Super Admin');
});
