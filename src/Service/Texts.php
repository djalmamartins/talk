<?php
declare(strict_types=1);

namespace ConnectTalk\Service;

/** Mensagens automáticas enviadas ao cliente. Ajuste o tom aqui. */
class Texts
{
    public function welcome(?string $firstName, string $protocol, array $sectors): string
    {
        $hi = $firstName ? "Olá, {$firstName}!" : 'Olá!';
        return "{$hi} Aqui é a Connect. Seu protocolo é *{$protocol}*.\n\n"
            . "Sobre o que você quer falar?\n" . $this->menu($sectors);
    }

    public function invalidOption(array $sectors): string
    {
        return "Não entendi. Responda com o número da opção:\n" . $this->menu($sectors);
    }

    public function routed(string $sector, string $protocol): string
    {
        return "Certo! Você está na fila do *{$sector}* (protocolo {$protocol}). Já vamos te atender.";
    }

    public function transferred(string $sector): string
    {
        return "Seu atendimento foi encaminhado para o *{$sector}*. Em instantes alguém continua com você.";
    }

    public function closed(string $protocol): string
    {
        return "Atendimento encerrado. Protocolo *{$protocol}*. Se precisar, é só mandar uma nova mensagem.";
    }

    private function menu(array $sectors): string
    {
        return implode("\n", array_map(fn ($s) => "*{$s['menu_key']}* - {$s['name']}", $sectors));
    }
}
