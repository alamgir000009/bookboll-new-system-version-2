<?php

namespace App\Services;

use App\Models\AddOn;
use App\Models\Listing;
use App\Models\SpecialDay;
use Carbon\Carbon;

class CustomerBookingQuoteService
{
    /**
     * Price of an extra (contiguous) slot after the first in a block.
     */
    public function extensionSlotPrice(Listing $listing, float $fullPrice): float
    {
        $mode = $listing->extension_pricing ?? 'none';
        $val  = (float) ($listing->extension_value ?? 0);

        if ($mode === 'flat') {
            return max(0, $val);
        }

        if ($mode === 'percent') {
            return max(0, $fullPrice * (1 - $val / 100));
        }

        return max(0, $fullPrice);
    }

    public function extensionMaxSlots(Listing $listing): int
    {
        return max(1, (int) floor((float) ($listing->extension_max_slots ?? 1)));
    }

    public function isExtensionIndex(Listing $listing, int $index, ?int $maxSlots = null): bool
    {
        $maxSlots = $maxSlots ?? $this->extensionMaxSlots($listing);

        return $index >= 1 && $index <= $maxSlots;
    }

    public function isPerPerson(Listing $listing): bool
    {
        $type = $listing->listing_type;

        return $type && ! in_array($type, ['per_space', 'court'], true);
    }

    /**
     * Split a contiguous block into individual slot windows.
     *
     * @return array<int, array{start_time: string, end_time: string}>
     */
    public function splitBlockIntoSlots(Listing $listing, string $startTime, string $endTime): array
    {
        $slotMinutes = $listing->slotDurationMinutes();
        $start       = Carbon::createFromFormat('H:i', substr($startTime, 0, 5));
        $end         = Carbon::createFromFormat('H:i', substr($endTime, 0, 5));

        // Midnight is the END of the day, so 23:00-00:00 is one hour, not a block
        // that ends before it starts. The old `&& $endTime !== '00:00'` skipped the
        // roll-over for exactly the case that needs it most: `end` stayed at 00:00,
        // never got past `start`, and the loop below produced ZERO slots. Every
        // block finishing at midnight then failed to price — the customer could not
        // book the last slot of the day, and a hosted game got the court for free
        // because courtPrice() swallows the resulting exception and returns 0.
        if ($end->lte($start)) {
            $end->addDay();
        }

        $slots   = [];
        $current = $start->copy();

        while ($current->lt($end)) {
            $slotEnd = $current->copy()->addMinutes($slotMinutes);

            if ($slotEnd->gt($end)) {
                break;
            }

            $slots[] = [
                'start_time' => $current->format('H:i'),
                'end_time'   => $slotEnd->format('H:i'),
            ];

            $current = $slotEnd;
        }

        return $slots;
    }

    /**
     * Quote one contiguous block with extension pricing applied per slot index.
     */
    public function quoteBlock(
        Listing $listing,
        string $date,
        string $startTime,
        string $endTime,
        int $quantity = 1,
        ?SpecialDay $specialDay = null
    ): array {
        $weekday    = strtolower(Carbon::parse($date)->format('l'));
        $slotWindows = $this->splitBlockIntoSlots($listing, $startTime, $endTime);

        if (empty($slotWindows)) {
            throw new \InvalidArgumentException('Invalid time block: could not resolve slot windows.');
        }

        $maxExt     = $this->extensionMaxSlots($listing);
        $slotsTotal = 0.0;
        $fullTotal  = 0.0;

        foreach ($slotWindows as $index => $window) {
            $fullPrice = (float) determineSlotPrice(
                $listing,
                $weekday,
                $window['start_time'],
                $window['end_time'],
                $specialDay
            );

            $fullTotal += $fullPrice;

            $slotPrice = $this->isExtensionIndex($listing, $index, $maxExt)
                ? $this->extensionSlotPrice($listing, $fullPrice)
                : $fullPrice;

            $slotsTotal += $slotPrice;
        }

        $players = $this->isPerPerson($listing) ? max(1, $quantity) : 1;
        $slotsTotal = round($slotsTotal * $players, 2);
        $fullTotal  = round($fullTotal * $players, 2);

        return [
            'date'        => $date,
            'start_time'  => substr($startTime, 0, 5),
            'end_time'    => substr($endTime, 0, 5),
            'quantity'    => max(1, $quantity),
            'slot_count'  => count($slotWindows),
            'slots_total' => $slotsTotal,
            'full_total'  => $fullTotal,
            'savings'     => max(0, round($fullTotal - $slotsTotal, 2)),
            'addon_total' => 0.0,
            'subtotal'    => $slotsTotal,
        ];
    }

    /**
     * Quote a full customer checkout from blocks[] payload.
     *
     * @param  array<int, array{date: string, start_time: string, end_time: string, quantity?: int}>  $blocks
     */
    public function quoteCheckout(Listing $listing, array $blocks, array $addOnIds = []): array
    {
        $specialDays = [];
        $quotedBlocks = [];
        $addonsTotal  = 0.0;

        if (! empty($addOnIds)) {
            $addonsTotal = (float) AddOn::whereIn('id', $addOnIds)->sum('price');
        }

        foreach ($blocks as $index => $block) {
            $date = $block['date'];

            if (! isset($specialDays[$date])) {
                $specialDays[$date] = SpecialDay::with('timeBlocks')
                    ->where('venue_id', $listing->venue_id)
                    ->where(function ($q) use ($listing) {
                        $q->whereNull('listing_id')->orWhere('listing_id', $listing->id);
                    })
                    ->whereDate('date', $date)
                    ->active()
                    ->first();
            }

            $quoted = $this->quoteBlock(
                $listing,
                $date,
                $block['start_time'],
                $block['end_time'],
                (int) ($block['quantity'] ?? 1),
                $specialDays[$date]
            );

            if ($index === 0) {
                $quoted['addon_total'] = round($addonsTotal, 2);
                $quoted['subtotal']    = round($quoted['slots_total'] + $addonsTotal, 2);
            }

            $quotedBlocks[] = $quoted;
        }

        $combinedSubtotal = round(array_sum(array_column($quotedBlocks, 'subtotal')), 2);
        $slotsTotal       = round(array_sum(array_column($quotedBlocks, 'slots_total')), 2);
        $savings          = round(array_sum(array_column($quotedBlocks, 'savings')), 2);

        return [
            'blocks'            => $quotedBlocks,
            'combined_subtotal' => $combinedSubtotal,
            'slots_total'       => $slotsTotal,
            'addons_total'      => round($addonsTotal, 2),
            'savings'           => $savings,
            'booking_count'     => count($quotedBlocks),
        ];
    }

    /**
     * Normalize customer checkout payload to one entry per bookable slot window.
     * Expands legacy blocks[] and multi-hour spans into individual slots.
     *
     * @return array<int, array{date: string, start_time: string, end_time: string, quantity: int}>
     */
    public function normalizeCustomerSlots(Listing $listing, array $formData): array
    {
        $raw = [];

        foreach ($formData['slots'] ?? [] as $slot) {
            $raw[] = $slot;
        }

        foreach ($formData['blocks'] ?? [] as $block) {
            $raw[] = [
                'date'       => $block['date'],
                'start_time' => $block['start_time'],
                'end_time'   => $block['end_time'],
                'quantity'   => $block['quantity'] ?? 1,
            ];
        }

        $normalized = [];

        foreach ($raw as $entry) {
            $windows = $this->splitBlockIntoSlots(
                $listing,
                $entry['start_time'],
                $entry['end_time']
            );

            if (empty($windows)) {
                $normalized[] = [
                    'date'       => $entry['date'],
                    'start_time' => substr($entry['start_time'], 0, 5),
                    'end_time'   => substr($entry['end_time'], 0, 5),
                    'quantity'   => max(1, (int) ($entry['quantity'] ?? 1)),
                ];

                continue;
            }

            foreach ($windows as $window) {
                $normalized[] = [
                    'date'       => $entry['date'],
                    'start_time' => $window['start_time'],
                    'end_time'   => $window['end_time'],
                    'quantity'   => max(1, (int) ($entry['quantity'] ?? 1)),
                ];
            }
        }

        return $normalized;
    }

    /**
     * Quote individual slots with extension pricing within contiguous chains per day.
     *
     * @param  array<int, array{date: string, start_time: string, end_time: string, quantity?: int}>  $slots
     */
    public function quoteSlots(Listing $listing, array $slots, array $addOnIds = []): array
    {
        $addonsTotal = 0.0;
        if (! empty($addOnIds)) {
            $addonsTotal = (float) AddOn::whereIn('id', $addOnIds)->sum('price');
        }

        $byDate = [];
        foreach ($slots as $slot) {
            $byDate[$slot['date']][] = $slot;
        }

        $specialDays  = [];
        $quotedSlots  = [];
        $maxExt       = $this->extensionMaxSlots($listing);
        $isFirstSlot  = true;

        foreach ($byDate as $date => $daySlots) {
            usort($daySlots, fn ($a, $b) => strcmp($a['start_time'], $b['start_time']));

            if (! isset($specialDays[$date])) {
                $specialDays[$date] = SpecialDay::with('timeBlocks')
                    ->where('venue_id', $listing->venue_id)
                    ->where(function ($q) use ($listing) {
                        $q->whereNull('listing_id')->orWhere('listing_id', $listing->id);
                    })
                    ->whereDate('date', $date)
                    ->active()
                    ->first();
            }

            $weekday = strtolower(Carbon::parse($date)->format('l'));
            $chains  = $this->groupContiguousSlots($daySlots);

            foreach ($chains as $chain) {
                foreach ($chain as $index => $slot) {
                    $startTime = substr($slot['start_time'], 0, 5);
                    $endTime   = substr($slot['end_time'], 0, 5);
                    $quantity  = max(1, (int) ($slot['quantity'] ?? 1));

                    $fullPrice = (float) determineSlotPrice(
                        $listing,
                        $weekday,
                        $startTime,
                        $endTime,
                        $specialDays[$date]
                    );

                    $slotPrice = $this->isExtensionIndex($listing, $index, $maxExt)
                        ? $this->extensionSlotPrice($listing, $fullPrice)
                        : $fullPrice;

                    $players    = $this->isPerPerson($listing) ? $quantity : 1;
                    $slotsTotal = round($slotPrice * $players, 2);
                    $fullTotal  = round($fullPrice * $players, 2);

                    $quoted = [
                        'date'             => $date,
                        'start_time'       => $startTime,
                        'end_time'         => $endTime,
                        'quantity'         => $quantity,
                        'extension_index'  => $index,
                        'is_extension'     => $this->isExtensionIndex($listing, $index, $maxExt),
                        'slots_total'      => $slotsTotal,
                        'full_total'       => $fullTotal,
                        'savings'          => max(0, round($fullTotal - $slotsTotal, 2)),
                        'addon_total'      => 0.0,
                        'subtotal'         => $slotsTotal,
                    ];

                    if ($isFirstSlot) {
                        $quoted['addon_total'] = round($addonsTotal, 2);
                        $quoted['subtotal']    = round($slotsTotal + $addonsTotal, 2);
                        $isFirstSlot           = false;
                    }

                    $quotedSlots[] = $quoted;
                }
            }
        }

        $combinedSubtotal = round(array_sum(array_column($quotedSlots, 'subtotal')), 2);
        $slotsTotal       = round(array_sum(array_column($quotedSlots, 'slots_total')), 2);
        $savings          = round(array_sum(array_column($quotedSlots, 'savings')), 2);

        return [
            'slots'             => $quotedSlots,
            'combined_subtotal' => $combinedSubtotal,
            'slots_total'       => $slotsTotal,
            'addons_total'      => round($addonsTotal, 2),
            'savings'           => $savings,
            'booking_count'     => count($quotedSlots),
        ];
    }

    /**
     * Group sorted same-day slots into contiguous chains (prev.end === next.start).
     *
     * @param  array<int, array{start_time: string, end_time: string}>  $sortedSlots
     * @return array<int, array<int, array{start_time: string, end_time: string, quantity?: int, date?: string}>>
     */
    private function groupContiguousSlots(array $sortedSlots): array
    {
        $chains = [];

        foreach ($sortedSlots as $slot) {
            $lastChain = $chains[count($chains) - 1] ?? null;
            $lastSlot  = $lastChain ? $lastChain[count($lastChain) - 1] : null;

            if ($lastSlot && substr($lastSlot['end_time'], 0, 5) === substr($slot['start_time'], 0, 5)) {
                $chains[count($chains) - 1][] = $slot;
            } else {
                $chains[] = [$slot];
            }
        }

        return $chains;
    }
}
