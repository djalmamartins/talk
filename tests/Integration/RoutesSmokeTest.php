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
            'TALK_STORAGE' => $this->mediaRoot, 'JACK_MODE' => 'off', 'PATH' => (string) getenv('PATH'),
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
        $take('POST', '/api/me', [], false); // POST sem CSRF
        $shot = array_combine(array_map(fn ($k) => str_replace("/$id", '/{id}', $k), array_keys($shot)), $shot);

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
        $headers = ['Accept: application/json'];
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
