<?php

namespace App\Exports;

use App\Models\Brand;
use App\Exports\Concerns\FiltersTrashedExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BrandExport
{
    use FiltersTrashedExport;

    public function download(Request $request): StreamedResponse
    {
        $search = mb_strtolower(trim((string) $request->query('search', '')));
        $status = strtolower(trim((string) $request->query('status', '')));
        $showTrashed = $this->shouldExportTrashed($request);

        return response()->streamDownload(function () use ($search, $status, $showTrashed): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, ['brand_code', 'brand_name', 'divisions', 'is_active']);

            $query = Brand::withTrashed()->with('divisions')
                ->when($search !== '', function ($query) use ($search): void {
                    $query->where(function ($query) use ($search): void {
                        $query->whereRaw('LOWER(brand_code) LIKE ?', ["%{$search}%"])
                            ->orWhereRaw('LOWER(brand_name) LIKE ?', ["%{$search}%"]);
                    });
                })
                ->when(in_array($status, ['active', 'inactive', '1', '0', 'true', 'false'], true), function ($query) use ($status): void {
                    $isActive = in_array($status, ['active', '1', 'true'], true);
                    $query->where('is_active', $isActive);
                })
                ->where('is_delete', $showTrashed);

            $query->chunkById(500, function ($brands) use ($output): void {
                foreach ($brands as $brand) {
                    fputcsv($output, [
                        $brand->brand_code,
                        $brand->brand_name,
                        $brand->divisions->pluck('name')->implode(', '),
                        $brand->is_active ? 'true' : 'false',
                    ]);
                }
            });

            fclose($output);
        }, 'brands.csv', ['Content-Type' => 'text/csv']);
    }
}
