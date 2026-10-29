<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AttendanceDeductionSlabSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Late Deduction Slabs Table Data
        $lateSlabs = [
            ['lates_count' => 5,  'days_deducted' => 1.0],
            ['lates_count' => 8,  'days_deducted' => 1.5],
            ['lates_count' => 10, 'days_deducted' => 2.0],
            ['lates_count' => 13, 'days_deducted' => 2.5],
            ['lates_count' => 15, 'days_deducted' => 3.0],
            ['lates_count' => 18, 'days_deducted' => 3.5],
            ['lates_count' => 20, 'days_deducted' => 4.0],
            ['lates_count' => 23, 'days_deducted' => 4.5],
            ['lates_count' => 25, 'days_deducted' => 5.0],
            ['lates_count' => 28, 'days_deducted' => 5.5],
            ['lates_count' => 30, 'days_deducted' => 6.0],
        ];

        foreach ($lateSlabs as $slab) {
            DB::table('late_deduction_slabs')->updateOrInsert(
                ['lates_count' => $slab['lates_count']],
                ['days_deducted' => $slab['days_deducted'], 'created_at' => now(), 'updated_at' => now()]
            );
        }

        // 2. Short Leave Deduction Slabs Table Data
        $shortLeaveSlabs = [
            ['short_leaves_count' => 3,  'days_deducted' => 1.0],
            ['short_leaves_count' => 5,  'days_deducted' => 1.5],
            ['short_leaves_count' => 6,  'days_deducted' => 2.0],
            ['short_leaves_count' => 9,  'days_deducted' => 3.0],
            ['short_leaves_count' => 12, 'days_deducted' => 4.0],
            ['short_leaves_count' => 15, 'days_deducted' => 5.0],
            ['short_leaves_count' => 18, 'days_deducted' => 6.0],
            ['short_leaves_count' => 21, 'days_deducted' => 7.0],
            ['short_leaves_count' => 24, 'days_deducted' => 8.0],
            ['short_leaves_count' => 27, 'days_deducted' => 9.0],
            ['short_leaves_count' => 30, 'days_deducted' => 10.0],
        ];

        foreach ($shortLeaveSlabs as $slab) {
            DB::table('short_leave_deduction_slabs')->updateOrInsert(
                ['short_leaves_count' => $slab['short_leaves_count']],
                ['days_deducted' => $slab['days_deducted'], 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }
}