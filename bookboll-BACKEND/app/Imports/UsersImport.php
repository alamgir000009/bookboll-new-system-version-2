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
    protected $errors = [];
    protected $successCount = 0;
    protected $errorCount = 0;
    protected $skippedCount = 0;

    public function collection(Collection $rows)
    {
        foreach ($rows as $index => $row) {
            try {
                // Skip empty rows
                if (empty(array_filter($row->toArray()))) {
                    continue;
                }

                $this->importRow($row, $index + 2); // +2 because heading row is 1, and we start from 2
            } catch (\Exception $e) {
                $this->errorCount++;
                $this->errors[] = [
                    'row' => $index + 2,
                    'error' => $e->getMessage(),
                ];
                Log::warning("User import error at row " . ($index + 2) . ": " . $e->getMessage());
            }
        }
    }

    protected function importRow($row, $rowNumber)
    {
        // Get values using flexible column name matching
        $name = $this->getValue($row, 'name');
        $email = $this->getValue($row, 'email');
        $phone = $this->getValue($row, 'phone');
        $nationality = $this->getValue($row, 'nationality');
        $dateOfBirth = $this->getValue($row, 'date_of_birth') ?: $this->getValue($row, 'dob');
        $gender = $this->getValue($row, 'gender');
        $loyaltyPoints = $this->getValue($row, 'loyalty_points', '0');
        $address = $this->getValue($row, 'address');

        // Email is required
        if (empty($email)) {
            $this->skippedCount++;
            $this->errors[] = [
                'row' => $rowNumber,
                'error' => 'Email is required',
            ];
            throw new \Exception('Email is required');
        }

        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->skippedCount++;
            $this->errors[] = [
                'row' => $rowNumber,
                'error' => "Invalid email format: {$email}",
            ];
            throw new \Exception("Invalid email format: {$email}");
        }

        // Process phone number
        if (!empty($phone)) {
            // Handle Excel scientific notation
            if (is_numeric($phone) && (stripos($phone, 'E') !== false || stripos($phone, 'e') !== false)) {
                $phone = number_format((float) $phone, 0, '', '');
            }

            // Remove non-numeric characters except +
            $phone = preg_replace('/[^0-9+]/', '', $phone);

            // Add country code if needed
            if (!empty($phone) && !str_starts_with($phone, '+')) {
                if (str_starts_with($phone, '974')) {
                    $phone = '+' . $phone;
                } elseif (strlen($phone) >= 8) {
                    $phone = '+974' . $phone;
                }
            }
        }

        // Parse date of birth - prioritize DD-MM-YYYY format (e.g., 20-01-2000)
        $parsedDateOfBirth = null;
        if (!empty($dateOfBirth)) {
            try {
                $trimmedDate = trim($dateOfBirth);

                // Try DD-MM-YYYY format first (e.g., 20-01-2000)
                if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $trimmedDate, $matches)) {
                    $parsedDateOfBirth = Carbon::createFromDate($matches[3], $matches[2], $matches[1])->format('Y-m-d');
                }
                // Try YYYY-MM-DD format (e.g., 2000-01-20)
                elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $trimmedDate, $matches)) {
                    $parsedDateOfBirth = Carbon::createFromDate($matches[1], $matches[2], $matches[3])->format('Y-m-d');
                }
                // Check if it's an Excel serial date number (fallback)
                elseif (is_numeric($trimmedDate) && $trimmedDate > 0) {
                    $excelSerial = (float) $trimmedDate;
                    $unixTimestamp = ($excelSerial - 25569) * 86400;
                    $parsedDateOfBirth = Carbon::createFromTimestamp($unixTimestamp)->format('Y-m-d');
                }
                // Otherwise, let Carbon parse it
                else {
                    $parsedDateOfBirth = Carbon::parse($trimmedDate)->format('Y-m-d');
                }
            } catch (\Exception $e) {
                Log::warning("Could not parse date of birth for row {$rowNumber}: {$dateOfBirth} - " . $e->getMessage());
                // Continue without date of birth
            }
        }

        // Normalize gender
        $normalizedGender = null;
        if (!empty($gender)) {
            $genderLower = strtolower(trim($gender));
            if (in_array($genderLower, ['male', 'm', 'man'])) {
                $normalizedGender = 'male';
            } elseif (in_array($genderLower, ['female', 'f', 'woman'])) {
                $normalizedGender = 'female';
            } else {
                $normalizedGender = $gender;
            }
        }

        // Parse loyalty points
        $loyaltyPointsInt = 0;
        if (!empty($loyaltyPoints)) {
            $loyaltyPointsInt = (int) filter_var($loyaltyPoints, FILTER_SANITIZE_NUMBER_INT);
        }

        // Find or create user
        $user = User::where('email', $email)->first();

        if ($user) {
            // Update existing user
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

            // Ensure user has customer role
            if (!$user->hasRole('customer')) {
                $user->assignRole('customer');
            }

            // Set status if not set
            if (empty($user->status)) {
                $updateData['status'] = User::ACTIVE;
            }

            // Update user if there are changes
            if (!empty($updateData)) {
                $user->update($updateData);
            }

            // Update loyalty points if needed
            if ($loyaltyPointsInt > 0) {
                $currentBalance = LoyaltyPoint::balance($user->id) ?? 0;
                if ($currentBalance < $loyaltyPointsInt) {
                    $pointsToAdd = $loyaltyPointsInt - $currentBalance;
                    $user->addLoyaltyPoints($pointsToAdd, null, "Imported from Excel - Row {$rowNumber}");
                }
            }
        } else {
            // Create new user
            $user = User::create([
                'name' => $name ?: 'User',
                'email' => $email,
                'phone' => $phone,
                'nationality' => $nationality,
                'date_of_birth' => $parsedDateOfBirth,
                'gender' => $normalizedGender,
                'address' => $address,
                'status' => User::ACTIVE,
                'password' => bcrypt('bookboll-import-' . time()),
            ]);

            $user->assignRole('customer');

            // Create default preferences
            if (!$user->preferences) {
                Preference::create([
                    'user_id' => $user->id,
                    'notifications' => [
                        'booking_emails' => true,
                        'sms_reminders' => false,
                        'marketing_emails' => true,
                    ],
                    'favorite_sports' => [],
                    'language' => 'english',
                    'currency' => 'QAR',
                ]);
            }

            // Add loyalty points if provided
            if ($loyaltyPointsInt > 0) {
                $user->addLoyaltyPoints($loyaltyPointsInt, null, "Imported from Excel - Row {$rowNumber}");
            }
        }

        $this->successCount++;
    }

    protected function getValue($row, $key, $default = null)
    {
        // Handle different possible column name formats
        $keys = [
            $key,
            str_replace('_', ' ', $key),
            str_replace(' ', '_', $key),
            strtolower($key),
            strtolower(str_replace('_', ' ', $key)),
            ucfirst($key),
            ucwords(str_replace('_', ' ', $key)),
        ];

        // First try exact matches
        foreach ($keys as $k) {
            if (isset($row[$k])) {
                $value = $row[$k];
                return $value !== null && $value !== '' ? trim($value) : $default;
            }
        }

        // Then try case-insensitive matching
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

