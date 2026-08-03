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
            color: #16a34a;
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
            background-color: #dcfce7;
            border-left: 4px solid #16a34a;
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
        .footer {
            margin-top: 30px;
            color: #999;
            font-size: 11px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Demande de congé approuvée</h2>
        
        <p>Bonjour <strong>{{ $userName }}</strong>,</p>
        
        <p>Votre demande de congé a été approuvée.</p>

        <div class="request-info">
            <div class="info-row">
                <span class="info-label">Période :</span> {{ $startDate }} - {{ $endDate }}
            </div>
            @if($leaveType)
            <div class="info-row">
                <span class="info-label">Type :</span> {{ $leaveType }}
            </div>
            @endif
            <div class="info-row">
                <span class="info-label">Approuvée par :</span> {{ $reviewedByName }}
            </div>
        </div>

        <p>Nous vous souhaitons de bonnes vacances !</p>

        <div class="footer">
            © {{ date('Y') }} EuroDental
        </div>
    </div>
</body>
</html>

