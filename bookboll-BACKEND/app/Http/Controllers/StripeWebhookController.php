<?php
namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\StripeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    public function __construct(protected StripeService $stripeService)
    {}

    public function handle(Request $request)
    {
        Log::info('Stripe payment webhook received', ['request' => $request->all()]);
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');

        try {
            $event = $this->stripeService->constructWebhookEvent($payload, $sigHeader);
        } catch (UnexpectedValueException $e) {
            Log::warning('Stripe webhook invalid payload', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Invalid payload'], 400);
        } catch (SignatureVerificationException $e) {
            Log::warning('Stripe webhook invalid signature', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Invalid signature'], 400);
        } catch (\Throwable $e) {
            Log::error('Stripe webhook verification failed', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Webhook configuration error'], 500);
        }

        try {
            $this->stripeService->handleWebhookEvent($event);
        } catch (\Throwable $e) {
            Log::error('Stripe webhook handler failed', [
                'type'  => $event->type,
                'id'    => $event->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Handler failed'], 500);
        }

        return response()->json(['received' => true]);
    }
}
