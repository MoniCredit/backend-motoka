<!DOCTYPE html>
<html>

<head>
    <title>Phone Verification</title>
</head>

<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
        <h1 style="color: #2d3748;">Hello, {{ $user->name }},</h1>
        <h2 style="color: #2d3748;">Verify Your Phone Number</h2>
        <p>Thank you for signing up with Motoka! Please use the verification code below to verify your phone number:</p>

        <div style="background-color: #f8fafc; padding: 15px; border-radius: 5px; margin: 20px 0; text-align: center;">
            <h1 style="color: #4299e1; letter-spacing: 5px; margin: 0;">{{ $code }}</h1>
        </div>

        <p>This code will expire in 60 minutes.</p>

        <p>If you didn't create an account with Motoka, please ignore this email.</p>

        <p style="margin-top: 30px; font-size: 14px; color: #718096;">
            Best regards,<br>
            The Motoka Team
        </p>
    </div>
</body>

</html>
