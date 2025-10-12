@include('emails.header')
<style>
    .contact-card {
        background: #ffffff;
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        margin: 20px 0;
        overflow: hidden;
        border: 1px solid #e0e0e0;
    }

    .card-header {
        background: linear-gradient(135deg, #5a43da, #6b46c1);
        color: white;
        padding: 16px 20px;
        text-align: center;
    }

    .card-header h2 {
        margin: 0;
        font-size: 18px;
        font-weight: 600;
    }

    .card-body {
        padding: 20px;
    }

    .detail-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 0;
        border-bottom: 1px solid #f0f0f0;
    }

    .detail-item:last-child {
        border-bottom: none;
    }

    .label {
        font-weight: 600;
        color: #333;
        font-size: 14px;
        min-width: 120px;
    }

    .value {
        color: #555;
        font-size: 14px;
        text-align: right;
        flex: 1;
        word-break: break-word;
    }

    /* Responsive for mobile email clients */
    @media only screen and (max-width: 600px) {
        .detail-item {
            flex-direction: column;
            align-items: flex-start;
        }

        .value {
            text-align: left;
            margin-top: 4px;
        }
    }

</style>
<div class="content">
    <p>Hello Admin,</p>
    <p>You have received a new contact inquiry. Details are below:</p>

    <div class="contact-card">
        <div class="card-header">
            <h2>Contact Inquiry Details</h2>
        </div>
        <div class="card-body">
            <div class="detail-item">
                <span class="label">Name:</span>
                <span class="value">{{ $contact->name }}</span>
            </div>
            <div class="detail-item">
                <span class="label">Email:</span>
                <span class="value">{{ $contact->email }}</span>
            </div>
            <div class="detail-item">
                <span class="label">Subject:</span>
                <span class="value">{{ $contact->subject }}</span>
            </div>
            <div class="detail-item">
                <span class="label">Message:</span>
                <span class="value">{{ $contact->message }}</span>
            </div>
            <div class="detail-item">
                <span class="label">Submitted At:</span>
                <span class="value">{{ $contact->created_at->format('M d, Y H:i') }}</span>
            </div>
        </div>
    </div>

    <p><strong>Action Required:</strong> Please review and respond to the sender at {{ $contact->email }}.</p>
</div>

@include('emails.footer')
