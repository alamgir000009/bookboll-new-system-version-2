<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\MarketingMailService;
use Illuminate\Http\Request;

/**
 * Automated service notifications by email — the counterpart of
 * /admin/notifications/whatsapp. Transactional, so never consent-gated.
 */
class NotificationEmailController extends Controller
{
    public function __construct(protected MarketingMailService $mail)
    {}

    public function send(Request $request)
    {
        $data = $request->validate([
            'to'      => 'required|email|max:191',
            'subject' => 'required|string|max:191',
            'body'    => 'required|string',
        ]);

        // The sender comes from the caller's own business, never from the request.
        $result = $this->mail->sendNotification(
            $this->mail->ownerFor($request->user()),
            $data['to'],
            $data['subject'],
            $data['body'],
        );

        return sendSuccess('OK', $result);
    }
}
