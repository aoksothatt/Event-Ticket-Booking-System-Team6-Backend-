<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Your Tickets</title>
</head>

<body style="font-family: Arial, sans-serif; background: #f4f4f5; padding: 40px;">

    <div style="max-width: 560px; margin: auto; background: white; padding: 30px; border-radius: 12px;">
        <h2 style="color: #18181b;">Thank you for your purchase!</h2>
        <p>Hello {{ $booking->user->name ?? 'there' }},</p>
        <p>Your payment has been confirmed and your tickets are ready.</p>

        <table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
            <tr style="background: #f4f4f5;">
                <th style="text-align: left; padding: 8px;">Ticket</th>
                <th style="text-align: left; padding: 8px;">Number</th>
                <th style="text-align: right; padding: 8px;">Status</th>
            </tr>
            @foreach ($tickets as $ticket)
                <tr style="border-bottom: 1px solid #e4e4e7;">
                    <td style="padding: 8px;">{{ $ticket->ticketType?->name ?? 'Ticket' }}</td>
                    <td style="padding: 8px;">{{ $ticket->ticket_number }}</td>
                    <td style="padding: 8px; text-align: right;">Active</td>
                </tr>
            @endforeach
        </table>

        <p>
            Booking reference: <strong>{{ $booking->booking_number }}</strong><br>
            Total paid: <strong>{{ $booking->total_amount }} USD</strong>
        </p>

        <p>Scan the attached QR code at the event entrance to check in.</p>
        <p>If you did not make this purchase, please contact support.</p>
        <p>Regards,<br>{{ config('app.name') }}</p>
    </div>
</body>
</html>