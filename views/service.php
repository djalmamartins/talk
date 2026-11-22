<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= htmlspecialchars($csrf) ?>">
<title>Serviço da Jack · Connect Talk</title>
<link rel="stylesheet" href="/assets/app.css?v=<?= TALK_ASSET_V ?>">
<?php require __DIR__ . '/partials/icons.php'; ?>
</head>
<body data-lab="<?= $isLab ? '1' : '0' ?>">
<div class="shell">
<?php $active = 'service'; require __DIR__ . '/partials/sidebar.php'; ?>
<div class="page">
<?php require __DIR__ . '/partials/topbar.php'; ?>
<header class="page-head">
  <div><h1>Serviço da Jack</h1><p id="sv-sub">O que a Jack está fazendo, o que ela não fez, as respostas que mandou, o que entendeu e o procedimento que seguiu.</p></div>
  <input id="sv-search" class="q-search" type="search" placeholder="Protocolo, chamado ou contato…" aria-label="Buscar">
</header>
<div class="rp-filters">
  <div class="rp-periods" role="group" aria-label="Período" id="sv-days">
    <button type="button" data-d="1" class="on">Hoje</button><button type="button" data-d="7">7 dias</button><button type="button" data-d="30">30 dias</button>
  </div>
  <small class="meta" id="sv-updated">Atualizando…</small>
</div>
<main class="reports sv">
  <p class="form-error" id="sv-error" role="alert" hidden></p>
  <p class="sv-off" id="sv-off" hidden>A Jack está desligada (JACK_MODE=off): as conversas vão direto para o setor padrão e ela não faz nada.</p>
  <section class="card"><div class="kpis rp-kpis" id="sv-kpis"></div></section>
  <section class="card q-table">
    <header>
      <nav class="q-tabs" id="sv-tabs" aria-label="Mostrar">
        <button type="button" data-f="all" class="active">Tudo <b></b></button>
        <button type="button" data-f="now">Fazendo agora <b></b></button>
        <button type="button" data-f="problems">Não fez ou deu problema <b></b></button>
        <button type="button" data-f="replies">Respostas enviadas <b></b></button>
      </nav>
    </header>
    <ul class="sv-list" id="sv-list" aria-live="polite"></ul>
  </section>
</main>
</div>
</div>
<script src="/assets/service.js?v=<?= TALK_ASSET_V ?>" type="module"></script>
</body>
</html>
