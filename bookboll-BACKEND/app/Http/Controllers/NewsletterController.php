<?php
namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Jobs\SendNewsletter;
use App\Mail\WelcomeNewsletterEmail;
use App\Models\Subscriber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class NewsletterController extends Controller
{
    public function __construct(protected Subscriber $subscriberModel)
    {}

    public function subscribe(Request $request)
    {
        $formData = $request->validate([
            'email' => 'required|email|unique:subscribers,email',
            'name'  => 'nullable|string|max:255',
        ]);

        $subscriber = $this->subscriberModel::create($formData);

        Mail::to($subscriber->email)->send(new WelcomeNewsletterEmail($subscriber));

        return sendSuccess('Successfully subscribed!', compact('subscriber'), 201);
    }

    public function unsubscribe($email)
    {
        $subscriber = $this->subscriberModel::where('email', $email)->firstOrFail();
        $subscriber->update(['status' => 'unsubscribed']);

        return sendSuccess('Successfully unsubscribed.');
    }

    public function sendNewsletter(Request $request)
    {
        $formData = $request->validate([
            'content' => 'required|string',
            'subject' => 'required|string|max:255',
        ]);

        $subscribers = $this->subscriberModel::where('status', 'subscribed')->get();

        SendNewsletter::dispatch($formData['subject'], $formData['content'], $subscribers);

        return sendSuccess('Newsletter queued for sending.');
    }
}
