<?php
declare(strict_types=1);

// Confere a ligação com o Connect ERP (ADR 0003 e 0005): endereço, token e se a API do Talk responde.
//   php bin/erp-check.php        sai com 0 se está tudo certo (ou se o ERP ainda não foi configurado)
// Faz uma busca de pessoa por um telefone que não existe: não grava nada no ERP e não mostra dado de ninguém.
use ConnectTalk\Erp\ErpApi;
use ConnectTalk\Http\CurlHttpClient;

$app = require __DIR__ . '/../bootstrap.php';
$env = $app['env'];
$url = trim((string) $env('ERP_API_URL', ''));
$token = trim((string) $env('ERP_API_TOKEN', ''));
if ($url === '') {
    echo "ERP ainda não configurado (ERP_API_URL vazio): o Talk funciona sozinho, sem nomes do cadastro nem chamados.\n";
    exit(0);
}
if (!preg_match('#^https?://[^/]+.*/api/v1/?$#', $url)) {
    fwrite(STDERR, "ERP_API_URL precisa terminar em /api/v1 (ex.: https://erp.connectcondominios.com.br/api/v1).\n");
    exit(1);
}
if (!preg_match('/^ctk_[0-9a-f]{64}$/', $token)) {
    fwrite(STDERR, "ERP_API_TOKEN vazio ou fora do formato ctk_... Gere no servidor do ERP: php tools/talk-token.php criar --usuario=EMAIL\n");
    exit(1);
}
try {
    $r = (new ErpApi(new CurlHttpClient(10), $url, $token))->call('GET', '/talk/people?phones=5500000000000');
} catch (\Throwable $e) {
    $m = $e->getMessage();
    $hint = str_contains($m, ' 401') ? 'o ERP recusou o token (revogado, digitado errado, ou o usuário de integração perdeu a permissão).'
        : (str_contains($m, ' 429') ? 'muitas tentativas erradas deste endereço; espere 15 minutos.'
        : (str_contains($m, ' 403') ? 'o IP do Pi não está liberado no TALK_ALLOWED_IPS do ERP.' : 'o ERP não respondeu; confira o endereço e a internet.'));
    fwrite(STDERR, "Ligação com o ERP falhou: $hint\n($m)\n");
    exit(1);
}
if ($r['status'] !== 200 || !is_array($r['data']['data'] ?? null)) {
    fwrite(STDERR, "O endereço respondeu {$r['status']}, mas não parece a API do ERP. Confira o ERP_API_URL.\n");
    exit(1);
}
echo "Ligação com o ERP OK: token aceito em $url.\n";
