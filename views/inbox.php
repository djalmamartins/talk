<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= htmlspecialchars($csrf) ?>">
<title>Connect Talk</title>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body data-lab="<?= $isLab ? '1' : '0' ?>">
<header class="top">
  <div class="brand">Connect <span>Talk</span></div>
  <div class="live" id="live" title="Tempo real">● conectando</div>
  <div class="me"><?= htmlspecialchars($userName) ?>
    <form method="post" action="/logout"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"><button class="link">Sair</button></form>
  </div>
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

<dialog id="transfer-dialog">
  <form method="dialog" id="transfer-form">
    <h3>Transferir conversa</h3>
    <label>Para o setor<select id="transfer-sector"></select></label>
    <label>Nota interna (o cliente não vê)<textarea id="transfer-note" rows="3" placeholder="Ex.: morador relata vazamento no 101"></textarea></label>
    <menu><button value="cancel" formnovalidate>Cancelar</button><button value="ok" class="primary">Transferir</button></menu>
  </form>
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
