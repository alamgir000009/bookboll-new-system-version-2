<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Backup</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
        <h2 style="color: #2c3e50;">Database Backup</h2>
        
        <p>Hello,</p>
        
        <p>This email contains the database backup that was requested on <strong>{{ now()->format('F d, Y \a\t H:i:s') }}</strong>.</p>
        
        <p>The backup file is attached to this email.</p>
        
        <div style="background-color: #f4f4f4; padding: 15px; border-radius: 5px; margin: 20px 0;">
            <p style="margin: 0;"><strong>Backup Details:</strong></p>
            <ul style="margin: 10px 0 0 20px; padding: 0;">
                <li>File Name: {{ $backupFileName }}</li>
                <li>Generated At: {{ now()->format('Y-m-d H:i:s') }}</li>
            </ul>
        </div>
        
        <p style="margin-top: 30px;">Please keep this backup file in a secure location.</p>
        
        <p style="margin-top: 20px;">Best regards,<br>{{ config('app.name') }}</p>
    </div>
</body>
</html>

