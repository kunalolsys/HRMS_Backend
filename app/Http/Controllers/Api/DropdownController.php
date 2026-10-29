<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Designation;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Location;
use App\Models\Shift;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Department;
use App\Models\NoticePeriod;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role as SpatieRole;
use Illuminate\Support\Facades\DB;

class DropdownController extends Controller
{
    /**
     * Get active, non-deleted brands for dropdowns.
     */
    public function brands(): JsonResponse
    {
        $brands = Brand::query()
            ->where('is_delete', false)
            ->where('is_active', true)
            ->orderBy('brand_name')
            ->get(['id', 'brand_code', 'brand_name']);

        return response()->json([
            'success' => true,
            'message' => 'Brands dropdown retrieved successfully.',
            'data' => $brands,
        ]);
    }

    /**
     * Get active, non-deleted divisions for dropdowns.
     */
    public function divisions(): JsonResponse
    {
        $divisions = Division::query()
            ->where('is_delete', false)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'success' => true,
            'message' => 'Divisions dropdown retrieved successfully.',
            'data' => $divisions,
        ]);
    }

    /**
     * Get active, non-deleted cost centers for dropdowns.
     */
    public function costCenters(): JsonResponse
    {
        $costCenters = CostCenter::query()
            ->where('is_delete', false)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        return response()->json([
            'success' => true,
            'message' => 'Cost centers dropdown retrieved successfully.',
            'data' => $costCenters,
        ]);
    }

    /**
     * Get active, non-deleted designations for dropdowns.
     */
    public function designations(): JsonResponse
    {
        $designations = Designation::query()
            ->where('is_delete', false)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        return response()->json([
            'success' => true,
            'message' => 'Designations dropdown retrieved successfully.',
            'data' => $designations,
        ]);
    }

    /**
     * Get active, non-deleted notice periods for dropdowns.
     */
    public function noticePeriods(): JsonResponse
    {
        $noticePeriods = NoticePeriod::with(['designation:id,code,name'])
            ->where('is_delete', false)
            ->where('is_active', true)
            ->get(['id', 'designation_id', 'notice_period_days', 'probation_notice_period_days']);

        return response()->json([
            'success' => true,
            'message' => 'Notice periods dropdown retrieved successfully.',
            'data' => $noticePeriods,
        ]);
    }

    /**
     * Get active, non-deleted shifts for dropdowns.
     */
    public function shifts(): JsonResponse
    {
        $shifts = Shift::query()
            ->where('is_delete', false)
            ->where('is_active', true)
            ->orderBy('shift_name')
            ->get(['id', 'shift_code', 'shift_name']);

        return response()->json([
            'success' => true,
            'message' => 'Shifts dropdown retrieved successfully.',
            'data' => $shifts,
        ]);
    }

    /**
     * Get active, non-deleted holidays for dropdowns.
     */
    public function holidays(Request $request): JsonResponse
    {
        $year  = $request->query('year');
        $month = $request->query('month');

        $holidays = Holiday::query()
            ->where('is_delete', false)
            ->where('is_active', true)
            ->when($year && $month, fn ($q) => $q->forMonth((int) $year, (int) $month))
            ->when($year && ! $month, fn ($q) => $q->forYear((int) $year))
            ->orderBy('holiday_date', 'asc')
            ->get(['id', 'holiday_date', 'holiday_title', 'holiday_type']);

        return response()->json([
            'success' => true,
            'message' => 'Holidays dropdown retrieved successfully.',
            'data'    => $holidays,
        ]);
    }

    /**
     * Get active, non-deleted locations for dropdowns.
     */
    public function locations(): JsonResponse
    {
        $locations = Location::query()
            ->where('is_delete', false)
            ->where('is_active', true)
            ->orderBy('location_name')
            ->get(['id', 'location_code', 'location_name']);

        return response()->json([
            'success' => true,
            'message' => 'Locations dropdown retrieved successfully.',
            'data' => $locations,
        ]);
    }

    /**
     * Get active, non-deleted companies for dropdowns.
     */
    public function companies(): JsonResponse
    {
        $companies = Company::query()
            ->where('is_delete', false)
            ->where('is_active', true)
            ->orderBy('company_name')
            ->get(['id', 'company_code', 'company_name']);

        return response()->json([
            'success' => true,
            'message' => 'Companies dropdown retrieved successfully.',
            'data' => $companies,
        ]);
    }

    /**
     * Get active, non-deleted vendors for dropdowns, optionally filtered by brand_id.
     */
    public function vendors(Request $request): JsonResponse
    {
        $brandId = $request->query('brand_id');

        $vendors = Vendor::query()
            ->where('is_delete', false)
            ->where('is_active', true)
            ->when($brandId, function ($query) use ($brandId) {
                $query->whereHas('brands', function ($q) use ($brandId) {
                    $q->where('brands.id', $brandId)
                      ->where('brands.is_delete', false)
                      ->where('brands.is_active', true);
                });
            })
            ->orderBy('vendor_name')
            ->get(['id', 'vendor_code', 'vendor_name']);

        return response()->json([
            'success' => true,
            'message' => 'Vendors dropdown retrieved successfully.',
            'data' => $vendors,
        ]);
    }

    /**
     * Get active, non-deleted vendors for a specific brand ID.
     */
    public function vendorsByBrand(int $brandId): JsonResponse
    {
        $vendors = Vendor::query()
            ->where('is_delete', false)
            ->where('is_active', true)
            ->whereHas('brands', function ($q) use ($brandId) {
                $q->where('brands.id', $brandId)
                  ->where('brands.is_delete', false)
                  ->where('brands.is_active', true);
            })
            ->orderBy('vendor_name')
            ->get(['id', 'vendor_code', 'vendor_name']);

        return response()->json([
            'success' => true,
            'message' => 'Vendors dropdown retrieved successfully.',
            'data' => $vendors,
        ]);
    }

    /**
     * Get active, non-deleted roles for dropdowns.
     */
    public function roles(): JsonResponse
    {
        $roles = Role::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'success' => true,
            'message' => 'Roles dropdown retrieved successfully.',
            'data' => $roles,
        ]);
    }
    /**
     * Get active, non-deleted users for dropdowns.
     */
    public function users(): JsonResponse
    {
        $users = User::query()
            ->where('is_delete', false)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'success' => true,
            'message' => 'Users dropdown retrieved successfully.',
            'data' => $users,
        ]);
    }
    /**
     * Get active, non-deleted departments for dropdowns.
     */
    public function departments(): JsonResponse
    {
        $departments = Department::query()
            ->where('is_delete', false)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        return response()->json([
            'success' => true,
            'message' => 'Departments dropdown retrieved successfully.',
            'data' => $departments,
        ]);
    }

    /**
     * Get active, non-deleted employees for dropdowns.
     */
    public function employees(): JsonResponse
    {
        $employees = Employee::query()
            ->where('is_delete', false)
            ->where('is_active', true)
            ->orderBy('employee_name')
            ->get(['id', 'employee_code', 'employee_name']);

        return response()->json([
            'success' => true,
            'message' => 'Employees dropdown retrieved successfully.',
            'data' => $employees,
        ]);
    }

    /**
     * Get active permissions grouped by title_name ({title_name}_{action}) for dropdowns.
     */
    public function permissions(Request $request): JsonResponse
    {
        $permissions = Permission::query()
            ->where('is_delete', false)
            ->where('is_active', true)
            ->when($request->filled('guard_name'), function ($query) use ($request): void {
                $query->where('guard_name', $request->query('guard_name'));
            })
            ->orderBy('title_name')
            ->orderBy('name')
            ->get(['id', 'name', 'title_name', 'guard_name'])
            ->groupBy(function ($item) {
                return $item->title_name ?: 'other';
            })
            ->map(function ($items, $titleName) {
                return [
                    'title_name'  => $titleName,
                    'permissions' => $items->map(function ($item) use ($titleName) {
                        $action = str_starts_with($item->name, $titleName . '_')
                            ? substr($item->name, strlen($titleName) + 1)
                            : (str_contains($item->name, '_') ? substr($item->name, strpos($item->name, '_') + 1) : $item->name);

                        return [
                            'id'         => $item->id,
                            'name'       => $item->name,
                            'action'     => $action,
                            'guard_name' => $item->guard_name,
                        ];
                    })->values(),
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Permissions dropdown retrieved successfully.',
            'data'    => $permissions,
        ]);
    }

    /**
     * Get all active dropdowns in a single request.
     */
    public function all(): JsonResponse
    {
        // Cache the result for 10 minutes to improve performance
        $data = Cache::remember('all_dropdowns', now()->addMinutes(10), function () {
            return [
                'brands' => Brand::query()
                    ->where('is_delete', false)->where('is_active', true)
                    ->orderBy('brand_name')->get(['id', 'brand_name']),

                'divisions' => Division::query()
                    ->where('is_delete', false)->where('is_active', true)
                    ->orderBy('name')->get(['id', 'name']),

                'cost_centers' => CostCenter::query()
                    ->where('is_delete', false)->where('is_active', true)
                    ->orderBy('name')->get(['id', 'name']),

                'designations' => Designation::query()
                    ->where('is_delete', false)->where('is_active', true)
                    ->orderBy('name')->get(['id', 'name']),

                'notice_periods' => NoticePeriod::with(['designation:id,code,name'])
                    ->where('is_delete', false)->where('is_active', true)
                    ->get(['id', 'designation_id', 'notice_period_days', 'probation_notice_period_days']),

                'shifts' => Shift::query()
                    ->where('is_delete', false)->where('is_active', true)
                    ->orderBy('shift_name')->get(['id', 'shift_name']),

                'locations' => Location::query()
                    ->where('is_delete', false)->where('is_active', true)
                    ->orderBy('location_name')->get(['id', 'location_name']),

                'reporting_managers' => User::query()
                    ->where('is_delete', false)->where('is_active', true)
                    ->orderBy('name')->get(['id', 'name']),

                'employees' => Employee::query()
                    ->where('is_delete', false)->where('is_active', true)
                    ->orderBy('employee_name')->get(['id', 'employee_code', 'employee_name']),

                'holidays' => Holiday::query()
                    ->where('is_delete', false)->where('is_active', true)
                    ->orderBy('holiday_date', 'asc')->get(['id', 'holiday_date', 'holiday_title', 'holiday_type']),
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'All dropdowns retrieved successfully.',
            'data' => $data,
        ]);
    }


    public function getUsers($id)
    {
        try {
            $role = SpatieRole::select('id')->find($id);

            if (!$role) {
                return response()->json([
                    'success' => false,
                    'message' => 'Role not found.',
                ], 404);
            }

            $users = DB::table('model_has_roles')
                ->join('users', 'users.id', '=', 'model_has_roles.model_id')
                ->where('model_has_roles.role_id', $role->id)
                ->where('model_has_roles.model_type', User::class)
                ->select('users.id', 'users.name')
                ->orderBy('users.name')
                ->get();

            return response()->json([
                'users' => $users,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while retrieving users for the role.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
