<?php

namespace App\Jobs;

use App\Mail\DynamicBroadcastMail;
use App\Models\BroadcastLog;
use App\Models\DynamicTag;
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
        $replacementMap = [];

        // 1. Recipient data ki har key ko {key} format me map karein
        foreach ($this->recipientData as $key => $value) {
            if (is_scalar($value) || is_null($value)) {
                $cleanKey = trim($key, '{}');
                $replacementMap['{' . $cleanKey . '}'] = (string) ($value ?? '');
            }
        }

        // 2. Database ke dynamic_tags check karein (agar mapped_column define hai)
        $dynamicTags = DynamicTag::all();
        foreach ($dynamicTags as $tag) {
            $label = '{' . trim($tag->key, '{}') . '}';

            if (!isset($replacementMap[$label])) {
                if ($tag->mapped_column && isset($this->recipientData[$tag->mapped_column])) {
                    $replacementMap[$label] = (string) $this->recipientData[$tag->mapped_column];
                } elseif ($tag->key === 'salary_month') {
                    $replacementMap[$label] = now()->format('F Y');
                } elseif ($tag->key === 'festival_name') {
                    $replacementMap[$label] = $this->recipientData['festival_name'] ?? 'Festival';
                } else {
                    // Agar tag banaya hai par value nahi mili, to bracket hatakar blank karein
                    $replacementMap[$label] = '';
                }
            }
        }

        // 3. Subject aur Body me replace karein
        $finalSubject = str_replace(array_keys($replacementMap), array_values($replacementMap), $this->rawSubject);
        $finalBody = nl2br(str_replace(array_keys($replacementMap), array_values($replacementMap), $this->rawBody));

        // 4. Mail Send
        Mail::to($this->recipientData['email'])->send(new DynamicBroadcastMail($finalSubject, $finalBody));

        // 5. Log Save
        BroadcastLog::create([
            'channel'         => 'email',
            'recipient_email' => $this->recipientData['email'],
            'recipient_name'  => $this->recipientData['name'] ?? null,
            'subject'         => $finalSubject,
            'status'          => 'sent',
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        BroadcastLog::create([
            'channel'         => 'email',
            'recipient_email' => $this->recipientData['email'],
            'recipient_name'  => $this->recipientData['name'] ?? null,
            'subject'         => $this->rawSubject,
            'status'          => 'failed',
            'error_message'   => $exception ? $exception->getMessage() : 'Unknown error',
        ]);
    }
}