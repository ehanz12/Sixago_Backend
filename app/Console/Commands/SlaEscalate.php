<?php

namespace App\Console\Commands;

use App\Models\ApprovalQueue;
use App\Models\Setting;
use App\Models\User;
use App\Support\Audits;
use App\Support\Notify;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:sla-escalate')]
#[Description('Tandai antrean approval yang melewati SLA sebagai urgent + notifikasi eskalasi')]
class SlaEscalate extends Command
{
    public function handle(): int
    {
        if (! Setting::bool('escalation_enabled', true)) {
            $this->info('Eskalasi SLA dinonaktifkan via settings.');
            return self::SUCCESS;
        }

        $overdue = ApprovalQueue::with('travelRequest.user')
            ->where('status', 'pending')
            ->where('escalated', false)
            ->whereNotNull('sla_due_at')
            ->where('sla_due_at', '<', now())
            ->get();

        foreach ($overdue as $queue) {
            $queue->update(['urgent' => true, 'escalated' => true]);
            Audits::record('approval.escalate', $queue, ['escalated' => false], ['escalated' => true]);

            $requester = $queue->travelRequest?->user;
            if ($requester) {
                Notify::user(
                    $requester,
                    "Antrean {$queue->travelRequest?->request_no} melewati SLA",
                    "Antrean level {$queue->level} melewati batas waktu dan ditandai urgent untuk percepatan.",
                    'Urgent', 'urgent', $queue->travelRequest?->request_no,
                    "Antrean melewati SLA"
                );
            }

            // Beritahu semua Super Admin agar menindaklanjuti
            foreach (User::where('role', 'Super Admin')->where('status', 'Aktif')->get() as $admin) {
                Notify::user(
                    $admin,
                    "Eskalasi SLA: {$queue->travelRequest?->request_no}",
                    "Antrean level {$queue->level} ({$queue->travelRequest?->user?->name}) melewati SLA.",
                    'Urgent', 'urgent', $queue->travelRequest?->request_no
                );
            }
        }

        $this->info("Dieskalasi: {$overdue->count()} antrean.");

        return self::SUCCESS;
    }
}
