<?php

namespace App\Http\Controllers;

use App\Services\BookingPayLinkService;

/**
 * Public "Pay by QR" landing (/pay/{uuid}). No session — the booking uuid in the
 * link is the credential, which is why it is a uuid and not the booking id.
 */
class BookingPayLinkController extends Controller
{
    public function __construct(protected BookingPayLinkService $payLink)
    {}

    public function show(string $uuid)
    {
        return $this->payLink->bill($uuid);
    }

    public function checkout(string $uuid)
    {
        return $this->payLink->createCheckout($uuid);
    }
}
