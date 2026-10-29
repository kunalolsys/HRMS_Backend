<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendBroadcastEmailJob;
use App\Models\BroadcastLog;
use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BroadcastController extends Controller
{
    // 1. Template List
    public function getTemplates(): JsonResponse
    {
        return response()->json(EmailTemplate::latest()->get());
    }

    // 2. Store New Template
    public function storeTemplate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title'    => 'required|string|max:255',
            'category' => 'required|string|max:100',
            'channel'  => 'required|in:email,whatsapp,telegram,all',
            'subject'  => 'nullable|string|max:255',
            'body'     => 'required|string',
        ]);

        $validated['template_code'] = 'TPL-' . rand(100, 999);

        $template = EmailTemplate::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Template created successfully',
            'data'    => $template
        ], 201);
    }

    // 3. Delete Template
    public function destroyTemplate($id): JsonResponse
    {
        $template = EmailTemplate::findOrFail($id);
        $template->delete();

        return response()->json(['success' => true, 'message' => 'Template deleted']);
    }

    // 4. Bulk Dispatching Engine (100% Dynamic)
    public function dispatchBroadcast(Request $request): JsonResponse
    {
        $request->validate([
            'channel'      => 'required|string|in:email,whatsapp,telegram',
            'target_type'  => 'required|in:all,store,designation,custom',
            'subject'      => 'required_if:channel,email|string',
            'body'         => 'required|string',
            'stores'       => 'nullable|array',
            'designations' => 'nullable|array',
            'csv_file'     => 'nullable|file|mimes:csv,txt|max:5120',
        ]);

        if ($request->channel !== 'email') {
            return response()->json([
                'success' => false,
                'message' => 'Gateway configuration needed for ' . strtoupper($request->channel)
            ], 422);
        }

        $queuedCount = 0;

        // Custom CSV Upload Handling (Header-to-key automatic mapping)
        if ($request->target_type === 'custom') {
            if (!$request->hasFile('csv_file')) {
                return response()->json(['success' => false, 'message' => 'CSV file is required for custom list'], 422);
            }

            $path = $request->file('csv_file')->getRealPath();
            $file = fopen($path, 'r');

            // CSV ke top row se column headers automatically uthayega
            $headers = fgetcsv($file);
            $cleanHeaders = array_map(function ($h) {
                return strtolower(trim(str_replace([' ', '-'], '_', $h)));
            }, $headers);

            while (($row = fgetcsv($file)) !== false) {
                if (empty($row[0])) continue;

                $recipientData = array_combine($cleanHeaders, $row);

                if (!empty($recipientData['email']) && filter_var($recipientData['email'], FILTER_VALIDATE_EMAIL)) {
                    SendBroadcastEmailJob::dispatch($recipientData, $request->subject, $request->body);
                    $queuedCount++;
                }
            }
            fclose($file);
        } else {
            // Database Audience Query with Chunking
            $query = User::query()->whereNotNull('email');

            if ($request->target_type === 'store' && !empty($request->stores)) {
                $query->whereIn('store_location', $request->stores);
            } elseif ($request->target_type === 'designation' && !empty($request->designations)) {
                $query->whereIn('designation', $request->designations);
            }

            $query->chunk(250, function ($users) use ($request, &$queuedCount) {
                foreach ($users as $user) {
                    // 1. User table ke saare columns automatically array ban jayenge
                    $recipientData = $user->toArray();

                    // 2. Common frontend aliases ensure karein
                    $recipientData['employee_name'] = $user->name ?? '';
                    $recipientData['employee_code'] = $user->employee_code ?? '';

                    // 3. Dynamic calculated fallbacks
                    $recipientData['salary_month']  = $request->salary_month ?? now()->format('F Y');
                    $recipientData['festival_name'] = $request->festival_name ?? 'Festival';

                    SendBroadcastEmailJob::dispatch($recipientData, $request->subject, $request->body);
                    $queuedCount++;
                }
            });
        }

        return response()->json([
            'success' => true,
            'message' => "Successfully queued {$queuedCount} emails for broadcast dispatch.",
            'total_queued' => $queuedCount
        ]);
    }

    // 5. Broadcast Audit Logs
    public function getLogs(): JsonResponse
    {
        $logs = BroadcastLog::latest()->paginate(50);
        return response()->json($logs);
    }
}
