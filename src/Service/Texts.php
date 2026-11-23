<?php
declare(strict_types=1);

namespace ConnectTalk\Service;

/** Mensagens automáticas enviadas ao cliente. Ajuste o tom aqui. */
class Texts
{
    /** Jaqueline: a pessoa só cumprimentou e parou de escrever. */
    public function jackAsk(?string $firstName): string
    {
        return '*Jack:*' . "\n" . ($firstName ? "Oi, {$firstName}!" : 'Oi!') . ' Como posso te ajudar?';
    }

    /** Jaqueline: entendeu o assunto, abriu o chamado e encaminhou. */
    public function jackRouted(?string $firstName, string $protocol, string $sector): string
    {
        return '*Jack:*' . "\n" . ($firstName ? "Certo, {$firstName}!" : 'Certo!')
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

    /** Avaliação: vai logo depois do "atendimento encerrado". */
    public function ratingAsk(?string $attendant): string
    {
        return 'Como você avalia ' . ($attendant ? "o atendimento de *{$attendant}*" : 'este atendimento') . '? '
            . "Responda com um número de *1* (ruim) a *5* (ótimo). Se quiser, escreva também um comentário.";
    }

    public function ratingThanks(int $score): string
    {
        return $score >= 4 ? 'Obrigado pela avaliação! Ficamos felizes em ajudar.'
            : 'Obrigado pela avaliação. Vamos usar sua resposta para melhorar o atendimento.';
    }

    /** Jaqueline, quando perguntam se é robô (texto aprovado pelo Djalma em 14/11/2026). */
    public function jackIdentity(): string
    {
        return "*Jack:*\nEu sou a inteligência artificial do time da Connect. Meu nome é Jaqueline, "
            . "mas por aqui o time de TI me chama de Jack. Estou aqui só para agilizar o seu atendimento.";
    }

    /** Jaqueline, quando a conversa sai do que ela sabe fazer. */
    public function jackOutOfScope(string $sector): string
    {
        return "*Jack:*\nIsso está fora do meu escopo de treinamento, então vou te transferir para o setor *{$sector}*. "
            . 'Se tiver mais alguma dúvida, eles podem esclarecer.';
    }

    /** "Bom dia", "Boa tarde" ou "Boa noite" pela hora (0–23). */
    public static function greeting(int $hour): string
    {
        return $hour >= 5 && $hour < 12 ? 'Bom dia' : ($hour >= 12 && $hour < 18 ? 'Boa tarde' : 'Boa noite');
    }

    /**
     * Jaqueline, quando abre o chamado (pedido do Djalma em 22/11/2026: "não ser um robozinho").
     * São variações aprovadas, escolhidas pela conversa (ADR 0004: só textos revisados, nunca texto livre).
     */
    public function jackTicketOpened(?string $firstName, string $number, string $sector, ?string $url, ?string $attendant, int $variant, int $hour): string
    {
        $hi = self::greeting($hour) . ($firstName ? ", {$firstName}" : '') . '!';
        $open = [
            "{$hi} Tudo bem? Já abri um chamado para a equipe de *{$sector}* cuidar do seu pedido. O número é *{$number}*.",
            "{$hi} Recebi sua mensagem e registrei tudo no chamado *{$number}*, com a equipe de *{$sector}*, que é quem resolve isso.",
            "{$hi} Seu pedido já está com a equipe de *{$sector}*. Anotei tudo no chamado *{$number}* para ninguém precisar te perguntar de novo.",
        ][abs($variant) % 3];
        $next = $attendant
            ? "*{$attendant}* já está com a sua conversa e continua com você por aqui."
            : 'Em instantes alguém da equipe continua com você por aqui.';
        $track = $url ? "\n\n" . $this->trackLink($url) : '';
        return "*Jack:*\n{$open} {$next}{$track}\n\nSe quiser saber como está, é só me mandar o número do chamado aqui no WhatsApp.";
    }

    public function trackLink(string $url): string
    {
        return "Você pode acompanhar o andamento por este link: {$url}";
    }

    /** Jaqueline, quando o cliente manda o número do chamado. */
    public function jackTicketStatus(?string $firstName, string $number, string $label, ?string $sector, ?string $lastReplyAt, ?string $url): string
    {
        $text = '*Jack:*' . "\n" . ($firstName ? "Oi, {$firstName}! " : 'Oi! ') . "Olhei aqui: o chamado *{$number}* está *{$label}*"
            . ($sector && !in_array($label, ['resolvido', 'fechado', 'encerrado'], true) ? " com a equipe de *{$sector}*" : '') . '.';
        if ($lastReplyAt) {
            $t = strtotime($lastReplyAt);
            $text .= ' A última resposta da equipe foi ' . (date('Y-m-d', $t) === date('Y-m-d') ? 'hoje' : 'em ' . date('d/m', $t)) . ' às ' . date('H:i', $t) . '.';
        }
        return $text . ($url ? "\n\n" . $this->trackLink($url) : '');
    }

    public function jackTicketNotFound(?string $firstName, string $number): string
    {
        return '*Jack:*' . "\n" . ($firstName ? "Oi, {$firstName}! " : 'Oi! ')
            . "Não encontrei o chamado *{$number}* ligado a este número de WhatsApp. Confere o número para mim? "
            . 'Se preferir, me conte o que você precisa que eu passo para a equipe.';
    }
}
