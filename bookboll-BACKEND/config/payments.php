<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Minimum Online Charge
    |--------------------------------------------------------------------------
    |
    | Stripe refuses any charge whose total converts to less than about US$0.50,
    | and answers with a message naming the conversion — "₨50.00 converts to
    | approximately $0.26" — which is not something a customer should ever read.
    |
    | These floors are checked BEFORE a Checkout Session is created, so the
    | refusal is ours, arrives instantly, and says what to do about it.
    |
    | A currency absent from this map is NOT checked; Stripe decides. That is
    | deliberate — a late refusal from Stripe is better than this app blocking a
    | payment that would have gone through. Add a currency here only once you
    | know its floor.
    |
    | Values are in MAJOR units of each currency, and are approximate: they
    | track an exchange rate, so leave headroom and revisit them occasionally.
    |
    */

    'minimum_charge' => [
        'USD' => 0.50,
        'EUR' => 0.50,
        'GBP' => 0.30,
        'AED' => 2.00,
        'SAR' => 2.00,
        'QAR' => 2.00,
        'PKR' => 200.00,
        'INR' => 50.00,
    ],

];
