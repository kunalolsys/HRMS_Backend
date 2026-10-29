<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Shift;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class ShiftSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
   
    private function timeToMinutes(?string $timeStr): ?int
    {
        if (empty($timeStr)) {
            return null;
        }
        
        $parts = explode(':', $timeStr);
        $hours = (int) ($parts[0] ?? 0);
        $minutes = (int) ($parts[1] ?? 0);

        return ($hours * 60) + $minutes;
    }

    public function run(): void
    {
        $shifts = [
            [
                'shift_name'            => 'Sales_Boys',
                'shift_code'            => 'SALES_BOYS',
                'shift_start_time'      => '10:00:00',
                'shift_end_time'        => '21:00:00',
                'grace_time'            => 15,
                'full_day_min_hours'    => $this->timeToMinutes('10:30'), // 630 mins
                'short_leave_min_hours' => $this->timeToMinutes('08:56'), // 536 mins
                'half_day_min_hours'    => $this->timeToMinutes('05:31'), // 331 mins
                'no_salary_max_hours'   => $this->timeToMinutes('05:30'), // 330 mins
            ],
            [
                'shift_name'            => 'Sales_Girls',
                'shift_code'            => 'SALES_GIRLS',
                'shift_start_time'      => '10:00:00',
                'shift_end_time'        => '20:30:00',
                'grace_time'            => 15,
                'full_day_min_hours'    => $this->timeToMinutes('10:00'),
                'short_leave_min_hours' => $this->timeToMinutes('08:34'),
                'half_day_min_hours'    => $this->timeToMinutes('05:16'),
                'no_salary_max_hours'   => $this->timeToMinutes('05:15'),
            ],
            [
                'shift_name'            => 'Grace_Sales',
                'shift_code'            => 'GRACE_SALES',
                'shift_start_time'      => '10:00:00',
                'shift_end_time'        => '21:00:00',
                'grace_time'            => 15,
                'full_day_min_hours'    => $this->timeToMinutes('10:30'),
                'short_leave_min_hours' => $this->timeToMinutes('08:56'),
                'half_day_min_hours'    => $this->timeToMinutes('05:31'),
                'no_salary_max_hours'   => $this->timeToMinutes('05:30'),
            ],
            [
                'shift_name'            => 'Online',
                'shift_code'            => 'ONLINE',
                'shift_start_time'      => '10:00:00',
                'shift_end_time'        => '20:00:00',
                'grace_time'            => 15,
                'full_day_min_hours'    => $this->timeToMinutes('09:30'),
                'short_leave_min_hours' => $this->timeToMinutes('08:16'),
                'half_day_min_hours'    => $this->timeToMinutes('05:01'),
                'no_salary_max_hours'   => $this->timeToMinutes('05:00'),
            ],
            [
                'shift_name'            => 'WH_Boys',
                'shift_code'            => 'WH_BOYS',
                'shift_start_time'      => '09:00:00',
                'shift_end_time'        => '20:00:00',
                'grace_time'            => 15,
                'full_day_min_hours'    => $this->timeToMinutes('10:30'),
                'short_leave_min_hours' => $this->timeToMinutes('08:56'),
                'half_day_min_hours'    => $this->timeToMinutes('05:31'),
                'no_salary_max_hours'   => $this->timeToMinutes('05:30'),
            ],
            [
                'shift_name'            => 'WH_Girls',
                'shift_code'            => 'WH_GIRLS',
                'shift_start_time'      => '09:00:00',
                'shift_end_time'        => '19:30:00',
                'grace_time'            => 15,
                'full_day_min_hours'    => $this->timeToMinutes('10:00'),
                'short_leave_min_hours' => $this->timeToMinutes('08:34'),
                'half_day_min_hours'    => $this->timeToMinutes('05:16'),
                'no_salary_max_hours'   => $this->timeToMinutes('05:15'),
            ],
            [
                'shift_name'            => 'HO',
                'shift_code'            => 'HO',
                'shift_start_time'      => '09:30:00',
                'shift_end_time'        => '19:00:00',
                'grace_time'            => 15,
                'full_day_min_hours'    => $this->timeToMinutes('09:00'),
                'short_leave_min_hours' => $this->timeToMinutes('06:49'),
                'half_day_min_hours'    => $this->timeToMinutes('04:46'),
                'no_salary_max_hours'   => $this->timeToMinutes('04:45'),
            ],
            [
                'shift_name'            => 'HO_EVENING',
                'shift_code'            => 'HO_EVENING',
                'shift_start_time'      => '12:00:00',
                'shift_end_time'        => '21:30:00',
                'grace_time'            => 15,
                'full_day_min_hours'    => $this->timeToMinutes('09:00'),
                'short_leave_min_hours' => $this->timeToMinutes('06:49'),
                'half_day_min_hours'    => $this->timeToMinutes('04:46'),
                'no_salary_max_hours'   => $this->timeToMinutes('04:45'),
            ],
            [
                'shift_name'            => 'Merchandiser',
                'shift_code'            => 'MERCHANDISER',
                'shift_start_time'      => '09:00:00',
                'shift_end_time'        => '20:00:00',
                'grace_time'            => 15,
                'full_day_min_hours'    => $this->timeToMinutes('10:30'),
                'short_leave_min_hours' => $this->timeToMinutes('08:56'),
                'half_day_min_hours'    => $this->timeToMinutes('05:31'),
                'no_salary_max_hours'   => $this->timeToMinutes('05:30'),
            ],
            [
                'shift_name'            => 'HK_MORNING',
                'shift_code'            => 'HK_MORNING',
                'shift_start_time'      => '09:00:00',
                'shift_end_time'        => '18:00:00',
                'grace_time'            => 15,
                'full_day_min_hours'    => $this->timeToMinutes('09:00'),
                'short_leave_min_hours' => $this->timeToMinutes('07:34'),
                'half_day_min_hours'    => $this->timeToMinutes('04:31'),
                'no_salary_max_hours'   => $this->timeToMinutes('04:30'),
            ],
            [
                'shift_name'            => 'HK_EVENING',
                'shift_code'            => 'HK_EVENING',
                'shift_start_time'      => '12:00:00',
                'shift_end_time'        => '21:00:00',
                'grace_time'            => 15,
                'full_day_min_hours'    => $this->timeToMinutes('09:00'),
                'short_leave_min_hours' => $this->timeToMinutes('07:34'),
                'half_day_min_hours'    => $this->timeToMinutes('04:31'),
                'no_salary_max_hours'   => $this->timeToMinutes('04:30'),
            ],
            [
                'shift_name'            => 'HK',
                'shift_code'            => 'HK',
                'shift_start_time'      => '10:00:00',
                'shift_end_time'        => '20:00:00',
                'grace_time'            => 15,
                'full_day_min_hours'    => $this->timeToMinutes('09:30'),
                'short_leave_min_hours' => $this->timeToMinutes('08:16'),
                'half_day_min_hours'    => $this->timeToMinutes('05:01'),
                'no_salary_max_hours'   => $this->timeToMinutes('05:00'),
            ],
            [
                'shift_name'            => 'HM_HO',
                'shift_code'            => 'HM_HO',
                'shift_start_time'      => '09:00:00',
                'shift_end_time'        => '19:00:00',
                'grace_time'            => 15,
                'full_day_min_hours'    => $this->timeToMinutes('09:30'),
                'short_leave_min_hours' => $this->timeToMinutes('08:30'),
                'half_day_min_hours'    => $this->timeToMinutes('05:00'),
                'no_salary_max_hours'   => $this->timeToMinutes('04:59'),
            ],
            [
                'shift_name'            => 'HO_DRIVER',
                'shift_code'            => 'HO_DRIVER',
                'shift_start_time'      => '08:10:00',
                'shift_end_time'        => '17:40:00',
                'grace_time'            => 15,
                'full_day_min_hours'    => $this->timeToMinutes('09:00'),
                'short_leave_min_hours' => $this->timeToMinutes('06:49'),
                'half_day_min_hours'    => $this->timeToMinutes('04:46'),
                'no_salary_max_hours'   => $this->timeToMinutes('04:45'),
            ],
            [
                'shift_name'            => 'MTLHK_MORNING',
                'shift_code'            => 'MTLHK_MORNING',
                'shift_start_time'      => '11:00:00',
                'shift_end_time'        => '20:00:00',
                'grace_time'            => 15,
                'full_day_min_hours'    => $this->timeToMinutes('08:30'),
                'short_leave_min_hours' => $this->timeToMinutes('07:31'),
                'half_day_min_hours'    => $this->timeToMinutes('04:31'),
                'no_salary_max_hours'   => $this->timeToMinutes('04:30'),
            ],
            [
                'shift_name'            => 'MTLHK_EVENING',
                'shift_code'            => 'MTLHK_EVENING',
                'shift_start_time'      => '13:00:00',
                'shift_end_time'        => '22:00:00',
                'grace_time'            => 15,
                'full_day_min_hours'    => $this->timeToMinutes('08:30'),
                'short_leave_min_hours' => $this->timeToMinutes('07:31'),
                'half_day_min_hours'    => $this->timeToMinutes('04:31'),
                'no_salary_max_hours'   => $this->timeToMinutes('04:30'),
            ],
            [
                'shift_name'            => 'MTLSALES_BOYS',
                'shift_code'            => 'MTLSALES_BOYS',
                'shift_start_time'      => '11:00:00',
                'shift_end_time'        => '22:00:00',
                'grace_time'            => 15,
                'full_day_min_hours'    => $this->timeToMinutes('10:30'),
                'short_leave_min_hours' => $this->timeToMinutes('08:56'),
                'half_day_min_hours'    => $this->timeToMinutes('05:31'),
                'no_salary_max_hours'   => $this->timeToMinutes('05:30'),
            ],
            [
                'shift_name'            => 'MTLSALES_GIRLS',
                'shift_code'            => 'MTLSALES_GIRLS',
                'shift_start_time'      => '11:00:00',
                'shift_end_time'        => '20:30:00',
                'grace_time'            => 15,
                'full_day_min_hours'    => $this->timeToMinutes('09:00'),
                'short_leave_min_hours' => $this->timeToMinutes('08:01'),
                'half_day_min_hours'    => $this->timeToMinutes('04:46'),
                'no_salary_max_hours'   => $this->timeToMinutes('04:45'),
            ],
        ];

        foreach ($shifts as $data) {
            Shift::updateOrCreate(
                ['shift_code' => $data['shift_code']],
                array_merge($data, [
                    'is_active'      => true,              // Strict PostgreSQL boolean
                    'is_delete'      => false,             // Strict PostgreSQL boolean
                    'effective_from' => '2024-01-01',
                    'effective_to'   => '2099-12-31',
                    'created_by'     => null,
                    'updated_by'     => null,
                ])
            );
        }
    }
}