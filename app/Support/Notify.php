<?php

namespace App\Support;

use App\Mail\DecisionMail;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class Notify
{
    /**
     * Buat notifikasi dalam aplikasi + kirim email (bila user mengizinkan).
     */
    public static function user(
        User $user,
        string $title,
        string $message,
        string $type = 'System',
        string $category = 'system',
        ?string $referenceId = null,
        ?string $emailSubject = null,
        ?string $ctaUrl = null,
    ): Notification {
        $notif = Notification::create([
            'user_id' => $user->id,
            'title' => $title,
            'message' => $message,
            'type' => $type,
            'category' => $category,
            'reference_id' => $referenceId,
        ]);

        if ($user->email_notif_enabled && filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            Mail::to($user->email)->queue(new DecisionMail(
                subjectLine: $emailSubject ?? $title,
                greeting: "Halo {$user->name},",
                body: strip_tags($message),
                ctaUrl: $ctaUrl,
                ctaLabel: 'Buka SixAgo',
            ));
        }

        return $notif;
    }
}
