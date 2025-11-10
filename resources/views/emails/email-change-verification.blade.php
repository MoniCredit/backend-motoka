<!DOCTYPE html>
<html>

<head>
    <title>Email Change Verification</title>
</head>

<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
        <h1 style="color: #2d3748;">Hello, {{ $user->name }},</h1>
        <h2 style="color: #2d3748;">Verify Your New Email Address</h2>
        <p>You have requested to change your email address to: <strong>{{ $newEmail }}</strong></p>
        
        <p>Please use the verification code below to confirm this change:</p>

        <div style="background-color: #f8fafc; padding: 15px; border-radius: 5px; margin: 20px 0; text-align: center;">
            <h1 style="color: #4299e1; letter-spacing: 5px; margin: 0;">{{ $code }}</h1>
        </div>

        <p>This code will expire in 10 minutes.</p>

        <div style="background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0;">
            <p style="margin: 0; color: #856404;">
                <strong>⚠️ Security Notice:</strong> If you did not request this email change, please ignore this message and your email will remain unchanged. Consider changing your password immediately if you suspect unauthorized access.
            </p>
        </div>

        <p style="margin-top: 30px; font-size: 14px; color: #718096;">
            Best regards,<br>
            The Motoka Team
        </p>
    </div>
</body>

</html>