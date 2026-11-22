<?php
declare(strict_types=1);

namespace ConnectTalk\Jack;

use ConnectTalk\Support\Db;

/**
 * Cenários fixos de triagem (JV1.2 / IA1, plano em docs/jack/IA.md): frases de cliente com o setor certo.
 * São a régua para comparar as regras (Classifier) com a IA, antes de a IA decidir qualquer coisa.
 * Setor null = a Jack não deve ter certeza (vai para o setor padrão ou espera o assunto).
 *
 * Frases fictícias, escritas como o cliente escreve (sem acento, abreviado, com erro). Ao achar um erro
 * real da Jack, acrescente aqui; as sugestões com "setor certo" da tela Serviço da Jack também entram
 * no relatório (bin/jack-cenarios.php).
 */
final class Scenarios
{
    /** @var list<array{0:string, 1:?string}> frase => setor certo (financeiro | administrativo | manutencao | null) */
    public const CASES = [
        // Financeiro
        ['bom dia, preciso da segunda via do boleto deste mês', 'financeiro'],
        ['me manda o boleto do condominio por favor', 'financeiro'],
        ['qual a linha digitável do boleto de dezembro?', 'financeiro'],
        ['tem pix pra pagar o condomínio?', 'financeiro'],
        ['paguei ontem e ainda aparece em aberto', 'financeiro'],
        ['quero fazer acordo das taxas atrasadas', 'financeiro'],
        ['dá pra parcelar o débito?', 'financeiro'],
        ['por que veio multa e juros se paguei no dia?', 'financeiro'],
        ['preciso da declaração de quitação de débitos', 'financeiro'],
        ['o valor da taxa aumentou, quero entender a cobrança', 'financeiro'],
        ['2a via pf', 'financeiro'],
        ['não recebi o boleto esse mes', 'financeiro'],
        // Administrativo
        ['quando vai ser a assembleia?', 'administrativo'],
        ['queria a ata da última assembleia', 'administrativo'],
        ['quero reservar o salão de festas para sábado', 'administrativo'],
        ['como faço a reserva da churrasqueira?', 'administrativo'],
        ['vou fazer mudança semana que vem, precisa avisar?', 'administrativo'],
        ['o vizinho de cima faz barulho toda noite', 'administrativo'],
        ['preciso atualizar meu cadastro de morador', 'administrativo'],
        ['onde acho o regimento interno?', 'administrativo'],
        ['quero falar com o síndico', 'administrativo'],
        ['gostaria de um orçamento para administrar o nosso condomínio', 'administrativo'],
        ['quanto custa a administração de vocês?', 'administrativo'],
        ['recebi uma advertência e não concordo', 'administrativo'],
        // Manutenção
        ['estamos sem água desde cedo', 'manutencao'],
        ['acabou a luz do bloco B', 'manutencao'],
        ['o elevador parou no 5 andar', 'manutencao'],
        ['portão da garagem não fecha', 'manutencao'],
        ['tem um vazamento no teto do meu banheiro', 'manutencao'],
        ['infiltração na parede da sala', 'manutencao'],
        ['a lâmpada do corredor queimou', 'manutencao'],
        ['o interfone não funciona', 'manutencao'],
        ['esgoto entupido na garagem, cheiro forte', 'manutencao'],
        ['tem água no chão do hall', 'manutencao'],
        ['a cerca elétrica está desligada', 'manutencao'],
        ['a bomba da caixa dágua parou', 'manutencao'],
        ['barata no corredor, precisa dedetizar', 'manutencao'],
        ['rachadura grande na escada', 'manutencao'],
        // Sem assunto claro: a Jack não deve ter certeza
        ['bom dia', null],
        ['oi tudo bem?', null],
        ['preciso falar com vocês', null],
        ['tô com um problema aqui', null],
        ['alguém pode me ajudar?', null],
        ['obrigado', null],
    ];

    /**
     * Roda um classificador nos cenários. $classify recebe a frase e devolve o setor com certeza, ou null.
     * @param callable(string):?string $classify
     * @param list<array{0:string, 1:?string}>|null $cases
     * @return array{total:int, acertos:int, taxa:float, erros:list<array{frase:string, esperado:?string, obtido:?string}>}
     */
    public static function score(callable $classify, ?array $cases = null): array
    {
        $cases ??= self::CASES;
        $hits = 0;
        $misses = [];
        foreach ($cases as [$text, $expected]) {
            $got = $classify($text);
            if ($got === $expected) {
                $hits++;
            } else {
                $misses[] = ['frase' => $text, 'esperado' => $expected, 'obtido' => $got];
            }
        }
        $total = count($cases);
        return ['total' => $total, 'acertos' => $hits, 'taxa' => $total ? round($hits / $total, 3) : 0.0, 'erros' => $misses];
    }

    /** As regras de hoje como classificador: setor só quando confiante. */
    public static function rules(?Classifier $c = null): callable
    {
        $c ??= new Classifier();
        return static function (string $text) use ($c): ?string {
            $r = $c->classify($text);
            return $r['confident'] ? $r['sector'] : null;
        };
    }

    /**
     * Sugestões da tela Serviço da Jack com "setor certo" viram cenários: a primeira mensagem do cliente
     * na conversa e o setor que deveria ter sido (pela chave da Jack ou pelo nome do setor).
     * @return list<array{0:string, 1:?string}>
     */
    public static function fromFeedback(Db $db): array
    {
        $out = [];
        foreach ($db->all(
            "SELECT f.conversation_id, s.jack_key, s.name FROM talk_jack_feedback f JOIN talk_sectors s ON s.id = f.expected_sector_id
              WHERE f.conversation_id IS NOT NULL AND f.status <> 'descartada' ORDER BY f.id"
        ) as $f) {
            $text = implode("\n", array_column($db->all(
                "SELECT body FROM talk_messages WHERE conversation_id = ? AND direction = 'in' AND type = 'text' AND body IS NOT NULL ORDER BY id LIMIT 5",
                [$f['conversation_id']]
            ), 'body'));
            if ($text !== '') {
                $out[] = [$text, $f['jack_key'] ?: str_replace(' ', '', Classifier::normalize((string) $f['name']))];
            }
        }
        return $out;
    }
}
