<?php
/**
 * Synthetic demonstration data. Every name, number and record here is made up.
 * No real patient information is ever used in UB-HMS.
 *
 * The data is created through the same service functions the web pages use,
 * so loading it also exercises the application rules.
 */
declare(strict_types=1);

const DEMO_PASSWORD = 'UbHms#Demo2026';

/** username => [full name, role code] */
function demo_staff(): array
{
    return [
        'manager1'    => ['Grace Njoroge',     'manager'],
        'reception1'  => ['Faith Achieng',     'receptionist'],
        'reception2'  => ['Brian Mutua',       'receptionist'],
        'nurse1'      => ['Esther Wambui',     'nurse'],
        'nurse2'      => ['Peter Omondi',      'nurse'],
        'doctor1'     => ['Dr. Samuel Kamau',  'doctor'],
        'doctor2'     => ['Dr. Ruth Chebet',   'doctor'],
        'doctor3'     => ['Dr. Daniel Barasa', 'doctor'],
        'lab1'        => ['Mary Atieno',       'lab'],
        'pharmacist1' => ['John Mwangi',       'pharmacist'],
        'cashier1'    => ['Lucy Muthoni',      'cashier'],
    ];
}

function seed_demo_users(): int
{
    $n = 0;
    foreach (demo_staff() as $username => [$name, $role]) {
        if (db_value('SELECT id FROM users WHERE username = ?', [$username]) !== null) {
            continue;
        }
        db_insert(
            'INSERT INTO users (full_name, username, password_hash, role_id, phone, must_change_password) VALUES (?, ?, ?, ?, ?, 0)',
            [$name, $username, hash_password(DEMO_PASSWORD), role_id_by_code($role), '+2547000000' . str_pad((string) (10 + $n), 2, '0', STR_PAD_LEFT)]
        );
        $n++;
    }
    return $n;
}

function demo_user_id(string $username): int
{
    return (int) db_value('SELECT id FROM users WHERE username = ?', [$username]);
}

/** Register synthetic patients. Returns the number registered. */
function seed_demo_patients(int $count = 40): int
{
    $first = ['Wanjiru', 'Otieno', 'Achieng', 'Kamau', 'Njeri', 'Mwangi', 'Akinyi', 'Kipchoge', 'Chebet', 'Mutua', 'Nduta',
              'Odhiambo', 'Wambui', 'Kiprono', 'Atieno', 'Maina', 'Naliaka', 'Barasa', 'Muthoni', 'Omondi', 'Wafula',
              'Jepkoech', 'Karanja', 'Auma', 'Kibet', 'Nyambura', 'Onyango', 'Cherono', 'Gathoni', 'Simiyu'];
    $last = ['Kariuki', 'Ouma', 'Wekesa', 'Langat', 'Mbugua', 'Ndungu', 'Owino', 'Rotich', 'Kimani', 'Juma',
             'Wairimu', 'Okoth', 'Kosgei', 'Mugo', 'Nyaga', 'Anyango', 'Koech', 'Wangui', 'Opiyo', 'Khamisi'];
    $residence = ['Embakasi', 'Kasarani', 'Kibera', 'Ruaka', 'Rongai', 'Kahawa West', 'Donholm', 'South B', 'Githurai 45', 'Utawala'];
    $kin = ['Mother', 'Father', 'Spouse', 'Brother', 'Sister', 'Guardian'];
    $allergies = [null, null, null, null, null, 'Penicillin', 'Sulfa drugs', 'Aspirin', null, 'Peanuts'];

    mt_srand(2026); // same data every time
    $reception = demo_user_id('reception1');
    $done = 0;
    for ($i = 1; $i <= $count; $i++) {
        $age = [2, 5, 8, 14, 19, 24, 28, 31, 35, 38, 42, 47, 53, 58, 63, 71][mt_rand(0, 15)];
        $dob = (new DateTimeImmutable('today'))->modify("-$age years")->modify('-' . mt_rand(1, 330) . ' days')->format('Y-m-d');
        $isChild = $age < 18;
        $r = register_patient([
            'first_name' => $first[mt_rand(0, count($first) - 1)],
            'middle_name' => mt_rand(0, 2) === 0 ? $first[mt_rand(0, count($first) - 1)] : '',
            'last_name' => $last[mt_rand(0, count($last) - 1)],
            'sex' => mt_rand(0, 1) ? 'Female' : 'Male',
            'date_of_birth' => $dob,
            'national_id' => $isChild ? '' : (string) (99000000 + $i),
            'phone' => $isChild ? '' : '0700 000 ' . str_pad((string) (100 + $i), 3, '0', STR_PAD_LEFT),
            'residence' => $residence[mt_rand(0, count($residence) - 1)],
            'allergies' => $allergies[mt_rand(0, count($allergies) - 1)] ?? '',
            'next_of_kin_name' => $first[mt_rand(0, count($first) - 1)] . ' ' . $last[mt_rand(0, count($last) - 1)],
            'next_of_kin_relation' => $kin[mt_rand(0, count($kin) - 1)],
            'next_of_kin_phone' => '0701 000 ' . str_pad((string) (100 + $i), 3, '0', STR_PAD_LEFT),
        ], $reception, true);
        if ($r['ok']) {
            $done++;
        }
    }
    return $done;
}

require_once __DIR__ . '/demo_clinical.php';

/** Create everything the demo needs. Returns a summary for the installer to print. */
function seed_demo_data(bool $full = true): array
{
    $summary = [];
    $summary['staff accounts'] = seed_demo_users();
    $summary['patients registered'] = seed_demo_patients();
    if (!$full) {
        return $summary;
    }
    // stock first, then two weeks of clinic history that uses it, then the summary
    foreach (['seed_demo_pharmacy', 'seed_demo_schedules', 'seed_demo_appointments_and_visits', 'seed_demo_billing'] as $fn) {
        $summary += $fn();
    }
    return $summary;
}

/**
 * Working hours for the three demonstration doctors, and one coming leave, so the Doctor schedules page has something to show.
 * This runs before the appointments are booked ahead, so those bookings follow the hours like any other.
 */
function seed_demo_schedules(): array
{
    $manager = demo_user_id('manager1');
    $day = static fn (string $from, string $to): array => [['from' => $from, 'to' => $to]];
    $plan = [
        // Dr Kamau: all day Monday to Friday, Saturday morning
        'doctor1' => [1 => $day('08:00', '17:00'), 2 => $day('08:00', '17:00'), 3 => $day('08:00', '17:00'), 4 => $day('08:00', '17:00'), 5 => $day('08:00', '17:00'), 6 => $day('08:00', '13:00')],
        // Dr Chebet: all day Monday to Saturday
        'doctor2' => [1 => $day('08:00', '17:00'), 2 => $day('08:00', '17:00'), 3 => $day('08:00', '17:00'), 4 => $day('08:00', '17:00'), 5 => $day('08:00', '17:00'), 6 => $day('08:00', '17:00')],
        // Dr Barasa: Monday to Friday, with a break at lunch time
        'doctor3' => [1 => [['from' => '08:00', 'to' => '13:00'], ['from' => '14:00', 'to' => '17:00']], 2 => [['from' => '08:00', 'to' => '13:00'], ['from' => '14:00', 'to' => '17:00']],
                      3 => [['from' => '08:00', 'to' => '13:00'], ['from' => '14:00', 'to' => '17:00']], 4 => [['from' => '08:00', 'to' => '13:00'], ['from' => '14:00', 'to' => '17:00']],
                      5 => [['from' => '08:00', 'to' => '13:00'], ['from' => '14:00', 'to' => '17:00']]],
    ];
    $n = 0;
    foreach ($plan as $username => $days) {
        $r = save_doctor_schedule(demo_user_id($username), ['sch' => $days], $manager);
        $n += $r['ok'] ? 1 : 0;
    }
    $from = (new DateTimeImmutable('today'))->modify('+10 days');
    add_doctor_leave(demo_user_id('doctor3'), ['start_date' => $from->format('Y-m-d'), 'end_date' => $from->modify('+2 days')->format('Y-m-d'), 'type' => 'Annual leave', 'reason' => 'Demonstration'], $manager);
    return ['doctor schedules' => $n];
}
