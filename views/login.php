<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Connect Talk — Entrar</title>
<link rel="stylesheet" href="/assets/app.css">
<?php require __DIR__ . '/partials/icons.php'; ?>
</head>
<body class="login-page">
<form class="login-card" method="post" action="/login">
  <img src="/assets/logo.svg" alt="Connect Condomínios" class="login-logo">
  <h1>Talk</h1>
  <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif ?>
  <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
  <label>E-mail<input type="email" name="email" required autofocus></label>
  <label>Senha<input type="password" name="password" required></label>
  <button type="submit">Entrar</button>
  <?php if ($isLab): ?>
  <p class="hint">Laboratório: supervisor@connect.lab, financeiro@connect.lab, administrativo@connect.lab,
    manutencao@connect.lab, gerente.manutencao@connect.lab · senha <b>connect123</b></p>
  <?php endif ?>
</form>
</body>
</html>
