<?php
namespace App\Http\Controllers;

use App\Services\WhatsappService;
use Illuminate\Http\Request;

class WhatsappController extends Controller
{
    public function __construct(protected WhatsappService $whatsappService)
    {}

    public function send(Request $request)
    {
        $data = $request->validate([
            'action'     => 'nullable|in:send_text,send_template',
            'to'         => 'required|string',
            'text'       => 'nullable|string',
            'bodyText'   => 'nullable|string',
            'template'   => 'nullable|array',
            'variables'  => 'nullable|array',
            'ownerId'    => 'nullable',
        ]);

        return sendSuccess('OK', $this->whatsappService->send($data));
    }

    public function ownerPhone(Request $request)
    {
        $id = $request->query('owner_id');

        return sendSuccess('OK', ['phone' => $this->whatsappService->ownerPhone($id)]);
    }
}
