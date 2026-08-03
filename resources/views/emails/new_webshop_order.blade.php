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
        .order-info {
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
        .btn {
            display: inline-block;
            margin-top: 15px;
            padding: 10px 20px;
            background-color: #f97316;
            color: #fff !important;
            text-decoration: none;
            border-radius: 4px;
            font-weight: bold;
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
        <h2>Nouvelle commande webshop</h2>

        <p>Bonjour,</p>

        <p>Une nouvelle commande a été passée via le webshop.</p>

        <div class="order-info">
            <div class="info-row">
                <span class="info-label">Client :</span> {{ $clientName }}
            </div>
            <div class="info-row">
                <span class="info-label">Date :</span> {{ $orderDate }}
            </div>
            <div class="info-row">
                <span class="info-label">Total :</span> {{ $totalFormatted }}
            </div>
        </div>

        <p>
            <a href="{{ $orderUrl }}" class="btn">Voir la commande</a>
        </p>

        <div class="footer">
            © {{ date('Y') }} EuroDental
        </div>
    </div>
</body>
</html>
