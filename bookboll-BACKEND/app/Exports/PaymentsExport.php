<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class PaymentsExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize
{
    protected $payments;

    public function __construct($payments)
    {
        $this->payments = $payments;
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    public function collection()
    {
        return $this->payments;
    }

    /**
     * @return array
     */
    public function headings(): array
    {
        return [
            'Payment ID',
            'Booking ID',
            'Customer Name',
            'Customer Email',
            'Venue',
            'Listing',
            'Amount',
            'Payment Method',
            'Payment Status',
            'Payment Type',
            'Date',
            'Notes',
            'Created At',
        ];
    }

    /**
     * @param mixed $payment
     * @return array
     */
    public function map($payment): array
    {
        return [
            $payment->payment_id ?? '',
            $payment->booking->booking_id ?? '',
            $payment->user->name ?? '',
            $payment->user->email ?? '',
            $payment->booking->venue->name ?? '',
            $payment->booking->listing->name ?? '',
            number_format($payment->amount ?? 0, 2),
            ucfirst(str_replace('_', ' ', $payment->method ?? '')),
            ucfirst($payment->status ?? ''),
            ucfirst(str_replace('_', ' ', $payment->type ?? '')),
            $payment->date ?? '',
            $payment->notes ?? '',
            $payment->created_at ? $payment->created_at->format('Y-m-d H:i:s') : '',
        ];
    }
}

