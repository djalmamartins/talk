<?php
/** Menu lateral comum. $active: 'inbox' | 'queue' | 'contacts' | 'channels' | 'team' | 'schedules' | 'campaigns' | 'reports' | 'service' | 'settings'. Fila de atendimento primeiro (é a página inicial). Fora da caixa de entrada, os diálogos abrem nela. */
$active ??= 'inbox';
$onInbox = $active === 'inbox';
$initials = mb_strtoupper(implode('', array_map(fn ($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim(preg_replace('/\(.*\)/', '', $userName))), 0, 2))));
?>
<?php
// Mesmo desenho e comportamento do menu lateral do Connect ERP (cabeçalho com logo e botão de recolher,
// alternador de sistemas, grupos com título, rodapé com a pessoa), nas cores do Talk.
// Recolhido: só ícones e o símbolo Connect Talk. Tablet (901 a 1100 px) já abre recolhido; celular vira gaveta.
$link = static function (string $key, string $href, string $label, string $svg, string $badge = '', string $id = '') use ($active): string {
    $cur = $active === $key ? ' active" aria-current="page' : '';
    return '  <a class="nav' . $cur . '" href="' . htmlspecialchars($href) . '"' . ($id !== '' ? ' id="' . $id . '"' : '') . ' title="' . htmlspecialchars($label) . '">'
        . '<svg viewBox="0 0 24 24" aria-hidden="true">' . $svg . '</svg><span class="nav-label">' . htmlspecialchars($label) . '</span>'
        . ($badge !== '' ? ' <b id="' . $badge . '"></b>' : '') . "</a>\n";
};
?>
<script>try{var w=innerWidth,s=localStorage.getItem('talk.sidebar');if(w>900&&(w<=1100||s==='1'))document.querySelector('.shell').classList.add('is-collapsed')}catch(e){}</script>
<aside class="sidebar" id="sidebar" aria-label="Navegação">
  <div class="sidebar-head">
    <a class="brand-link" href="/" aria-label="Connect Talk: fila de atendimento">
      <img class="logo" src="/assets/logo.svg" alt="Connect Condomínios" width="190" height="63">
      <img class="brand-mark" src="/assets/icons/talk.svg" alt="" width="36" height="36">
    </a>
    <button type="button" class="sb-collapse" id="sb-collapse" aria-label="Recolher menu" aria-controls="sidebar" aria-expanded="true" title="Recolher menu"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg></button>
    <button type="button" class="sb-close" data-sb-close aria-label="Fechar menu"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
  </div>
  <details class="envswitch">
    <summary title="Trocar de sistema"><span class="env-mark" aria-hidden="true"><img src="/assets/icons/talk.svg" alt=""></span><span><small>Talk</small><strong>Connect Talk</strong></span><svg class="env-chev" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg></summary>
    <div class="envswitch-panel" role="menu" aria-label="Sistemas Connect">
      <small class="env-title">Sistemas Connect</small>
<?php foreach ($systems ?? [] as $sys): $tag = $sys['href'] === null ? 'span' : 'a'; ?>
      <<?= $tag ?> class="env-item<?= $sys['href'] === null ? ' off' : '' ?>"<?= $sys['href'] !== null ? ' href="' . htmlspecialchars($sys['href']) . '"' : '' ?><?= $sys['current'] ? ' aria-current="page"' : '' ?> role="menuitem">
        <span class="env-ico env-<?= htmlspecialchars($sys['key']) ?>" aria-hidden="true"><?= $sys['key'] === 'talk' ? '<img src="/assets/icons/talk.svg" alt="">' : '<svg viewBox="0 0 24 24"><path d="M4 21V8l8-5 8 5v13M9 21v-6h6v6M4 21h16"/></svg>' ?></span>
        <span><strong><?= htmlspecialchars($sys['label']) ?></strong><small><?= htmlspecialchars($sys['href'] === null ? 'Endereço ainda não configurado' : $sys['description']) ?></small></span>
        <?= $sys['current'] ? '<em>Atual</em>' : '' ?>
      </<?= $tag ?>>
<?php endforeach; ?>
    </div>
  </details>
  <nav class="sb-nav" aria-label="Menu principal">
  <span class="nav-heading">Atendimento</span>
<?= $link('queue', '/', 'Fila de atendimento', '<circle cx="9" cy="8" r="3"/><path d="M3 19c0-3 3-5 6-5s6 2 6 5M16 5a3 3 0 010 6M18 14c2 .5 3 2.4 3 5"/>', 'badge-queue') ?>
<?= $link('inbox', '/atendimentos', 'Atendimentos', '<path d="M4 5h16v10H8l-4 4z"/>', 'badge-nav') ?>
<?= $link('schedules', '/agendamentos', 'Agendamentos', '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4M12 13v3l2 1"/>', 'badge-schedules') ?>
<?= $link('quick', $onInbox ? '#' : '/atendimentos?abrir=mensagens', 'Mensagens rápidas', '<path d="M13 3L5 13h6l-1 8 8-10h-6z"/>', '', 'nav-quick') ?>
<?= $link('tags', $onInbox ? '#' : '/atendimentos?abrir=etiquetas', 'Etiquetas', '<path d="M3 12V4h8l10 10-8 8z"/><circle cx="7.5" cy="8.5" r="1.5"/>', '', 'nav-tags') ?>
  <span class="nav-heading">Acompanhamento</span>
<?= $link('reports', '/painel', 'Painel', '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>') ?>
<?= $link('contacts', '/contatos', 'Contatos', '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>') ?>
<?php if (!empty($canBulk)): ?>
<?= $link('campaigns', '/envios', 'Envio em massa', '<path d="M3 11v2a2 2 0 002 2h2l5 4V5L7 9H5a2 2 0 00-2 2zM16 8.5a5 5 0 010 7M19 6a8.5 8.5 0 010 12"/>') ?>
<?php if (!empty($jackOn)): ?>
<?= $link('service', '/servico', 'Serviço da Jack', '<rect x="5" y="8" width="14" height="11" rx="3"/><path d="M12 4v4M9 13h.01M15 13h.01M9.5 16.5h5M3 13v2M21 13v2"/>') ?>
<?php endif ?>
<?php endif; ?>
<?php if (!empty($isSupervisor)): ?>
  <span class="nav-heading">Administração</span>
<?= $link('team', '/equipe', 'Equipe e setores', '<circle cx="8" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M2 20c0-3.3 2.7-6 6-6s6 2.7 6 6M14 15.5c.9-.6 1.9-1 3-1 2.8 0 5 2.2 5 5"/>') ?>
<?= $link('channels', '/conexoes', 'Conexão WhatsApp', '<rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/>') ?>
<?= $link('settings', '/configuracoes', 'Horário de atendimento', '<circle cx="12" cy="12" r="8"/><path d="M12 7v5l3 2"/>') ?>
<?php endif; ?>
  </nav>
  <div class="sidebar-foot">
    <div class="sb-profile" title="<?= htmlspecialchars($userName) ?>">
      <span class="avatar sm" aria-hidden="true"><?= htmlspecialchars($initials) ?></span>
      <div><strong><?= htmlspecialchars($userName) ?></strong><small><?= htmlspecialchars($roleLabel ?? 'Connect Talk') ?></small></div>
    </div>
  </div>
</aside>
<div class="sb-backdrop" data-sb-close></div>
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
<script src="/assets/account.js?v=<?= TALK_ASSET_V ?>" type="module"></script>
<script src="/assets/sidebar.js?v=<?= TALK_ASSET_V ?>" type="module"></script>
<script src="/assets/topbar.js?v=<?= TALK_ASSET_V ?>" type="module"></script>
