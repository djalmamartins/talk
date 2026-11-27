<?php
declare(strict_types=1);

namespace ConnectTalk\Tests\Integration;

use ConnectTalk\Service\AccessPolicy;
use ConnectTalk\Service\AppTokens;
use ConnectTalk\Service\DomainError;
use ConnectTalk\Service\PushNotifier;
use ConnectTalk\Service\StaffRepository;
use ConnectTalk\Tests\Fixtures\FakeHttpClient;

/** App do Talk no celular: token por aparelho, login pelo ERP com código de uso único e notificações (docs/app-movel.md). */
final class AppMobileTest extends DbTestCase
{
    private AppTokens $tokens;
    private int $ana;
    private int $bia;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokens = new AppTokens($this->db);
        $this->ana = $this->db->insert('talk_users', ['name' => 'Ana Lima', 'email' => 'ana@exemplo.test', 'password_hash' => 'x']);
        $this->bia = $this->db->insert('talk_users', ['name' => 'Bia Rocha', 'email' => 'bia@exemplo.test', 'password_hash' => 'x']);
        $this->db->insert('talk_staff', ['user_id' => $this->ana, 'sector_id' => $this->sectors['Financeiro'], 'role' => 'agent']);
        $this->db->insert('talk_staff', ['user_id' => $this->bia, 'sector_id' => $this->sectors['Manutenção'], 'role' => 'agent']);
    }

    public function test_codigo_de_uso_unico_vira_token_e_so_o_hash_fica_no_banco(): void
    {
        $state = str_repeat('a', 32);
        $code = $this->tokens->issueLoginCode($this->ana, $state);

        $this->assertNull($this->tokens->redeem($code, str_repeat('b', 32)), 'state de outro pedido não vale');
        $r = $this->tokens->redeem($code, $state, 'iPhone da Ana', 'ios');
        $this->assertNotNull($r);
        $this->assertSame($this->ana, $r['user_id']);
        $this->assertMatchesRegularExpression('/^tka_[a-f0-9]{64}$/', $r['token']);
        $this->assertNull($this->tokens->redeem($code, $state), 'o código vale uma vez só');

        $row = $this->db->one('SELECT * FROM talk_app_tokens WHERE user_id = ?', [$this->ana]);
        $this->assertSame(hash('sha256', $r['token']), $row['token_hash']);
        $this->assertSame('ios', $row['platform']);
        $this->assertGreaterThan(0, (int) $row['erp_checked_at'], 'o ERP acabou de confirmar o acesso');

        $who = $this->tokens->authenticate($r['token']);
        $this->assertSame(['user_id' => $this->ana, 'name' => 'Ana Lima'], ['user_id' => $who['user_id'], 'name' => $who['name']]);
    }

    public function test_codigo_vencido_nao_vale(): void
    {
        $state = str_repeat('c', 20);
        $code = $this->tokens->issueLoginCode($this->ana, $state);
        $this->db->run('UPDATE talk_app_login_codes SET expires_at = NOW() - INTERVAL 1 SECOND');
        $this->assertNull($this->tokens->redeem($code, $state));
    }

    public function test_token_cai_ao_sair_ao_vencer_e_com_a_pessoa_desativada(): void
    {
        $t1 = $this->tokens->issue($this->ana, 'Android', 'android');
        $this->assertNotNull($this->tokens->authenticate($t1));
        $this->assertNull($this->tokens->authenticate('tka_' . str_repeat('0', 64)));
        $this->assertNull($this->tokens->authenticate('qualquer coisa'));

        $this->db->run('UPDATE talk_users SET active = 0 WHERE id = ?', [$this->ana]);
        $this->assertNull($this->tokens->authenticate($t1));
        $this->db->run('UPDATE talk_users SET active = 1 WHERE id = ?', [$this->ana]);

        $id = $this->tokens->authenticate($t1)['id'];
        $this->tokens->revoke($id);
        $this->assertNull($this->tokens->authenticate($t1));

        $t2 = $this->tokens->issue($this->ana);
        $this->db->run('UPDATE talk_app_tokens SET expires_at = NOW() - INTERVAL 1 SECOND');
        $this->assertNull($this->tokens->authenticate($t2));
    }

    public function test_endereco_de_push_valida_e_muda_de_dono_com_o_aparelho(): void
    {
        $a = $this->tokens->authenticate($this->tokens->issue($this->ana))['id'];
        $b = $this->tokens->authenticate($this->tokens->issue($this->bia))['id'];
        $push = 'ExponentPushToken[abcdefghij1234567890]';
        $this->tokens->setPushToken($a, $push);
        $this->assertSame([$this->ana => [$push]], $this->tokens->pushTargets());

        // o mesmo celular agora é da Bia: a Ana não recebe mais por ele
        $this->tokens->setPushToken($b, $push);
        $this->assertSame([$this->bia => [$push]], $this->tokens->pushTargets());

        $this->expectException(DomainError::class);
        $this->tokens->setPushToken($a, 'https://evil.example/push');
    }

    private function notifier(FakeHttpClient $http, string $preview = 'nome'): PushNotifier
    {
        return new PushNotifier($this->db, $http, new AccessPolicy(), new StaffRepository($this->db), $this->tokens, $preview);
    }

    /** @return list<array> mensagens mandadas ao Expo */
    private function sent(FakeHttpClient $http): array
    {
        $all = [];
        foreach ($http->calls as $c) {
            foreach ($c['body'] as $m) {
                $all[] = $m;
            }
        }
        return $all;
    }

    private function okTickets(int $n): array
    {
        return ['status' => 200, 'body' => json_encode(['data' => array_fill(0, $n, ['status' => 'ok', 'id' => 'x'])])];
    }

    public function test_push_avisa_fila_do_setor_e_mensagem_da_propria_conversa(): void
    {
        $anaPush = 'ExponentPushToken[ana_aparelho_0001]';
        $biaPush = 'ExponentPushToken[bia_aparelho_0001]';
        $this->tokens->setPushToken($this->tokens->authenticate($this->tokens->issue($this->ana))['id'], $anaPush);
        $this->tokens->setPushToken($this->tokens->authenticate($this->tokens->issue($this->bia))['id'], $biaPush);
        $http = new FakeHttpClient();
        $push = $this->notifier($http);

        $this->assertSame(0, $push->run(), 'a primeira rodada só marca o ponto de partida');

        $c = $this->routed('quero a segunda via do boleto'); // fila do Financeiro
        $http->responses[] = $this->okTickets(1);
        $this->assertSame(1, $push->run());
        $msgs = $this->sent($http);
        $this->assertCount(1, $msgs, 'só quem é do Financeiro');
        $this->assertSame($anaPush, $msgs[0]['to']);
        $this->assertSame('Novo na fila · Financeiro', $msgs[0]['title']);
        $this->assertSame('Maria Silva', $msgs[0]['body']);
        $this->assertSame(['conversation_id' => $c, 'kind' => 'queue'], $msgs[0]['data']);

        // nada novo: não repete
        $this->assertSame(0, $push->run());
        $this->assertCount(1, $this->sent($http));

        // a Ana pega a conversa e o cliente escreve: só a Ana é avisada, sem o texto (PUSH_PREVIEW=nome)
        $this->db->run("UPDATE talk_conversations SET status = 'open', assigned_user_id = ? WHERE id = ?", [$this->ana, $c]);
        $this->inbound('o código de barras não aparece');
        $http->responses[] = $this->okTickets(1);
        $this->assertSame(1, $push->run());
        $last = $this->sent($http)[1];
        $this->assertSame($anaPush, $last['to']);
        $this->assertSame(['Nova mensagem', 'Maria Silva'], [$last['title'], $last['body']]);
        $this->assertStringNotContainsString('código', json_encode($last, JSON_UNESCAPED_UNICODE));
    }

    public function test_push_com_texto_e_aparelho_desinstalado_sai_da_lista(): void
    {
        $anaPush = 'ExponentPushToken[ana_aparelho_0002]';
        $this->tokens->setPushToken($this->tokens->authenticate($this->tokens->issue($this->ana))['id'], $anaPush);
        $http = new FakeHttpClient();
        $push = $this->notifier($http, 'texto');
        $push->run();

        $c = $this->routed('quero a segunda via do boleto');
        $this->db->run("UPDATE talk_conversations SET status = 'open', assigned_user_id = ? WHERE id = ?", [$this->ana, $c]);
        $this->db->run('UPDATE talk_settings SET value = (SELECT MAX(id) FROM talk_events) WHERE name = ?', ['push_last_event']);
        $this->inbound('pode mandar por aqui mesmo?');
        $http->responses[] = ['status' => 200, 'body' => json_encode(['data' => [['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']]]])];
        $this->assertSame(0, $push->run());
        $msg = $this->sent($http)[0];
        $this->assertSame(['Maria Silva', 'pode mandar por aqui mesmo?'], [$msg['title'], $msg['body']]);
        $this->assertSame([], $this->tokens->pushTargets(), 'aparelho que não existe mais sai da lista');
    }

    public function test_quem_transferiu_nao_e_avisado_e_quem_recebe_e(): void
    {
        $biaPush = 'ExponentPushToken[bia_aparelho_0003]';
        $anaPush = 'ExponentPushToken[ana_aparelho_0003]';
        $this->tokens->setPushToken($this->tokens->authenticate($this->tokens->issue($this->bia))['id'], $biaPush);
        $this->tokens->setPushToken($this->tokens->authenticate($this->tokens->issue($this->ana))['id'], $anaPush);
        $http = new FakeHttpClient();
        $push = $this->notifier($http);
        $c = $this->routed('quero a segunda via do boleto');
        $push->run(); // ponto de partida depois da chegada

        $this->db->run("UPDATE talk_conversations SET status = 'open', assigned_user_id = ? WHERE id = ?", [$this->ana, $c]);
        $this->svc->transfer($c, $this->agent($this->ana, 'Financeiro'), $this->sectors['Manutenção'], 'é vazamento');
        $http->responses[] = $this->okTickets(1);
        $push->run();
        $msgs = $this->sent($http);
        $this->assertCount(1, $msgs);
        $this->assertSame($biaPush, $msgs[0]['to']);
        $this->assertSame('Transferido para a fila · Manutenção', $msgs[0]['title']);
    }
}
