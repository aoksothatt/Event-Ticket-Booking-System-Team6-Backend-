<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Password Reset OTP</title>
</head>

<body style="font-family: Arial, sans-serif; background: #f4f4f5; padding: 40px;">

    <div style=" max-width: 500px; margin: auto; background: white; padding: 30px; border-radius: 12px;">
        <h2 style="color: #18181b;">Password Reset</h2>
        <p>Hello {{ $name }},</p>
        <p>We received a request to reset your password.</p>
        <p>Your verification code is:</p>
        <div style="font-size: 32px;font-weight: bold;letter-spacing: 8px; text-align: center; padding: 20px; 
		background: #f4f4f5; border-radius: 10px;">
            {{ $otp }}
        </div>
        <p>This code will expire in <strong>5 minutes</strong>.</p>
        <p>If you did not request this password reset, you can safely ignore this email.</p>
        <p>Regards,<br>Scoloship</p>
    </div>
</body>
</html>
