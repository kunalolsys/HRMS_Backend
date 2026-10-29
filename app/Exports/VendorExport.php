<?php

namespace App\Exports;

use App\Models\Vendor;
use App\Exports\Concerns\FiltersTrashedExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VendorExport
{
    use FiltersTrashedExport;

    public function download(Request $request): StreamedResponse
    {
        $search = mb_strtolower(trim((string) $request->query('search', '')));
        $status = strtolower(trim((string) $request->query('status', '')));
        $showTrashed = $this->shouldExportTrashed($request);

        return response()->streamDownload(function () use ($search, $status, $showTrashed): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, ['vendor_code', 'vendor_name', 'gstin_number', 'phone_number', 'registered_address', 'brands', 'is_active']);
            Vendor::withTrashed()->with('brands')
                ->when($search !== '', function ($query) use ($search): void {
                    $query->where(function ($query) use ($search): void {
                        foreach (['vendor_code', 'vendor_name', 'gstin_number', 'phone_number'] as $column) {
                            $query->orWhereRaw("LOWER({$column}) LIKE ?", ["%{$search}%"]);
                        }
                    });
                })
                ->when(in_array($status, ['active', 'inactive', '1', '0', 'true', 'false'], true), function ($query) use ($status): void {
                    $query->where('is_active', in_array($status, ['active', '1', 'true'], true));
                })
                ->where('is_delete', $showTrashed)
                ->chunkById(500, function ($vendors) use ($output): void {
                    foreach ($vendors as $vendor) {
                        fputcsv($output, [
                            $vendor->vendor_code,
                            $vendor->vendor_name,
                            $vendor->gstin_number,
                            $vendor->phone_number,
                            $vendor->registered_address,
                            $vendor->brands->pluck('brand_name')->implode(', '),
                            $vendor->is_active ? 'true' : 'false',
                        ]);
                    }
                });
            fclose($output);
        }, 'vendors.csv', ['Content-Type' => 'text/csv']);
    }
}
