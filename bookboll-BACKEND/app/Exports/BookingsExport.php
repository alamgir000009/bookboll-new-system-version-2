<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class BookingsExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize
{
    protected $bookings;

    public function __construct($bookings)
    {
        $this->bookings = $bookings;
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    public function collection()
    {
        return $this->bookings;
    }

    /**
     * @return array
     */
    public function headings(): array
    {
        return [
            'Booking ID',
            'Customer Name',
            'Customer Email',
            'Customer Phone',
            'Venue',
            'Listing',
            'Date',
            'Start Time',
            'End Time',
            'Duration',
            'Status',
            'Payment Status',
            'Payment Method',
            'Subtotal',
            'Discount Amount',
            'Total Price',
            'Promocode',
            'Add-ons',
            'Notes',
            'Created At',
        ];
    }

    /**
     * @param mixed $booking
     * @return array
     */
    public function map($booking): array
    {
        $addOns = $booking->addOns->pluck('name')->implode(', ');

        return [
            $booking->booking_id ?? '',
            $booking->user->name ?? '',
            $booking->user->email ?? '',
            $booking->user->phone ?? '',
            $booking->venue->name ?? '',
            $booking->listing->name ?? '',
            $booking->date ?? '',
            $booking->start_time ?? '',
            $booking->end_time ?? '',
            $booking->duration ?? '',
            ucfirst($booking->status ?? ''),
            ucfirst(str_replace('_', ' ', $booking->payment_status ?? '')),
            ucfirst(str_replace('_', ' ', $booking->payment_method ?? '')),
            number_format($booking->subtotal ?? 0, 2),
            number_format($booking->discount_amount ?? 0, 2),
            number_format($booking->total_price ?? 0, 2),
            $booking->promocode->code ?? '',
            $addOns,
            $booking->notes ?? '',
            $booking->created_at ? $booking->created_at->format('Y-m-d H:i:s') : '',
        ];
    }
}

