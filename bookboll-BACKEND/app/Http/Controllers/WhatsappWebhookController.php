<?php

namespace App\Http\Controllers;

use App\Services\WhatsappWebhookService;
use Illuminate\Http\Request;

/**
 * The public endpoint Meta calls. See App\Services\WhatsappWebhookService.
 */
class WhatsappWebhookController extends Controller
{
    public function __construct(protected WhatsappWebhookService $webhook)
    {}

    /** Meta's one-time verification handshake. */
    public function verify(Request $request)
    {
        return $this->webhook->verify($request);
    }

    /** Inbound messages and delivery receipts. */
    public function handle(Request $request)
    {
        return $this->webhook->handle($request);
    }
}
