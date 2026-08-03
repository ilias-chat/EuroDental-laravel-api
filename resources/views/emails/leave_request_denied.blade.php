<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #ffffff;
        }
        .container {
            background-color: #ffffff;
            padding: 20px;
        }
        h2 {
            color: #dc2626;
            margin: 0 0 20px 0;
            font-size: 20px;
            font-weight: bold;
        }
        p {
            margin: 0 0 20px 0;
            color: #555;
        }
        .request-info {
            margin: 20px 0;
            padding: 15px;
            background-color: #fee2e2;
            border-left: 4px solid #dc2626;
            border-radius: 4px;
        }
        .info-row {
            margin: 8px 0;
            font-size: 14px;
            color: #555;
        }
        .info-label {
            font-weight: bold;
            color: #333;
        }
        .reason-box {
            margin-top: 15px;
            padding: 10px;
            background-color: #ffffff;
            border-radius: 4px;
            border: 1px solid #dc2626;
        }
        .footer {
            margin-top: 30px;
            color: #999;
            font-size: 11px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Demande de congé refusée</h2>
        
        <p>Bonjour <strong>{{ $userName }}</strong>,</p>
        
        <p>Votre demande de congé a été refusée.</p>

        <div class="request-info">
            <div class="info-row">
                <span class="info-label">Période :</span> {{ $startDate }} - {{ $endDate }}
            </div>
            @if($leaveType)
            <div class="info-row">
                <span class="info-label">Type :</span> {{ $leaveType }}
            </div>
            @endif
            @if($rejectionReason)
            <div class="reason-box">
                <div class="info-label" style="margin-bottom: 8px;">Raison du refus :</div>
                <div>{{ $rejectionReason }}</div>
            </div>
            @endif
        </div>

        <p>Pour plus d'informations, veuillez contacter l'administration.</p>

        <div class="footer">
            © {{ date('Y') }} EuroDental
        </div>
    </div>
</body>
</html>

