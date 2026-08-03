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
            color: #f97316;
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
            background-color: #fef3c7;
            border-left: 4px solid #f97316;
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
        <h2>Nouvelle demande de congé</h2>
        
        <p>Bonjour,</p>
        
        <p>Une nouvelle demande de congé a été soumise par <strong>{{ $userName }}</strong>.</p>

        <div class="request-info">
            <div class="info-row">
                <span class="info-label">Utilisateur :</span> {{ $userName }}
            </div>
            <div class="info-row">
                <span class="info-label">Période :</span> {{ $startDate }} - {{ $endDate }}
            </div>
            @if($leaveType)
            <div class="info-row">
                <span class="info-label">Type :</span> {{ $leaveType }}
            </div>
            @endif
            @if($justificationMethod)
            <div class="info-row">
                <span class="info-label">Justification envoyée via :</span> {{ $justificationMethod }}
            </div>
            @endif
            @if($description)
            <div class="info-row" style="margin-top: 12px;">
                <span class="info-label">Description :</span><br>
                {{ $description }}
            </div>
            @endif
        </div>

        <p>Veuillez examiner cette demande dans la section administration.</p>

        <div class="footer">
            © {{ date('Y') }} EuroDental
        </div>
    </div>
</body>
</html>

