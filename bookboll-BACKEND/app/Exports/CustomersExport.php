<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class CustomersExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize
{
    protected $customers;

    public function __construct($customers)
    {
        $this->customers = $customers;
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    public function collection()
    {
        return $this->customers;
    }

    /**
     * @return array
     */
    public function headings(): array
    {
        return [
            'Name',
            'Email',
            'Phone',
            'Status',
            'Tags',
            'Total Bookings',
            'Loyalty Balance',
            'Total Spent',
            'Total Due',
            'Created At',
        ];
    }

    /**
     * @param mixed $customer
     * @return array
     */
    public function map($customer): array
    {
        $tags = $customer->tags->pluck('name')->implode(', ');

        return [
            $customer->name ?? '',
            $customer->email ?? '',
            $customer->phone ?? '',
            ucfirst($customer->status ?? ''),
            $tags,
            $customer->bookings_count ?? 0,
            number_format($customer->loyalty_balance ?? 0, 2),
            number_format($customer->total_spent ?? 0, 2),
            number_format($customer->total_due_amount ?? 0, 2),
            $customer->created_at ? $customer->created_at->format('Y-m-d H:i:s') : '',
        ];
    }
}

