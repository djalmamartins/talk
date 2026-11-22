<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= htmlspecialchars($csrf) ?>">
<title>Serviço da Jack · Connect Talk</title>
<link rel="stylesheet" href="/assets/app.css?v=<?= TALK_ASSET_V ?>">
<?php require __DIR__ . '/partials/icons.php'; ?>
</head>
<body data-lab="<?= $isLab ? '1' : '0' ?>" data-supervisor="<?= !empty($isSupervisor) ? '1' : '0' ?>">
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
  <p class="sv-ia" id="sv-ia" hidden></p>
  <section class="card q-table">
    <header>
      <nav class="q-tabs" id="sv-tabs" aria-label="Mostrar">
        <button type="button" data-f="all" class="active">Tudo <b></b></button>
        <button type="button" data-f="now">Fazendo agora <b></b></button>
        <button type="button" data-f="problems">Não fez ou deu problema <b></b></button>
        <button type="button" data-f="replies">Respostas enviadas <b></b></button>
        <button type="button" data-f="feedback">Sugestões <b></b></button>
      </nav>
    </header>
    <div class="q-scroll" id="sv-table-wrap">
      <table class="rp-table sv-table">
        <thead><tr><th>Situação</th><th>Quando</th><th>Contato</th><th>Protocolo</th><th>O que entendeu</th><th>Último passo</th><th class="r">Respostas</th></tr></thead>
        <tbody id="sv-list"></tbody>
      </table>
    </div>
    <div id="sv-feedback" hidden>
      <div class="rp-periods sv-fb-status" role="group" aria-label="Situação da sugestão" id="sv-fb-status">
        <button type="button" data-s="nova" class="on">Novas</button><button type="button" data-s="aplicada">Aplicadas</button><button type="button" data-s="descartada">Descartadas</button><button type="button" data-s="">Todas</button>
      </div>
      <ul class="sv-fb-list" id="sv-fb-list"></ul>
    </div>
  </section>
</main>
</div>
</div>
<dialog id="sv-dialog" class="sv-dialog" aria-labelledby="sv-d-title">
  <header class="sv-d-head">
    <div><h2 id="sv-d-title"></h2><p class="meta" id="sv-d-sub"></p></div>
    <a class="ghost btn sm" id="sv-d-open" href="#">Abrir conversa</a>
    <button type="button" class="sv-d-close" id="sv-d-close" aria-label="Fechar">×</button>
  </header>
  <div class="sv-d-body" id="sv-d-body"></div>
  <form class="sv-fb-form" id="sv-fb-form">
    <h3>Sugestão para a Jack melhorar</h3>
    <div class="sv-fb-row">
      <label>Sobre<select name="kind">
        <option value="ideia">O que ela entendeu</option><option value="resposta">A resposta dela</option>
        <option value="procedimento">O que ela fez</option><option value="outro">Outra coisa</option></select></label>
      <label>Setor certo <small class="meta">(se ela errou o setor)</small><select name="expected_sector_id" id="sv-fb-sector"><option value="">—</option></select></label>
    </div>
    <label>O que ela devia ter feito<textarea name="text" rows="3" maxlength="1000" required placeholder="Ex.: &quot;cerca elétrica desligada&quot; é Manutenção, não Administrativo."></textarea></label>
    <p class="form-error" id="sv-fb-error" role="alert" hidden></p>
    <menu><button type="submit" class="primary">Enviar sugestão</button></menu>
  </form>
</dialog>
<script src="/assets/service.js?v=<?= TALK_ASSET_V ?>" type="module"></script>
</body>
</html>
