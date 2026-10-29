<?php

namespace App\Jobs;

use App\Mail\DynamicBroadcastMail;
use App\Models\BroadcastLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendBroadcastEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    protected array $recipientData;
    protected string $rawSubject;
    protected string $rawBody;

    public function __construct(array $recipientData, string $rawSubject, string $rawBody)
    {
        $this->recipientData = $recipientData;
        $this->rawSubject = $rawSubject;
        $this->rawBody = $rawBody;
    }

    public function handle(): void
    {
        $subject = $this->rawSubject;
        $body = $this->rawBody;

        $replacementMap = [];

        // 1. RecipientData ke har field ko {key} format me dynamic map karein
        foreach ($this->recipientData as $key => $value) {
            if (is_scalar($value) || is_null($value)) {
                $cleanKey = strtolower(trim($key, '{} '));
                $replacementMap['{' . $cleanKey . '}'] = (string) ($value ?? '');
            }
        }

        // 2. Extra safety mappings
        if (isset($this->recipientData['email'])) {
            $replacementMap['{email}'] = (string) $this->recipientData['email'];
        }

        if (!isset($replacementMap['{salary_month}'])) {
            $replacementMap['{salary_month}'] = now()->format('F Y');
        }

        // 3. Case-Insensitive replacement (taaki {Email}, {EMAIL}, {email} sab replace ho jayein)
        foreach ($replacementMap as $tag => $val) {
            $subject = str_ireplace($tag, $val, $subject);
            $body = str_ireplace($tag, $val, $body);
        }

        $finalSubject = $subject;
        $finalBody = nl2br($body);

        // 4. Mail Dispatch
        $recipientEmail = $this->recipientData['email'] ?? null;

        if ($recipientEmail && filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            Mail::to($recipientEmail)->send(new DynamicBroadcastMail($finalSubject, $finalBody));

            // Audit log create karein
            BroadcastLog::create([
                'channel'         => 'email',
                'recipient_email' => $recipientEmail,
                'recipient_name'  => $this->recipientData['name'] ?? $this->recipientData['employee_name'] ?? null,
                'subject'         => $finalSubject,
                'status'          => 'sent',
            ]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        BroadcastLog::create([
            'channel'         => 'email',
            'recipient_email' => $this->recipientData['email'] ?? 'unknown',
            'recipient_name'  => $this->recipientData['name'] ?? $this->recipientData['employee_name'] ?? null,
            'subject'         => $this->rawSubject,
            'status'          => 'failed',
            'error_message'   => $exception ? $exception->getMessage() : 'Queue execution failed',
        ]);
    }
}
