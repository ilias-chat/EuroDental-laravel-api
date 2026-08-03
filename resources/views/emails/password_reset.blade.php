<h1 style="color: #2563eb;">Bienvenue chez EuroDental !</h1>

<p>Bonjour {{ $user->first_name }},</p>

<p>Votre mot de passe a été réinitialisé. Vous pouvez maintenant vous connecter avec les identifiants suivants :</p>

<p><strong>Email :</strong> {{ $user->email }}</p>
<p><strong>Mot de passe :</strong> {{ $password }}</p>

<p>Cliquez ici pour accéder à l'application : <a href="{{ config('app.url') }}/admin" style="color: #2563eb;">{{ config('app.url') }}/admin</a></p>

<p>Veuillez changer votre mot de passe après votre première connexion.</p>

<p style="margin-top: 30px;">Cordialement,<br><strong>L'équipe EuroDental</strong></p>
