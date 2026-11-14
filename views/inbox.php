<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= htmlspecialchars($csrf) ?>">
<title>Connect Talk</title>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body data-lab="<?= $isLab ? '1' : '0' ?>">
<div class="shell">
<nav class="sidebar" aria-label="Navegação">
  <img class="logo" src="/assets/logo.svg" alt="Connect Condomínios">
  <div class="profile">
    <span class="avatar"><?= htmlspecialchars(mb_strtoupper(implode('', array_map(fn ($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim(preg_replace('/\(.*\)/', '', $userName))), 0, 2))))) ?></span>
    <div><b><?= htmlspecialchars($userName) ?></b><span class="live" id="live" title="Tempo real">● conectando</span></div>
  </div>
  <a class="nav active" href="/"><svg viewBox="0 0 24 24"><path d="M4 5h16v10H8l-4 4z"/></svg>Atendimentos <b id="badge-nav"></b></a>
  <a class="nav" href="#" id="nav-search"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="6"/><path d="M20 20l-4.5-4.5"/></svg>Buscar conversas</a>
  <a class="nav" href="#" id="nav-quick"><svg viewBox="0 0 24 24"><path d="M13 3L5 13h6l-1 8 8-10h-6z"/></svg>Mensagens rápidas</a>
  <form class="logout" method="post" action="/logout"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"><button class="nav"><svg viewBox="0 0 24 24"><path d="M10 5H5v14h5M14 8l4 4-4 4M18 12H9"/></svg>Sair</button></form>
</nav>
<div class="page">
<header class="page-head">
  <div><h1>Atendimentos</h1><p>Acompanhe a fila e responda os clientes pelo WhatsApp.</p></div>
</header>
<main class="layout">
  <aside class="list">
    <nav class="tabs" id="tabs">
      <button data-tab="pending" class="active">Fila <b id="badge-pending"></b></button>
      <button data-tab="mine">Meus</button>
      <button data-tab="open">Em atendimento</button>
      <button data-tab="closed">Encerrados</button>
    </nav>
    <div class="filters">
      <select id="sector-filter"><option value="">Todos os setores</option></select>
      <form id="search-form"><input id="search" placeholder="Buscar mensagens, nome, telefone, protocolo"></form>
    </div>
    <ul id="conversations" class="items"></ul>
  </aside>

  <section class="chat" id="chat">
    <div class="empty">Selecione uma conversa</div>
  </section>

  <aside class="side" id="side">
    <div id="contact-panel"></div>
    <?php if ($isLab): ?>
    <div class="sim">
      <h3>Simulador de cliente (laboratório)</h3>
      <label>Telefone<input id="sim-phone" value="5531999990001"></label>
      <label>Nome<input id="sim-name" value="Maria Silva"></label>
      <div id="sim-thread" class="sim-thread"></div>
      <form id="sim-form"><input id="sim-text" placeholder="Mensagem do cliente…" autocomplete="off"><button>Enviar</button></form>
    </div>
    <?php endif ?>
  </aside>
</main>
</div>
</div>

<dialog id="transfer-dialog">
  <form method="dialog" id="transfer-form" class="xfer">
    <header><h3><svg viewBox="0 0 24 24"><path d="M4 8h13l-3-3M20 16H7l3 3"/></svg>Trocar atendente</h3>
      <p>Transfira este atendimento para outro setor ou atendente. O cliente será avisado da transferência.</p></header>
    <fieldset>
      <legend>Para onde deseja transferir?</legend>
      <div class="xfer-row"><label class="radio"><input type="radio" name="xfer-mode" value="sector" checked> Transferir para um setor</label>
        <select id="transfer-sector"></select></div>
      <div class="xfer-row"><label class="radio"><input type="radio" name="xfer-mode" value="user"> Transferir para um atendente</label>
        <select id="transfer-user" disabled><option value="">Selecione um atendente</option></select></div>
    </fieldset>
    <label class="xfer-note"><b>Relate o motivo da transferência</b>
      <span>Descreva o motivo e as informações importantes para o próximo atendente.</span>
      <textarea id="transfer-note" rows="4" maxlength="500" placeholder="Ex.: morador relata vazamento no teto da garagem, bloco A"></textarea>
      <small id="transfer-count">0/500</small></label>
    <label class="check"><input type="checkbox" id="transfer-history" checked> Manter histórico visível para o próximo atendente
      <span>O novo atendente poderá ver todo o histórico da conversa.</span></label>
    <menu><button value="cancel" formnovalidate>Cancelar</button><button value="ok" class="primary">Transferir atendimento</button></menu>
  </form>
</dialog>
<dialog id="quick-dialog">
  <div class="quick">
    <header><h3>Mensagens rápidas</h3>
      <p>No campo de resposta, digite <b>/</b> e o atalho para inserir. Campos trocados na hora:
        <code>{primeiro_nome}</code> <code>{nome}</code> <code>{protocolo}</code> <code>{setor}</code> <code>{atendente}</code>.</p></header>
    <ul id="quick-list" class="quick-list"></ul>
    <form id="quick-form" class="quick-form" hidden>
      <input type="hidden" id="quick-id">
      <div class="quick-row"><label>Atalho<input id="quick-shortcut" required maxlength="40" placeholder="bomdia"></label>
        <label>Setor<select id="quick-sector"></select></label></div>
      <label>Mensagem<textarea id="quick-body" rows="4" required maxlength="2000" placeholder="Bom dia, {primeiro_nome}! Em que posso ajudar?"></textarea></label>
      <menu><button type="button" id="quick-cancel">Cancelar</button><button class="primary">Salvar</button></menu>
    </form>
    <menu id="quick-actions"><button type="button" id="quick-new" class="primary">Nova mensagem</button><button type="button" id="quick-close">Fechar</button></menu>
  </div>
</dialog>
<dialog id="close-dialog">
  <form method="dialog">
    <h3>Encerrar atendimento</h3>
    <label>Resolução (fica no chamado)<textarea id="close-resolution" rows="3"></textarea></label>
    <menu><button value="cancel" formnovalidate>Cancelar</button><button value="ok" class="primary">Encerrar</button></menu>
  </form>
</dialog>
<script src="/assets/app.js" type="module"></script>
</body>
</html>
