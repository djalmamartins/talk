<?php
declare(strict_types=1);

namespace ConnectTalk\Tests\Unit;

use ConnectTalk\Service\TicketBridge;
use PHPUnit\Framework\TestCase;

final class TicketBridgeTest extends TestCase
{
    public function test_numero_do_chamado_prefere_o_do_help_desk(): void
    {
        $this->assertSame('HD-2026-0042', TicketBridge::number(['helpdesk_protocol' => 'HD-2026-0042', 'protocol' => 'SAC26112100001']));
        $this->assertSame('SAC26112100001', TicketBridge::number(['helpdesk_protocol' => null, 'protocol' => 'SAC26112100001']));
        $this->assertSame('SAC26112100001', TicketBridge::number(['protocol' => 'SAC26112100001']));
    }
}
