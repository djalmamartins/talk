<?php
declare(strict_types=1);

namespace ConnectTalk\Tests\Integration;

/**
 * Teste de fumaça das rotas: sobe o site de verdade (php -S, APP_ENV=lab) e confere, rota por rota,
 * o código HTTP e o formato da resposta (chaves do JSON). Serve de rede de segurança para a etapa 2
 * da revisão de arquitetura (roteador): a troca precisa deixar o retrato igual.
 *
 * Atualizar o retrato de propósito: UPDATE_ROUTES_SNAPSHOT=1 vendor/bin/phpunit --filter RoutesSmoke
 */
final class RoutesSmokeTest extends DbTestCase
{
    private const SNAPSHOT = __DIR__ . '/../Fixtures/routes-snapshot.json';

    /** @var resource|null */
    private $server = null;
    private string $base = '';
    private string $cookies = '';
    private string $csrf = '';

    protected function setUp(): void
    {
        parent::setUp();
        $dsn = (string) getenv('TEST_DB_DSN');
        preg_match_all('/(\w+)=([^;]*)/', substr($dsn, strpos($dsn, ':') + 1), $mm, PREG_SET_ORDER);
        $p = [];
        foreach ($mm as $x) {
            $p[$x[1]] = $x[2];
        }
        $port = random_int(20000, 40000);
        $this->base = "http://127.0.0.1:$port";
        $env = [
            'APP_ENV' => 'lab', 'TALK_KEY' => base64_encode(str_repeat('k', 32)), 'TZ' => 'America/Sao_Paulo',
            'DB_HOST' => isset($p['unix_socket']) ? 'localhost' : ($p['host'] ?? '127.0.0.1'),
            'DB_PORT' => $p['port'] ?? '3306', 'DB_DATABASE' => $p['dbname'] ?? 'talk_test',
            'DB_USERNAME' => (string) getenv('TEST_DB_USER'), 'DB_PASSWORD' => (string) getenv('TEST_DB_PASS'),
            'TALK_STORAGE' => $this->mediaRoot, 'TRUST_PROXY' => '1', 'JACK_MODE' => 'off', 'PATH' => (string) getenv('PATH'),
        ];
        $root = dirname(__DIR__, 2);
        $this->server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', "$root/public", "$root/public/index.php"],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes, $root, $env);
        for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
            usleep(100_000);
        }
        $this->cookies = tempnam(sys_get_temp_dir(), 'talk-cookies');
        $this->db->insert('talk_users', ['id' => 1, 'name' => 'Sara (Supervisão)', 'email' => 'sara@exemplo.test',
            'password_hash' => password_hash('segredo', PASSWORD_DEFAULT)]);
        $this->db->insert('talk_staff', ['user_id' => 1, 'sector_id' => null, 'role' => 'supervisor']);
        $this->db->insert('talk_channels', ['name' => 'Simulado', 'kind' => 'lab', 'purpose' => 'attendance',
            'config_encrypted' => $this->crypto->encrypt([])]);
    }

    protected function tearDown(): void
    {
        if ($this->server) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        @unlink($this->cookies);
        parent::tearDown();
    }

    public function test_retrato_das_rotas(): void
    {
        $shot = [];
        $who = 'anon';
        $take = function (string $method, string $path, array $data = [], bool $withCsrf = true) use (&$shot, &$who): array {
            [$status, $type, $body] = $this->request($method, $path, $data, $withCsrf);
            $shape = str_starts_with($type, 'application/json') ? $this->shape(json_decode($body, true)) : explode(';', $type)[0];
            $shot["$who $method $path"] = [$status, $shape];
            return str_starts_with($type, 'application/json') ? (array) json_decode($body, true) : [];
        };

        // sem login
        $take('GET', '/health');
        $take('GET', '/api/me');
        $take('GET', '/');
        $take('GET', '/rota-que-nao-existe');
        $take('GET', '/webhook/' . $this->channelId . '?hub_mode=subscribe&hub_verify_token=errado&hub_challenge=123');
        $take('POST', '/webhook/' . $this->channelId, ['x' => '1'], false);
        $take('GET', '/logout');
        $take('GET', '/media/1');
        $take('GET', '/lab/customer?phone=5531900000000');

        // login
        $this->request('GET', '/login');
        $take('POST', '/login', ['email' => 'sara@exemplo.test', 'password' => 'segredo', 'csrf' => $this->csrf]);
        $who = 'supervisor';

        foreach (['/', '/contatos', '/agendamentos', '/envios', '/configuracoes', '/equipe', '/conexoes', '/painel', '/fila'] as $page) {
            $take('GET', $page);
        }
        foreach (['/api/me', '/api/notifications', '/api/conversations?tab=pending', '/api/jack/stats', '/api/contacts', '/api/templates',
            '/api/campaigns', '/api/schedules', '/api/settings/hours', '/api/team/users', '/api/team/sectors', '/api/team/audit',
            '/api/channels', '/api/reports', '/api/queue', '/api/tags', '/api/quick-messages', '/api/search?q=boleto', '/api/nao-existe'] as $api) {
            $take('GET', $api);
        }

        // equipe
        $take('GET', '/api/team/users/1');
        $take('POST', '/api/team/users/1', ['name' => 'Sara (Supervisão)', 'email' => 'sara@exemplo.test', 'role' => 'supervisor']);
        // auditoria grava o IP do cliente (atrás do proxy), não o do proxy
        $this->assertSame('203.0.113.7', $this->db->one('SELECT ip FROM talk_audit_log ORDER BY id DESC LIMIT 1')['ip'] ?? null);
        $take('GET', '/api/team/sectors/' . $this->sectors['Financeiro']);
        $take('POST', '/api/team/sectors/' . $this->sectors['Financeiro'] . '/deactivate');
        $take('POST', '/api/team/sectors/' . $this->sectors['Financeiro'] . '/activate');
        $take('POST', '/api/me/password', ['current' => 'errada', 'new' => 'NovaSenha#2026']);
        $shot = array_combine(array_map(fn ($k) => preg_replace('#/sectors/\d+#', '/sectors/{id}', $k), array_keys($shot)), $shot);

        // canais (o simulado criado no setUp é o 2º canal)
        $lab = (int) $this->db->one("SELECT id FROM talk_channels WHERE kind = 'lab'")['id'];
        $take('GET', "/api/channels/$lab");
        $take('GET', "/api/channels/$lab/logout");
        $take('POST', "/api/channels/$lab/state");
        $take('POST', "/api/channels/$lab/default");
        $take('POST', "/api/channels/$lab/acao-que-nao-existe");
        $shot = array_combine(array_map(fn ($k) => preg_replace('#/channels/\d+#', '/channels/{id}', $k), array_keys($shot)), $shot);

        // envio em massa: modelo e campanha em rascunho direto no banco (sem disparar nada)
        $tpl = $this->db->insert('talk_templates', ['channel_id' => $this->channelId, 'name' => 'aviso_manutencao', 'body' => 'Olá {{1}}, aviso de manutenção.', 'variables' => 1]);
        $cid = $this->db->insert('talk_campaigns', ['name' => 'Aviso', 'channel_id' => $this->channelId, 'template_id' => $tpl,
            'sector_id' => $this->sectors['Manutenção'], 'audience' => '{"type":"list","rows":[]}', 'created_by' => 1]);
        $take('POST', '/api/campaigns/preview', ['template_id' => $tpl, 'audience' => ['type' => 'list', 'rows' => []]]);
        $take('GET', "/api/campaigns/$cid");
        $take('GET', "/api/campaigns/$cid/recipients");
        $take('POST', "/api/campaigns/$cid/cancel");
        $take('POST', "/api/templates/$tpl/delete");
        $take('GET', '/api/templates');
        $shot = array_combine(array_map(fn ($k) => preg_replace('#/campaigns/\d+#', '/campaigns/{id}', $k), array_keys($shot)), $shot);

        // uma conversa pelo simulador, e as ações principais nela
        $id = (int) ($take('POST', '/lab/simulate', ['text' => 'Preciso da segunda via do boleto', 'phone' => '5531977776666', 'name' => 'Rita'])['conversation_id'] ?? 0);
        $this->assertGreaterThan(0, $id);
        $shot = array_combine(array_map(fn ($k) => str_replace("/$id", '/{id}', $k), array_keys($shot)), $shot);
        foreach (['', '/panel', '/history'] as $sub) {
            $take('GET', "/api/conversations/$id$sub");
        }
        // ação por GET não pode rodar: o CSRF só é conferido em POST (antes, GET /tags apagava as etiquetas)
        $take('GET', "/api/conversations/$id/accept");
        $take('GET', "/api/conversations/$id/tags");
        $this->assertNull($this->conv($id)['assigned_user_id'], 'GET /accept não pode aceitar a conversa');
        $take('POST', "/api/conversations/$id/accept");
        $take('POST', "/api/conversations/$id/reply", ['text' => 'Já envio.']);
        $take('POST', "/api/conversations/$id/notes", ['note' => 'Cliente pediu por e-mail também.']);
        $take('POST', "/api/conversations/$id/read");
        $take('POST', "/api/conversations/$id/acao-que-nao-existe");
        $take('GET', '/lab/customer?phone=5531977776666');
        $take('GET', '/media/999999');

        // contatos, etiquetas, mensagens rápidas, relatórios, agendamentos e feriados
        $ct = (int) $this->conv($id)['contact_id'];
        $take('GET', "/api/contacts/$ct");
        $take('POST', "/api/contacts/$ct/rename", ['name' => 'Rita Souza']);
        $take('POST', "/api/contacts/$ct/block", ['reason' => 'teste']);
        $take('POST', "/api/contacts/$ct/unblock");
        $take('POST', "/api/contacts/$ct/apagar");
        $tag = (int) ($take('POST', '/api/tags', ['name' => 'Inadimplente', 'color' => '#c0392b'])['id'] ?? 0);
        $take('POST', "/api/tags/$tag", ['name' => 'Inadimplente 2026', 'color' => '#c0392b']);
        $take('POST', "/api/tags/$tag/delete");
        $qm = (int) ($take('POST', '/api/quick-messages', ['shortcut' => 'boleto', 'body' => 'Segue a segunda via.'])['id'] ?? 0);
        $take('POST', "/api/quick-messages/$qm/delete");
        $take('GET', '/api/reports/export.csv?from=2026-11-01&to=2026-11-30');
        $sch = (int) ($take('POST', '/api/schedules', ['contact_id' => $ct, 'channel_id' => $this->channelId, 'sector_id' => $this->sectors['Financeiro'],
            'body' => 'Lembrete do boleto', 'send_at' => date('Y-m-d H:i', strtotime('+2 days'))])['id'] ?? 0);
        $take('GET', "/api/schedules/$sch");
        $take('POST', "/api/schedules/$sch/cancel");
        $hol = (int) ($take('POST', '/api/settings/holidays', ['date' => date('Y') . '-12-24', 'name' => 'Véspera de Natal'])['id'] ?? 0);
        $take('POST', "/api/settings/holidays/$hol/delete");
        $shot = array_combine(array_map(fn ($k) => preg_replace(['#/contacts/\d+#', '#/tags/\d+#', '#/quick-messages/\d+#', '#/schedules/\d+#', '#/holidays/\d+#'],
            ['/contacts/{id}', '/tags/{id}', '/quick-messages/{id}', '/schedules/{id}', '/holidays/{id}'], $k), array_keys($shot)), $shot);
        $take('POST', '/api/me', [], false); // POST sem CSRF
        $shot = array_combine(array_map(fn ($k) => str_replace("/$id", '/{id}', $k), array_keys($shot)), $shot);

        // atendente comum: telas de supervisor voltam para a caixa de entrada
        $take('POST', '/logout', ['csrf' => $this->csrf], false);
        $who = 'anon';
        $take('GET', '/fila');
        $this->db->insert('talk_users', ['id' => 2, 'name' => 'Ana (Financeiro)', 'email' => 'ana@exemplo.test',
            'password_hash' => password_hash('segredo', PASSWORD_DEFAULT)]);
        $this->db->insert('talk_staff', ['user_id' => 2, 'sector_id' => $this->sectors['Financeiro'], 'role' => 'agent']);
        $this->request('GET', '/login');
        $this->request('POST', '/login', ['email' => 'ana@exemplo.test', 'password' => 'segredo', 'csrf' => $this->csrf], false);
        $who = 'atendente';
        foreach (['/', '/envios', '/configuracoes', '/equipe', '/conexoes', '/fila'] as $page) {
            $take('GET', $page);
        }
        $take('GET', '/api/jack/stats');

        if (getenv('UPDATE_ROUTES_SNAPSHOT') === '1' || !is_file(self::SNAPSHOT)) {
            file_put_contents(self::SNAPSHOT, json_encode($shot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
            $this->markTestIncomplete('Retrato das rotas gravado em tests/Fixtures/routes-snapshot.json; confira e rode de novo.');
        }
        $this->assertSame(json_decode((string) file_get_contents(self::SNAPSHOT), true), $shot);
    }

    /** @return array{int,string,string} status, content-type, corpo */
    private function request(string $method, string $path, array $data = [], bool $withCsrf = true): array
    {
        $ch = curl_init($this->base . $path);
        $headers = ['Accept: application/json', 'X-Forwarded-For: 203.0.113.7'];
        if ($method === 'POST' && $withCsrf) {
            $headers[] = 'X-CSRF: ' . $this->csrf;
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
            CURLOPT_COOKIEFILE => $this->cookies, CURLOPT_COOKIEJAR => $this->cookies, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        if ($status >= 300 && $status < 400) { // redirecionamento: o destino faz parte do retrato
            $type = '→ ' . (string) parse_url((string) curl_getinfo($ch, CURLINFO_REDIRECT_URL), PHP_URL_PATH);
        }
        $body = substr($raw, (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE));
        if (preg_match('/name="csrf" value="([0-9a-f]+)"/', $body, $m) || preg_match('/csrf["\']?\s*[:=]\s*["\']([0-9a-f]{32})/', $body, $m)) {
            $this->csrf = $m[1];
        }
        return [$status, $type, $body];
    }

    /** Formato do JSON: chaves de topo em ordem (numa lista, as do primeiro item), sem os valores. */
    private function shape(mixed $v): mixed
    {
        if (!is_array($v)) {
            return get_debug_type($v);
        }
        if (array_is_list($v)) {
            return $v === [] ? [] : [is_array($v[0]) && !array_is_list($v[0]) ? $this->keys($v[0]) : get_debug_type($v[0])];
        }
        return $this->keys($v);
    }

    private function keys(array $v): array
    {
        $k = array_map('strval', array_keys($v));
        sort($k);
        return $k;
    }
}
