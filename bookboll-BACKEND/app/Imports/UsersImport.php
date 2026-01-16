<?php
namespace App\Imports;

use App\Models\LoyaltyPoint;
use App\Models\Preference;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class UsersImport implements ToCollection, WithHeadingRow
{
    protected $errors       = [];
    protected $successCount = 0;
    protected $errorCount   = 0;
    protected $skippedCount = 0;

    public function collection(Collection $rows)
    {
        foreach ($rows as $index => $row) {
            try {

                if (empty(array_filter($row->toArray()))) {
                    Log::warning("Row " . ($index + 2) . " is empty. Skipping.");
                    continue;
                }

                $this->importRow($row, $index + 2);
            } catch (\Exception $e) {
                $this->errorCount++;
                $this->errors[] = [
                    'row'   => $index + 2,
                    'error' => $e->getMessage(),
                ];
                Log::error("User import error at row " . ($index + 2) . ": " . $e->getMessage());
            }
        }
    }

    protected function importRow($row, $rowNumber)
    {
        $email = $this->getValue($row, 'email');
        if (empty($email)) {
            $this->skippedCount++;
            $this->errors[] = [
                'row'   => $rowNumber,
                'error' => 'Email is required',
            ];
            Log::warning("Row {$rowNumber}: Skipped - Email is required");
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Log::warning("Invalid email format at row {$rowNumber}: {$email}");
        }

        $name          = $this->getValue($row, 'name', '');
        $phone         = $this->getValue($row, 'phone', '');
        $gender        = $this->getValue($row, 'gender', '');
        $nationality   = $this->getValue($row, 'nationality', '');
        $dateOfBirth   = $this->getValue($row, 'date_of_birth', '');
        $loyaltyPoints = $this->getValue($row, 'loyalty_points', '0');
        $address       = $this->getValue($row, 'address', '');

        if (!empty($phone)) {
            if (is_numeric($phone) && (stripos($phone, 'E') !== false || stripos($phone, 'e') !== false)) {
                $phone = number_format((float) $phone, 0, '', '');
            }

            $phone = preg_replace('/[^0-9+]/', '', $phone);

            if (!empty($phone) && !str_starts_with($phone, '+')) {
                if (str_starts_with($phone, '974')) {
                    $phone = '+' . $phone;
                } elseif (strlen($phone) >= 8) {
                    $phone = '+974' . $phone;
                }
            }
        }

        $parsedDateOfBirth = null;
        if (!empty($dateOfBirth)) {
            $dateOfBirth = trim($dateOfBirth);
            try {
                if (preg_match('/^\d{1,2}-\d{1,2}-\d{4}$/', $dateOfBirth)) {
                    $parsedDateOfBirth = Carbon::createFromFormat('d-m-Y', $dateOfBirth)->format('Y-m-d');
                } else {
                    $parsedDateOfBirth = Carbon::parse($dateOfBirth)->format('Y-m-d');
                }
            } catch (\Throwable $e) {
                Log::warning("Could not parse DOB for row {$rowNumber}: {$dateOfBirth} - " . $e->getMessage());
            }
        }

        $normalizedGender = null;
        if (!empty($gender)) {
            $genderLower = strtolower($gender);
            if (in_array($genderLower, ['male', 'm', 'man'])) {
                $normalizedGender = 'male';
            } elseif (in_array($genderLower, ['female', 'f', 'woman'])) {
                $normalizedGender = 'female';
            } else {
                $normalizedGender = $gender;
            }
        }

        $loyaltyPointsInt = 0;
        if (!empty($loyaltyPoints)) {
            $loyaltyPointsInt = (int) filter_var($loyaltyPoints, FILTER_SANITIZE_NUMBER_INT);
        }

        $user = User::where('email', $email)->first();

        if ($user) {
            $updateData = [];

            if (!empty($name)) {
                $updateData['name'] = $name;
            }
            if (!empty($phone)) {
                $updateData['phone'] = $phone;
            }
            if (!empty($nationality)) {
                $updateData['nationality'] = $nationality;
            }
            if ($parsedDateOfBirth) {
                $updateData['date_of_birth'] = $parsedDateOfBirth;
            }
            if ($normalizedGender) {
                $updateData['gender'] = $normalizedGender;
            }
            if (!empty($address)) {
                $updateData['address'] = $address;
            }

            if (!$user->hasRole('customer')) {
                $user->assignRole('customer');
            }

            if (!empty($updateData)) {
                $user->update($updateData);
            }

            if (!$user->preferences) {
                Preference::create([
                    'user_id'         => $user->id,
                    'notifications'   => [
                        'booking_emails'   => true,
                        'sms_reminders'    => false,
                        'marketing_emails' => true,
                    ],
                    'favorite_sports' => [],
                    'language'        => 'english',
                    'currency'        => 'QAR',
                ]);
            }

            if ($loyaltyPointsInt > 0) {
                $currentBalance = LoyaltyPoint::balance($user->id) ?? 0;
                if ($currentBalance < $loyaltyPointsInt) {
                    $pointsToAdd = $loyaltyPointsInt - $currentBalance;
                    $user->addLoyaltyPoints($pointsToAdd, null, "Imported from Excel - Row {$rowNumber}");
                }
            }

            $this->successCount++;
        } else {
            $user = User::create([
                'name'          => $name ?: 'User',
                'email'         => $email,
                'phone'         => $phone,
                'nationality'   => $nationality,
                'date_of_birth' => $parsedDateOfBirth,
                'gender'        => $normalizedGender,
                'address'       => $address,
                'status'        => User::ACTIVE,
            ]);

            $user->assignRole('customer');

            Preference::create([
                'user_id'         => $user->id,
                'notifications'   => [
                    'booking_emails'   => true,
                    'sms_reminders'    => false,
                    'marketing_emails' => true,
                ],
                'favorite_sports' => [],
                'language'        => 'english',
                'currency'        => 'QAR',
            ]);

            if ($loyaltyPointsInt > 0) {
                $user->addLoyaltyPoints($loyaltyPointsInt, null, "Imported from Excel - Row {$rowNumber}");
            }

            $this->successCount++;
        }
    }

    protected function getValue($row, $key, $default = null)
    {
        $keys = [
            $key,
            str_replace('_', ' ', $key),
            str_replace(' ', '_', $key),
            strtolower($key),
            strtolower(str_replace('_', ' ', $key)),
            ucfirst($key),
            ucwords(str_replace('_', ' ', $key)),
        ];

        foreach ($keys as $k) {
            if (isset($row[$k])) {
                $value = $row[$k];
                return $value !== null && $value !== '' ? trim($value) : $default;
            }
        }

        foreach ($row as $rowKey => $rowValue) {
            foreach ($keys as $k) {
                if (strcasecmp($rowKey, $k) === 0) {
                    $value = $rowValue;
                    return $value !== null && $value !== '' ? trim($value) : $default;
                }
            }
        }

        return $default;
    }

    public function getErrors()
    {
        return $this->errors;
    }

    public function getSuccessCount()
    {
        return $this->successCount;
    }

    public function getErrorCount()
    {
        return $this->errorCount;
    }

    public function getSkippedCount()
    {
        return $this->skippedCount;
    }
}

