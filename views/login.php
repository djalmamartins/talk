<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Connect Talk — Entrar</title>
<link rel="stylesheet" href="/assets/app.css?v=<?= TALK_ASSET_V ?>">
<?php require __DIR__ . '/partials/icons.php'; ?>
</head>
<body class="login-page">
<form class="login-card" method="post" action="/login">
  <img src="/assets/logo.svg" alt="Connect Condomínios" class="login-logo">
  <h1>Talk</h1>
  <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif ?>
  <?php if (!empty($erpLogin)): ?>
  <p class="hint">Use o mesmo e-mail e senha do Connect ERP. Só entra quem tem a permissão de atendimento por WhatsApp.</p>
  <a class="button primary login-erp" href="<?= htmlspecialchars($erpLogin) ?>" rel="noreferrer">Entrar pelo Connect ERP</a>
  <?php else: ?>
  <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
  <label>E-mail<input type="email" name="email" required autofocus></label>
  <label>Senha<input type="password" name="password" required></label>
  <button type="submit">Entrar</button>
  <?php endif ?>
  <?php if ($isLab && empty($erpLogin)): ?>
  <p class="hint">Laboratório: supervisor@connect.lab, financeiro@connect.lab, administrativo@connect.lab,
    manutencao@connect.lab, gerente.manutencao@connect.lab · senha <b>connect123</b></p>
  <?php endif ?>
</form>
</body>
</html>
