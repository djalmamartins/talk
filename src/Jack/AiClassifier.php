<?php
declare(strict_types=1);

namespace ConnectTalk\Jack;

use ConnectTalk\Http\HttpClient;

/**
 * IA local da Jack (ADR 0004, etapa A; plano em docs/jack/IA.md): só entende o assunto, dentro da lista
 * fechada de intenções. Nunca escreve texto para o cliente.
 *
 * Fala com um servidor de modelo no formato da API da OpenAI (`/v1/chat/completions`), que roda na
 * própria máquina: llama-server (llama.cpp, serviço jack-ia do compose do Pi) ou Ollama (lab do Mac).
 * Pede a resposta em JSON fechado (json_schema) e confere de novo aqui. Qualquer falha, demora ou
 * resposta fora da lista devolve null, e a Jack segue com as regras.
 *
 * Só o texto da mensagem vai para o modelo, com números longos e e-mails mascarados.
 */
final class AiClassifier
{
    /** Intenções que a IA pode responder (as do Classifier) + "nenhuma". */
    public const INTENTS = ['boleto', 'financeiro', 'cotacao', 'administrativo', 'manutencao', 'nenhuma'];

    private const SYSTEM = <<<'TXT'
Você faz a triagem das mensagens de WhatsApp de moradores e síndicos para a Connect, uma administradora de condomínios.
Leia a mensagem e diga qual é o assunto, escolhendo UMA intenção:
- boleto: 2ª via, boleto, linha digitável, código de barras, Pix do condomínio.
- financeiro: pagamento, cobrança, taxa, multa, juros, acordo, parcelamento, débito, quitação, reembolso, valor cobrado.
- cotacao: quer contratar a Connect, orçamento ou preço da administração do condomínio.
- administrativo: assembleia, ata, reserva de salão ou churrasqueira, mudança, cadastro, documentos, regimento, convenção, síndico, advertência, reclamação de vizinho ou barulho.
- manutencao: algo quebrado ou com defeito no condomínio: água, luz, vazamento, infiltração, elevador, portão, interfone, lâmpada, esgoto, bomba, cerca elétrica, limpeza, pragas.
- nenhuma: só cumprimento, agradecimento ou mensagem sem assunto.
certeza = "alta" só quando o assunto está claro na mensagem; na dúvida, "baixa".
Responda só o JSON pedido.
TXT;

    public function __construct(
        private HttpClient $http,
        private string $baseUrl,
        private string $model,
        private int $maxTokens = 40,
    ) {
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * @return array{intent:?string, sector:?string, confident:bool, ms:int}|null  null = IA indisponível ou resposta inválida
     */
    public function classify(string $text): ?array
    {
        $start = microtime(true);
        $user = self::mask($text);
        if (str_contains(strtolower($this->model), 'qwen3')) {
            $user .= "\n/no_think"; // Qwen3: responde direto, sem "pensar" antes (mais rápido)
        }
        try {
            $res = $this->http->postJson(rtrim($this->baseUrl, '/') . '/v1/chat/completions', [
                'model' => $this->model,
                'temperature' => 0,
                'max_tokens' => $this->maxTokens,
                'stream' => false,
                'messages' => [
                    ['role' => 'system', 'content' => self::SYSTEM], // fixo: o servidor reaproveita o cache
                    ['role' => 'user', 'content' => $user],
                ],
                'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'triagem', 'strict' => true, 'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'intencao' => ['type' => 'string', 'enum' => self::INTENTS],
                        'certeza' => ['type' => 'string', 'enum' => ['alta', 'baixa']],
                    ],
                    'required' => ['intencao', 'certeza'],
                    'additionalProperties' => false,
                ]]],
            ]);
        } catch (\Throwable $e) {
            error_log('[talk] IA da Jack: ' . $e->getMessage());
            return null;
        }
        $ms = (int) round((microtime(true) - $start) * 1000);
        if ($res['status'] < 200 || $res['status'] >= 300) {
            error_log('[talk] IA da Jack respondeu HTTP ' . $res['status']);
            return null;
        }
        $out = self::parse((string) (json_decode($res['body'], true)['choices'][0]['message']['content'] ?? ''));
        if ($out === null) {
            error_log('[talk] IA da Jack: resposta fora do formato');
            return null;
        }
        $intent = $out['intencao'] === 'nenhuma' ? null : $out['intencao'];
        return [
            'intent' => $intent,
            'sector' => $intent !== null ? Classifier::SECTOR[$intent] : null,
            'confident' => $intent !== null && $out['certeza'] === 'alta',
            'ms' => $ms,
        ];
    }

    /** Aceita só {"intencao": <da lista>, "certeza": alta|baixa}, mesmo que o modelo tenha escrito algo em volta. */
    public static function parse(string $content): ?array
    {
        $content = (string) preg_replace('/<think>.*?<\/think>/s', '', $content);
        if (!preg_match('/\{[^{}]*\}/s', $content, $m)) {
            return null;
        }
        $j = json_decode($m[0], true);
        if (!is_array($j) || !in_array($j['intencao'] ?? null, self::INTENTS, true) || !in_array($j['certeza'] ?? null, ['alta', 'baixa'], true)) {
            return null;
        }
        return ['intencao' => $j['intencao'], 'certeza' => $j['certeza']];
    }

    /** Tira dados pessoais óbvios antes de mandar ao modelo: e-mails e números com 6 dígitos ou mais (CPF, telefone, unidade longa). */
    public static function mask(string $text): string
    {
        $t = (string) preg_replace('/[\w.+-]+@[\w-]+(\.[\w-]+)+/u', '[email]', $text);
        $t = (string) preg_replace('/\d[\d .\-\/]{4,}\d/', '[número]', $t);
        return mb_substr($t, 0, 1000);
    }
}
