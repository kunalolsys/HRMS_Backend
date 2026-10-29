<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\DivisionController;
use App\Http\Controllers\Api\CostCenterController;
use App\Http\Controllers\Api\VendorController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\StoreWeeklyOffController;
use App\Http\Controllers\Api\ShiftController;
use App\Http\Controllers\Api\DesignationController;
use App\Http\Controllers\Api\DropdownController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\PermissionAssignmentController;
use App\Http\Controllers\Api\LateDeductionSlabController;
use App\Http\Controllers\Api\ShortLeaveDeductionSlabController;
use App\Http\Controllers\Api\StatutoryParameterMasterController;
use App\Http\Controllers\Api\MinimumWageMasterController;
use App\Http\Controllers\Api\NoticePeriodController;
use App\Http\Controllers\Api\EmployeeAttendanceController;
use App\Http\Controllers\Api\HolidayController;
use App\Http\Controllers\Api\CommunicationController;
use Illuminate\Support\Facades\Broadcast;
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Broadcast::routes(['middleware' => ['auth:sanctum']]);
// Import Error Routes
Route::get('/brands/import/errors/{filename}', [BrandController::class, 'importErrors'])
    ->name('brands.import.errors');
Route::get('/vendors/import/errors/{filename}', [VendorController::class, 'importErrors'])
    ->name('vendors.import.errors');
Route::get('/locations/import/errors/{filename}', [LocationController::class, 'importErrors'])
    ->name('locations.import.errors');
Route::get('/cost-centers/import/errors/{filename}', [CostCenterController::class, 'importErrors'])
    ->name('cost-centers.import.errors');
Route::get('/companies/import/errors/{filename}', [CompanyController::class, 'importErrors'])
    ->name('companies.import.errors');
Route::get('/divisions/import/errors/{filename}', [DivisionController::class, 'importErrors'])
    ->name('divisions.import.errors');
Route::get('/shifts/import/errors/{filename}', [ShiftController::class, 'importErrors'])
    ->name('shifts.import.errors');
Route::get('/designations/import/errors/{filename}', [DesignationController::class, 'importErrors'])
    ->name('designations.import.errors');
Route::get('/employees/import/errors/{filename}', [EmployeeController::class, 'importErrors'])
    ->name('employees.import.errors');
Route::get('/store-weekly-offs/import/errors/{filename}', [StoreWeeklyOffController::class, 'importErrors'])
    ->name('store-weekly-offs.import.errors');
Route::get('/late-deduction-slabs/import/errors/{filename}', [LateDeductionSlabController::class, 'importErrors'])
    ->name('late-deduction-slabs.import.errors');
Route::get('/short-leave-deduction-slabs/import/errors/{filename}', [ShortLeaveDeductionSlabController::class, 'importErrors'])
    ->name('short-leave-deduction-slabs.import.errors');
Route::get('/statutory-parameter-masters/import/errors/{filename}', [StatutoryParameterMasterController::class, 'importErrors'])
    ->name('statutory-parameter-masters.import.errors');
Route::get('/minimum-wage-masters/import/errors/{filename}', [MinimumWageMasterController::class, 'importErrors'])
    ->name('minimum-wage-masters.import.errors');
Route::get('/notice-periods/import/errors/{filename}', [NoticePeriodController::class, 'importErrors'])
    ->name('notice-periods.import.errors');
Route::get('/employee-attendances/import/errors/{filename}', [EmployeeAttendanceController::class, 'importErrors'])
    ->name('employee-attendances.import.errors');
Route::get('/holidays/import/errors/{filename}', [HolidayController::class, 'importErrors'])
    ->name('holidays.import.errors');

Route::middleware('auth:sanctum')->group(function () {
    
    // Auth related (logout)
    Route::post('/logout', [AuthController::class, 'logout']);
    
    // All users fetch karne ke liye
    Route::get('/users', [UserController::class, 'index']);
    
    // Single user detail ke liye (e.g., /api/users/1)
    Route::get('/users/{id}', [UserController::class, 'show']);

    // Fetch permissions assigned directly to a user.
    Route::get('/users/{id}/permissions', [UserController::class, 'directPermissions']);

    // User update karne ke liye
    Route::put('/users/{id}', [UserController::class, 'update']);

    // Refresh Token Route
    Route::get('/refresh-token', [AuthController::class, 'refreshToken']);
    
    // Example: Kisi specific function ka route
    // Route::get('/profile', [UserController::class, 'profile']);
    // Role resource routes (CRUD)
    Route::apiResource('/roles', RoleController::class);
    Route::get('/roles/{id}/permissions', [RoleController::class, 'getPermissions']);
    Route::post('/roles/{id}/permissions', [RoleController::class, 'syncPermissions']);
    Route::post('/roles/{id}/role-permissions', [RoleController::class, 'syncRolePermissions']);

    // Permission resource routes (CRUD)
    Route::put('/permissions/by-title/{title_name?}', [PermissionController::class, 'updateByTitleName']);
    Route::delete('/permissions/by-title/{title_name}/soft-delete', [PermissionController::class, 'softDeleteByTitle']);
    Route::get('/permissions/getall', [PermissionController::class, 'getGroupedPermissions']);
    Route::apiResource('/permissions', PermissionController::class);
    Route::post('/permissions/{id}/roles', [PermissionController::class, 'syncRoles']);

    // Permission Assignment Routes (New Controller)
    Route::post('/roles/{roleId}/sync-permissions', [PermissionAssignmentController::class, 'syncRolePermissions']);
    Route::post('/users/{userId}/sync-roles', [PermissionAssignmentController::class, 'syncUserRoles']);
    Route::post('/users/{userId}/sync-direct-permissions', [PermissionAssignmentController::class, 'syncUserDirectPermissions']);
    Route::post('/users/{userId}/sync-permissions', [PermissionAssignmentController::class, 'syncUserPermissions']);

    // Company resource routes (CRUD) - Existing routes below
    Route::post('/companies/import', [CompanyController::class, 'import']);
    Route::get('/companies/import/structure', [CompanyController::class, 'importStructure'])
        ->name('companies.import.structure');
    Route::get('/companies/import/template', [CompanyController::class, 'importTemplate'])
        ->name('companies.import.template');
    Route::get('/companies/export', [CompanyController::class, 'export']);
    Route::post('/companies/{id}/restore', [CompanyController::class, 'restore']);
    Route::apiResource('/companies', CompanyController::class);

    // Division import/export routes must be registered before the resource route.
    Route::post('/divisions/import', [DivisionController::class, 'import']);
    Route::get('/divisions/import/structure', [DivisionController::class, 'importStructure'])
        ->name('divisions.import.structure');
    Route::get('/divisions/import/template', [DivisionController::class, 'importTemplate'])
        ->name('divisions.import.template');
    Route::get('/divisions/export', [DivisionController::class, 'export']);
    Route::post('/divisions/{id}/restore', [DivisionController::class, 'restore']);

    // Division resource routes (CRUD)
    Route::apiResource('/divisions', DivisionController::class);

    // Brand import/export routes must be registered before the resource route.
    Route::post('/brands/import', [BrandController::class, 'import']);
    Route::get('/brands/import/structure', [BrandController::class, 'importStructure'])
        ->name('brands.import.structure');
    Route::get('/brands/import/template', [BrandController::class, 'importTemplate'])
        ->name('brands.import.template');
    Route::get('/brands/export', [BrandController::class, 'export']);
    Route::post('/brands/{id}/restore', [BrandController::class, 'restore']);

    // Brand resource routes (CRUD)
    Route::apiResource('/brands', BrandController::class);

    // Vendor import/export routes must be registered before the resource route.
    Route::post('/vendors/import', [VendorController::class, 'import']);
    Route::get('/vendors/import/structure', [VendorController::class, 'importStructure'])
        ->name('vendors.import.structure');
    Route::get('/vendors/import/template', [VendorController::class, 'importTemplate'])
        ->name('vendors.import.template');
    Route::get('/vendors/export', [VendorController::class, 'export']);
    Route::post('/vendors/{id}/restore', [VendorController::class, 'restore']);

    // Vendor resource routes (CRUD)
    Route::apiResource('/vendors', VendorController::class);

    // Cost Center resource routes (CRUD)
    Route::post('/cost-centers/import', [CostCenterController::class, 'import']);
    Route::get('/cost-centers/import/structure', [CostCenterController::class, 'importStructure'])
        ->name('cost-centers.import.structure');
    Route::get('/cost-centers/import/template', [CostCenterController::class, 'importTemplate'])
        ->name('cost-centers.import.template');
    Route::get('/cost-centers/export', [CostCenterController::class, 'export']);
    Route::post('/cost-centers/{id}/restore', [CostCenterController::class, 'restore']);
    Route::apiResource('/cost-centers', CostCenterController::class);

    // Department resource routes (CRUD)
    Route::apiResource('/departments', DepartmentController::class);

    // Location import/export routes must be registered before the resource route.
    Route::post('/locations/import', [LocationController::class, 'import']);
    Route::get('/locations/import/structure', [LocationController::class, 'importStructure'])
        ->name('locations.import.structure');
    Route::get('/locations/import/template', [LocationController::class, 'importTemplate'])
        ->name('locations.import.template');
    Route::get('/locations/export', [LocationController::class, 'export']);
    Route::post('/locations/{id}/restore', [LocationController::class, 'restore']);

    // Location resource routes (CRUD)
    Route::apiResource('/locations', LocationController::class);

    // Store Weekly Off resource routes (CRUD)
    Route::post('/store-weekly-offs/import', [StoreWeeklyOffController::class, 'import']);
    Route::get('/store-weekly-offs/import/structure', [StoreWeeklyOffController::class, 'importStructure'])
        ->name('store-weekly-offs.import.structure');
    Route::get('/store-weekly-offs/import/template', [StoreWeeklyOffController::class, 'importTemplate'])
        ->name('store-weekly-offs.import.template');
    Route::get('/store-weekly-offs/export', [StoreWeeklyOffController::class, 'export']);
    Route::post('/store-weekly-offs/{id}/restore', [StoreWeeklyOffController::class, 'restore']);
    Route::apiResource('/store-weekly-offs', StoreWeeklyOffController::class);

    // Shift import/export routes must be registered before the resource route.
    Route::post('/shifts/import', [ShiftController::class, 'import']);
    Route::get('/shifts/import/structure', [ShiftController::class, 'importStructure'])
        ->name('shifts.import.structure');
    Route::get('/shifts/import/template', [ShiftController::class, 'importTemplate'])
        ->name('shifts.import.template');
    Route::get('/shifts/export', [ShiftController::class, 'export']);
    Route::post('/shifts/{id}/restore', [ShiftController::class, 'restore']);

    Route::apiResource('shifts', ShiftController::class);

    // Designation resource routes (CRUD)
    Route::post('/designations/import', [DesignationController::class, 'import']);
    Route::get('/designations/import/template', [DesignationController::class, 'importTemplate'])
        ->name('designations.import.template');
    Route::get('/designations/export', [DesignationController::class, 'export']);
    Route::post('/designations/{id}/restore', [DesignationController::class, 'restore']);

    Route::apiResource('designations', DesignationController::class);

    // Employee resource routes (CRUD)
    Route::post('/employees/import', [EmployeeController::class, 'import']);
    Route::get('/employees/import/structure', [EmployeeController::class, 'importStructure'])
        ->name('employees.import.structure');
    Route::get('/employees/import/template', [EmployeeController::class, 'importTemplate'])
        ->name('employees.import.template');
    Route::post('/employees/sync', [EmployeeController::class, 'sync']);
    Route::get('/employees/export', [EmployeeController::class, 'export']);
    Route::post('/employees/{id}/restore', [EmployeeController::class, 'restore']);
    Route::apiResource('employees', EmployeeController::class);

    // Attendance Deduction Slabs (CRUD)
    Route::post('/late-deduction-slabs/import', [LateDeductionSlabController::class, 'import']);
    Route::get('/late-deduction-slabs/import/structure', [LateDeductionSlabController::class, 'importStructure'])
        ->name('late-deduction-slabs.import.structure');
    Route::get('/late-deduction-slabs/import/template', [LateDeductionSlabController::class, 'importTemplate'])
        ->name('late-deduction-slabs.import.template');
    Route::get('/late-deduction-slabs/export', [LateDeductionSlabController::class, 'export']);
    Route::post('/late-deduction-slabs/{id}/restore', [LateDeductionSlabController::class, 'restore']);
    Route::apiResource('late-deduction-slabs', LateDeductionSlabController::class);

    Route::post('/short-leave-deduction-slabs/import', [ShortLeaveDeductionSlabController::class, 'import']);
    Route::get('/short-leave-deduction-slabs/import/structure', [ShortLeaveDeductionSlabController::class, 'importStructure'])
        ->name('short-leave-deduction-slabs.import.structure');
    Route::get('/short-leave-deduction-slabs/import/template', [ShortLeaveDeductionSlabController::class, 'importTemplate'])
        ->name('short-leave-deduction-slabs.import.template');
    Route::get('/short-leave-deduction-slabs/export', [ShortLeaveDeductionSlabController::class, 'export']);
    Route::post('/short-leave-deduction-slabs/{id}/restore', [ShortLeaveDeductionSlabController::class, 'restore']);
    Route::apiResource('short-leave-deduction-slabs', ShortLeaveDeductionSlabController::class);

    // Statutory Parameter Master import/export and CRUD routes.
    Route::post('/statutory-parameter-masters/import', [StatutoryParameterMasterController::class, 'import']);
    Route::get('/statutory-parameter-masters/import/structure', [StatutoryParameterMasterController::class, 'importStructure'])
        ->name('statutory-parameter-masters.import.structure');
    Route::get('/statutory-parameter-masters/import/template', [StatutoryParameterMasterController::class, 'importTemplate'])
        ->name('statutory-parameter-masters.import.template');
    Route::get('/statutory-parameter-masters/export', [StatutoryParameterMasterController::class, 'export']);
    Route::post('/statutory-parameter-masters/{id}/restore', [StatutoryParameterMasterController::class, 'restore']);
    Route::apiResource('statutory-parameter-masters', StatutoryParameterMasterController::class);

    // Minimum Wage Master import/export and CRUD routes.
    Route::post('/minimum-wage-masters/import', [MinimumWageMasterController::class, 'import']);
    Route::get('/minimum-wage-masters/import/structure', [MinimumWageMasterController::class, 'importStructure'])
        ->name('minimum-wage-masters.import.structure');
    Route::get('/minimum-wage-masters/import/template', [MinimumWageMasterController::class, 'importTemplate'])
        ->name('minimum-wage-masters.import.template');
    Route::get('/minimum-wage-masters/export', [MinimumWageMasterController::class, 'export']);
    Route::post('/minimum-wage-masters/{id}/restore', [MinimumWageMasterController::class, 'restore']);
    Route::apiResource('minimum-wage-masters', MinimumWageMasterController::class);

    // Notice Period import/export routes must be registered before the resource route
    Route::post('/notice-periods/import', [NoticePeriodController::class, 'import']);
    Route::get('/notice-periods/import/structure', [NoticePeriodController::class, 'importStructure'])
        ->name('notice-periods.import.structure');
    Route::get('/notice-periods/import/template', [NoticePeriodController::class, 'importTemplate'])
        ->name('notice-periods.import.template');
    Route::get('/notice-periods/export', [NoticePeriodController::class, 'export']);
    Route::post('/notice-periods/{id}/restore', [NoticePeriodController::class, 'restore']);
    // Notice Period resource routes (CRUD)
    Route::apiResource('notice-periods', NoticePeriodController::class);

    // Holiday resource routes (CRUD)
    Route::post('/holidays/import', [HolidayController::class, 'import']);
    Route::get('/holidays/import/structure', [HolidayController::class, 'importStructure'])
        ->name('holidays.import.structure');
    Route::get('/holidays/import/template', [HolidayController::class, 'importTemplate'])
        ->name('holidays.import.template');
    Route::get('/holidays/export', [HolidayController::class, 'export']);
    Route::post('/holidays/{id}/restore', [HolidayController::class, 'restore']);
    Route::apiResource('holidays', HolidayController::class);

    // Employee Attendance resource routes
    Route::post('/employee-attendances/import', [EmployeeAttendanceController::class, 'import']);
    Route::get('/employee-attendances/import/structure', [EmployeeAttendanceController::class, 'importStructure'])
        ->name('employee-attendances.import.structure');
    Route::get('/employee-attendances/import/template', [EmployeeAttendanceController::class, 'importTemplate'])
        ->name('employee-attendances.import.template');
    Route::get('/employee-attendances/download-template', [EmployeeAttendanceController::class, 'downloadTemplate'])
        ->name('employee-attendances.download.template');
    Route::get('/employee-attendances/export', [EmployeeAttendanceController::class, 'export']);
    Route::get('/employee-attendances/{employeeAttendance}/get-working-hours', [EmployeeAttendanceController::class, 'recalculateWorkingHours']);
    Route::apiResource('employee-attendances', EmployeeAttendanceController::class);

    // Dropdown data routes
    Route::get('/dropdowns/brands', [DropdownController::class, 'brands']);
    Route::get('/dropdowns/divisions', [DropdownController::class, 'divisions']);
    Route::get('/dropdowns/cost-centers', [DropdownController::class, 'costCenters']);
    Route::get('/dropdowns/designations', [DropdownController::class, 'designations']);
    Route::get('/dropdowns/notice-periods', [DropdownController::class, 'noticePeriods']);
    Route::get('/dropdowns/shifts', [DropdownController::class, 'shifts']);
    Route::get('/dropdowns/locations', [DropdownController::class, 'locations']);
    Route::get('/dropdowns/companies', [DropdownController::class, 'companies']);
    Route::get('/dropdowns/vendors', [DropdownController::class, 'vendors']);
    Route::get('/dropdowns/brands/{brandId}/vendors', [DropdownController::class, 'vendorsByBrand']);
    Route::get('/dropdowns/departments', [DropdownController::class, 'departments']);
    Route::get('/dropdowns/employees', [DropdownController::class, 'employees']);
    Route::get('/dropdowns/users', [DropdownController::class, 'users']);
    Route::get('/dropdowns/roles', [DropdownController::class, 'roles']);
    Route::get('/dropdowns/permissions', [DropdownController::class, 'permissions']);

    // Single endpoint for all dropdowns
    Route::get('/dropdowns/all', [DropdownController::class, 'all']);
    Route::get('/usersbyroles/{id}/users', [DropdownController::class, 'getUsers']);

    // Chat endpoints
    Route::get('/messages/{userId}', [CommunicationController::class, 'getMessages']);
    Route::post('/messages/send', [CommunicationController::class, 'sendMessage']);

    // Notification endpoints
    Route::get('/notifications', [CommunicationController::class, 'getNotifications']);
    Route::post('/notifications/send', [CommunicationController::class, 'sendNotification']);
    Route::post('/notifications/mark-read', [CommunicationController::class, 'markNotificationsAsRead']);
});
