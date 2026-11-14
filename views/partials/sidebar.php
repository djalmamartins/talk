<?php
/** Menu lateral comum. $active: 'inbox' | 'queue' | 'contacts' | 'channels' | 'team'. Fora da caixa de entrada, os diálogos abrem nela. */
$active ??= 'inbox';
$onInbox = $active === 'inbox';
$initials = mb_strtoupper(implode('', array_map(fn ($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim(preg_replace('/\(.*\)/', '', $userName))), 0, 2))));
?>
<nav class="sidebar" aria-label="Navegação">
  <img class="logo" src="/assets/logo.svg" alt="Connect Condomínios">
  <div class="profile">
    <span class="avatar"><?= htmlspecialchars($initials) ?></span>
    <div><b><?= htmlspecialchars($userName) ?></b><span class="live" id="live" title="Tempo real">● conectando</span><button type="button" class="link" id="my-password">Trocar senha</button></div>
  </div>
  <a class="nav<?= $active === 'inbox' ? ' active' : '' ?>" href="/"><svg viewBox="0 0 24 24"><path d="M4 5h16v10H8l-4 4z"/></svg>Atendimentos <b id="badge-nav"></b></a>
  <a class="nav<?= $active === 'queue' ? ' active' : '' ?>" href="/fila"><svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><path d="M3 19c0-3 3-5 6-5s6 2 6 5M16 5a3 3 0 010 6M18 14c2 .5 3 2.4 3 5"/></svg>Fila de atendimento <b id="badge-queue"></b></a>
  <a class="nav<?= $active === 'contacts' ? ' active' : '' ?>" href="/contatos"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>Contatos</a>
<?php if (!empty($isSupervisor)): ?>
  <a class="nav<?= $active === 'team' ? ' active' : '' ?>" href="/equipe"><svg viewBox="0 0 24 24"><circle cx="8" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M2 20c0-3.3 2.7-6 6-6s6 2.7 6 6M14 15.5c.9-.6 1.9-1 3-1 2.8 0 5 2.2 5 5"/></svg>Equipe e setores</a>
  <a class="nav<?= $active === 'channels' ? ' active' : '' ?>" href="/conexoes"><svg viewBox="0 0 24 24"><rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/></svg>Conexão WhatsApp</a>
<?php endif; ?>
  <a class="nav" href="<?= $onInbox ? '#' : '/?abrir=busca' ?>" id="nav-search"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="6"/><path d="M20 20l-4.5-4.5"/></svg>Buscar conversas</a>
  <a class="nav" href="<?= $onInbox ? '#' : '/?abrir=mensagens' ?>" id="nav-quick"><svg viewBox="0 0 24 24"><path d="M13 3L5 13h6l-1 8 8-10h-6z"/></svg>Mensagens rápidas</a>
  <a class="nav" href="<?= $onInbox ? '#' : '/?abrir=etiquetas' ?>" id="nav-tags"><svg viewBox="0 0 24 24"><path d="M3 12V4h8l10 10-8 8z"/><circle cx="7.5" cy="8.5" r="1.5"/></svg>Etiquetas</a>
  <form class="logout" method="post" action="/logout"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"><button class="nav"><svg viewBox="0 0 24 24"><path d="M10 5H5v14h5M14 8l4 4-4 4M18 12H9"/></svg>Sair</button></form>
</nav>
<dialog id="pw-dialog">
  <form method="dialog" id="pw-form">
    <h3>Trocar minha senha</h3>
    <label>Senha atual<input type="password" name="current" autocomplete="current-password" required></label>
    <label>Nova senha<input type="password" name="new" autocomplete="new-password" minlength="8" maxlength="72" required></label>
    <label>Repita a nova senha<input type="password" name="again" autocomplete="new-password" required></label>
    <p class="meta">Pelo menos 8 caracteres, com letras e números. Você continua conectado aqui; em outros aparelhos será preciso entrar de novo.</p>
    <p class="form-error" id="pw-error" role="alert" hidden></p>
    <menu><button value="cancel" formnovalidate>Cancelar</button><button value="ok" class="primary">Salvar</button></menu>
  </form>
</dialog>
<script src="/assets/account.js" type="module"></script>
