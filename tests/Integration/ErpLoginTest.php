<?php
declare(strict_types=1);

namespace ConnectTalk\Tests\Integration;

use ConnectTalk\Domain\StaffMember;
use ConnectTalk\Erp\ErpApi;
use ConnectTalk\Realtime\EventBus;
use ConnectTalk\Service\DomainError;
use ConnectTalk\Service\ErpLogin;
use ConnectTalk\Service\TeamService;
use ConnectTalk\Tests\Fixtures\FakeHttpClient;

/** Login único pelo ERP (ADR 0006): só quem o ERP libera (talk.access) entra; o ERP pode tirar o acesso depois. */
final class ErpLoginTest extends DbTestCase
{
    private FakeHttpClient $erpHttp;
    private string $code;

    protected function setUp(): void
    {
        parent::setUp();
        $this->erpHttp = new FakeHttpClient();
        $this->code = str_repeat('ab', 32);
    }

    private function login(bool $on = true): ErpLogin
    {
        return new ErpLogin($this->db, new ErpApi($this->erpHttp, 'https://erp.connect.lab/api/v1', 'TOKEN'), $on, 600);
    }

    private function erpSays(int $status, ?array $data = null): void
    {
        $this->erpHttp->responses[] = ['status' => $status, 'body' => json_encode($data !== null ? ['data' => $data] : ['error' => ['code' => 'not_found']])];
    }

    public function test_primeiro_login_cria_o_perfil_sem_setor(): void
    {
        $this->erpSays(200, ['id' => 41, 'name' => 'Ana Lima', 'email' => 'ana@connect.com.br']);
        $who = $this->login()->exchange($this->code, '10.0.0.5');
        $u = $this->db->one('SELECT * FROM talk_users WHERE id = ?', [$who['id']]);
        $this->assertSame(41, (int) $u['erp_user_id']);
        $this->assertSame('ana@connect.com.br', $u['email']);
        $this->assertNull($this->db->one('SELECT id FROM talk_staff WHERE user_id = ?', [$who['id']]), 'Papel e setores: o supervisor escolhe.');
        $this->assertSame('POST', $this->erpHttp->calls[0]['method']);
        $this->assertSame('https://erp.connect.lab/api/v1/talk/staff/login', $this->erpHttp->calls[0]['url']);
        $this->assertSame(['code' => $this->code], $this->erpHttp->calls[0]['body']);
        $this->assertNotNull($this->db->one("SELECT id FROM talk_audit_log WHERE action = 'erp_first_login' AND target_id = ?", [$who['id']]));
    }

    public function test_liga_pelo_email_o_supervisor_do_setup_e_depois_pelo_id(): void
    {
        $sup = $this->db->insert('talk_users', ['name' => 'Djalma', 'email' => 'djalma@connect.com.br', 'password_hash' => 'x']);
        $this->db->insert('talk_staff', ['user_id' => $sup, 'sector_id' => null, 'role' => 'supervisor']);
        $this->erpSays(200, ['id' => 7, 'name' => 'Djalma Martins', 'email' => 'djalma@connect.com.br']);
        $this->assertSame($sup, $this->login()->exchange($this->code)['id']);
        // trocou o e-mail no ERP: continua o mesmo perfil, pelo id
        $this->erpSays(200, ['id' => 7, 'name' => 'Djalma Martins', 'email' => 'outro@connect.com.br']);
        $this->assertSame($sup, $this->login()->exchange($this->code)['id']);
        $this->assertSame('Djalma Martins', $this->db->one('SELECT name FROM talk_users WHERE id = ?', [$sup])['name']);
    }

    public function test_recusa(): void
    {
        $this->erpSays(404);
        $this->assertNull($this->login()->exchange($this->code), 'ERP recusou (sem talk.access, vencido, usado).');
        $this->assertNull($this->login()->exchange('nao-e-codigo'), 'Código malformado nem vai ao ERP.');
        $this->assertCount(1, $this->erpHttp->calls);
        $this->assertNull($this->login(false)->exchange($this->code), 'TALK_LOGIN=senha.');

        $off = $this->db->insert('talk_users', ['name' => 'Saiu', 'email' => 'saiu@connect.com.br', 'password_hash' => 'x', 'erp_user_id' => 9, 'active' => 0]);
        $this->erpSays(200, ['id' => 9, 'name' => 'Saiu', 'email' => 'saiu@connect.com.br']);
        $this->assertNull($this->login()->exchange($this->code), 'Desativado no Talk não entra.');

        // e-mail de um perfil já ligado a outra conta do ERP: não toma o perfil
        $this->db->insert('talk_users', ['name' => 'Bia', 'email' => 'bia@connect.com.br', 'password_hash' => 'x', 'erp_user_id' => 10]);
        $this->erpSays(200, ['id' => 11, 'name' => 'Outra', 'email' => 'bia@connect.com.br']);
        $this->assertNull($this->login()->exchange($this->code));
        $this->assertGreaterThan(0, $off);
    }

    public function test_token_sem_escopo_ou_erp_fora_do_ar_avisam(): void
    {
        $this->erpHttp->responses[] = ['status' => 403, 'body' => json_encode(['error' => ['code' => 'insufficient_scope', 'message' => 'Este token não tem o escopo staff.login.']])];
        $this->expectException(\RuntimeException::class);
        $this->login()->exchange($this->code);
    }

    public function test_revalida_a_cada_10_minutos_e_mantem_se_o_erp_cair(): void
    {
        $id = $this->db->insert('talk_users', ['name' => 'Ana', 'email' => 'ana@connect.com.br', 'password_hash' => 'x', 'erp_user_id' => 41]);
        $login = $this->login();
        $checked = 1000;
        $this->assertTrue($login->revalidate($id, $checked, 1500), 'Antes de 10 minutos nem pergunta.');
        $this->assertCount(0, $this->erpHttp->calls);

        $this->erpSays(200, ['id' => 41, 'name' => 'Ana', 'email' => 'ana@connect.com.br', 'active' => true]);
        $this->assertTrue($login->revalidate($id, $checked, 1700));
        $this->assertSame(1700, $checked);
        $this->assertSame('https://erp.connect.lab/api/v1/talk/staff/41', $this->erpHttp->calls[0]['url']);

        $this->erpHttp->responses[] = new \RuntimeException('timeout');
        $this->assertTrue($login->revalidate($id, $checked, 2400), 'ERP fora do ar: a sessão continua.');
        $this->assertSame(1700, $checked, 'E pergunta de novo na próxima.');

        $this->erpSays(200, ['id' => 41, 'name' => 'Ana', 'email' => 'ana@connect.com.br', 'active' => false]);
        $this->assertFalse($login->revalidate($id, $checked, 2401), 'Tirou talk.access no ERP: sai.');

        $semErp = $this->db->insert('talk_users', ['name' => 'Local', 'email' => 'local@x.com', 'password_hash' => 'x']);
        $c = 0;
        $this->assertFalse($login->revalidate($semErp, $c, 5000), 'Com login pelo ERP, perfil sem conta do ERP não fica.');
        $this->assertTrue($this->login(false)->revalidate($semErp, $c, 5000));
    }

    public function test_sem_trocar_senha_com_login_pelo_erp(): void
    {
        $sup = $this->db->insert('talk_users', ['name' => 'Djalma', 'email' => 'sup@x.com', 'password_hash' => 'x', 'erp_user_id' => 7]);
        $team = new TeamService($this->db, new EventBus($this->db), '10.0.0.1', true);
        $this->expectException(DomainError::class);
        $team->changeOwnPassword($sup, 'x', 'novaSenha123');
    }

    public function test_equipe_vem_do_erp(): void
    {
        $ana = $this->db->insert('talk_users', ['name' => 'Ana', 'email' => 'ana@connect.com.br', 'password_hash' => 'x']); // cadastrada antes, pelo e-mail
        $old = $this->db->insert('talk_users', ['name' => 'Saiu', 'email' => 'saiu@connect.com.br', 'password_hash' => 'x', 'erp_user_id' => 9]);
        $conv = $this->inbound('oi');
        $this->db->run("UPDATE talk_conversations SET status = 'open', assigned_user_id = ? WHERE id = ?", [$old, $conv]);
        $this->erpSays(200, [['id' => 41, 'name' => 'Ana Lima', 'email' => 'ana@connect.com.br'], ['id' => 42, 'name' => 'Bruno', 'email' => 'bruno@connect.com.br']]);
        $r = $this->login()->syncTeam(1, '10.0.0.5');
        $this->assertSame(['added' => 1, 'updated' => 1, 'deactivated' => 1, 'total' => 2], $r);
        $this->assertSame('https://erp.connect.lab/api/v1/talk/staff', $this->erpHttp->calls[0]['url']);
        $this->assertSame(41, (int) $this->db->one('SELECT erp_user_id FROM talk_users WHERE id = ?', [$ana])['erp_user_id']);
        $this->assertSame('Ana Lima', $this->db->one('SELECT name FROM talk_users WHERE id = ?', [$ana])['name']);
        $this->assertNotNull($this->db->one("SELECT id FROM talk_users WHERE erp_user_id = 42 AND active = 1"));
        $this->assertSame(0, (int) $this->db->one('SELECT active FROM talk_users WHERE id = ?', [$old])['active'], 'Perdeu o acesso no ERP.');
        $this->assertSame('pending', $this->conv($conv)['status'], 'O atendimento dela volta para a fila.');

        // supervisor desativou a Ana no Talk: a próxima atualização não reativa
        $this->db->run('UPDATE talk_users SET active = 0 WHERE id = ?', [$ana]);
        $this->erpSays(200, [['id' => 41, 'name' => 'Ana Lima', 'email' => 'ana@connect.com.br'], ['id' => 42, 'name' => 'Bruno', 'email' => 'bruno@connect.com.br']]);
        $this->assertSame(['added' => 0, 'updated' => 0, 'deactivated' => 0, 'total' => 2], $this->login()->syncTeam());
        $this->assertSame(0, (int) $this->db->one('SELECT active FROM talk_users WHERE id = ?', [$ana])['active']);
    }

    public function test_equipe_no_modo_erp_so_muda_papel_e_setores(): void
    {
        $sup = $this->db->insert('talk_users', ['name' => 'Djalma', 'email' => 'sup@x.com', 'password_hash' => 'x', 'erp_user_id' => 7]);
        $this->db->insert('talk_staff', ['user_id' => $sup, 'sector_id' => null, 'role' => 'supervisor']);
        $ana = $this->db->insert('talk_users', ['name' => 'Ana Lima', 'email' => 'ana@connect.com.br', 'password_hash' => 'x', 'erp_user_id' => 41]);
        $team = new TeamService($this->db, new EventBus($this->db), '10.0.0.1', true);
        $team->saveUser(new StaffMember($sup, true), ['name' => 'Outro Nome', 'email' => 'outro@x.com', 'erp_user_id' => 99, 'role' => 'manager',
            'sector_ids' => [$this->sectors['Manutenção']]], $ana);
        $u = $this->db->one('SELECT * FROM talk_users WHERE id = ?', [$ana]);
        $this->assertSame(['Ana Lima', 'ana@connect.com.br', 41], [$u['name'], $u['email'], (int) $u['erp_user_id']]);
        $this->assertNotNull($this->db->one("SELECT id FROM talk_staff WHERE user_id = ? AND role = 'manager'", [$ana]));
        $this->expectException(DomainError::class);
        $team->saveUser(new StaffMember($sup, true), ['name' => 'Nova', 'email' => 'nova@x.com', 'role' => 'agent', 'sector_ids' => [$this->sectors['Financeiro']]]);
    }
}
