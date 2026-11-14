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

    /** Jaqueline, quando perguntam se é robô (texto aprovado pelo Djalma em 14/11/2026). */
    public function jackIdentity(): string
    {
        return "*Jaqueline:*\nEu sou a inteligência artificial do time da Connect. Meu nome é Jaqueline, "
            . "mas por aqui o time de TI me chama de Jack. Estou aqui só para agilizar o seu atendimento.";
    }

    /** Jaqueline, quando a conversa sai do que ela sabe fazer. */
    public function jackOutOfScope(string $sector): string
    {
        return "*Jaqueline:*\nIsso está fora do meu escopo de treinamento, então vou te transferir para o setor *{$sector}*. "
            . 'Se tiver mais alguma dúvida, eles podem esclarecer.';
    }

    private function menu(array $sectors): string
    {
        return implode("\n", array_map(fn ($s) => "*{$s['menu_key']}* - {$s['name']}", $sectors));
    }
}
