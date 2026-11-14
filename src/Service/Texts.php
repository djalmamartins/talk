<?php
declare(strict_types=1);

namespace ConnectTalk\Service;

/** Mensagens automáticas enviadas ao cliente. Ajuste o tom aqui. */
class Texts
{
    /** Jaqueline: a pessoa só cumprimentou e parou de escrever. */
    public function jackAsk(?string $firstName): string
    {
        return '*Jaqueline:*' . "\n" . ($firstName ? "Oi, {$firstName}!" : 'Oi!') . ' Como posso te ajudar?';
    }

    /** Jaqueline: entendeu o assunto, abriu o chamado e encaminhou. */
    public function jackRouted(?string $firstName, string $protocol, string $sector): string
    {
        return '*Jaqueline:*' . "\n" . ($firstName ? "Certo, {$firstName}!" : 'Certo!')
            . " Abri o chamado *{$protocol}* e já encaminhei para a equipe de *{$sector}*. Já vamos te atender.";
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
}
