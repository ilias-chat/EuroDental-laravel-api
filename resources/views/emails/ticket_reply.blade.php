<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; background-color: #ffffff; }
        .container { padding: 20px; }
        h2 { color: #2563eb; margin: 0 0 20px 0; font-size: 20px; font-weight: bold; }
        p { margin: 0 0 20px 0; color: #555; }
        .reply-info { margin: 20px 0; padding: 15px; background-color: #eff6ff; border-left: 4px solid #2563eb; border-radius: 4px; }
        .info-row { margin: 8px 0; font-size: 14px; color: #555; }
        .info-label { font-weight: bold; color: #333; }
        a.btn { display: inline-block; margin-top: 15px; padding: 10px 20px; background-color: #2563eb; color: #fff !important; text-decoration: none; border-radius: 6px; }
        .footer { margin-top: 30px; color: #999; font-size: 11px; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Nouvelle réponse sur un ticket</h2>
        <p>Bonjour,</p>
        <p><strong>{{ $authorName }}</strong> a répondu au ticket « {{ $ticket->subject }} ».</p>
        <div class="reply-info">
            <div class="info-row"><span class="info-label">Réponse :</span><br>{{ Str::limit($reply->body, 300) }}</div>
        </div>
        <p><a href="{{ url()->route('admin.tickets.show', $ticket) }}" class="btn">Voir le ticket</a></p>
        <div class="footer">© {{ date('Y') }} EuroDental</div>
    </div>
</body>
</html>
