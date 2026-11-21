<?php
declare(strict_types=1);

namespace ConnectTalk\Tests\Unit;

use ConnectTalk\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function test_acha_rota_pelo_metodo_e_extrai_parametros(): void
    {
        $r = new Router();
        $got = null;
        $r->get('/api/conversations/{id}', function (array $p) use (&$got) { $got = ['detalhe', $p]; });
        $r->post('/api/conversations/{id}/contact-notes/{note}/delete', function (array $p) use (&$got) { $got = ['apaga', $p]; });

        $this->assertTrue($r->dispatch('GET', '/api/conversations/12'));
        $this->assertSame(['detalhe', ['id' => 12]], $got);
        $this->assertTrue($r->dispatch('POST', '/api/conversations/3/contact-notes/9/delete'));
        $this->assertSame(['apaga', ['id' => 3, 'note' => 9]], $got);
    }

    public function test_nao_acha_metodo_errado_nem_parametro_que_nao_e_numero(): void
    {
        $r = new Router();
        $r->post('/api/conversations/{id}/accept', fn () => null);
        $this->assertFalse($r->dispatch('GET', '/api/conversations/1/accept'), 'GET não executa ação (sem CSRF)');
        $this->assertFalse($r->dispatch('POST', '/api/conversations/abc/accept'));
        $this->assertFalse($r->dispatch('POST', '/api/conversations/1/accept/x'));
    }
}
