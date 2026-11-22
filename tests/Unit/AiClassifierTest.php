<?php
declare(strict_types=1);

namespace ConnectTalk\Tests\Unit;

use ConnectTalk\Jack\AiClassifier;
use ConnectTalk\Tests\Fixtures\FakeHttpClient;
use PHPUnit\Framework\TestCase;

/** IA local da Jack (docs/jack/IA.md): só entende o assunto na lista fechada; qualquer coisa fora vira null. */
final class AiClassifierTest extends TestCase
{
    private function reply(string $content, int $status = 200): array
    {
        return ['status' => $status, 'body' => json_encode(['choices' => [['message' => ['content' => $content]]]])];
    }

    public function test_pede_json_fechado_e_traduz_para_setor(): void
    {
        $http = new FakeHttpClient();
        $http->responses[] = $this->reply('{"intencao":"manutencao","certeza":"alta"}');
        $r = (new AiClassifier($http, 'http://jack-ia:8080/', 'gemma3:1b'))->classify('o portão quebrou, meu cpf é 123.456.789-00');

        $this->assertSame(['intent' => 'manutencao', 'sector' => 'manutencao', 'confident' => true], array_diff_key($r, ['ms' => 0]));
        $call = $http->calls[0];
        $this->assertSame('http://jack-ia:8080/v1/chat/completions', $call['url']);
        $this->assertSame('gemma3:1b', $call['body']['model']);
        $this->assertSame(0, $call['body']['temperature']);
        $this->assertSame(AiClassifier::INTENTS, $call['body']['response_format']['json_schema']['schema']['properties']['intencao']['enum']);
        $sent = $call['body']['messages'][1]['content'];
        $this->assertStringNotContainsString('123.456.789-00', $sent); // CPF mascarado
        $this->assertStringContainsString('[número]', $sent);
    }

    public function test_boleto_vira_financeiro_e_certeza_baixa_nao_decide(): void
    {
        $http = new FakeHttpClient();
        $http->responses[] = $this->reply("Claro!\n{\"intencao\": \"boleto\", \"certeza\": \"baixa\"}");
        $http->responses[] = $this->reply('{"intencao":"nenhuma","certeza":"alta"}');
        $ai = new AiClassifier($http, 'http://x', 'gemma3:1b');

        $r = $ai->classify('boleto?');
        $this->assertSame('financeiro', $r['sector']);
        $this->assertFalse($r['confident']);
        $r = $ai->classify('bom dia');
        $this->assertNull($r['intent']);
        $this->assertFalse($r['confident']);
    }

    public function test_resposta_fora_da_lista_erro_ou_queda_devolvem_null(): void
    {
        $http = new FakeHttpClient();
        $http->responses[] = $this->reply('{"intencao":"juridico","certeza":"alta"}');
        $http->responses[] = $this->reply('não sei', 200);
        $http->responses[] = $this->reply('', 500);
        $http->responses[] = new \RuntimeException('timeout');
        $ai = new AiClassifier($http, 'http://x', 'gemma3:1b');
        for ($i = 0; $i < 4; $i++) {
            $this->assertNull(@$ai->classify('vazamento'));
        }
    }

    public function test_qwen3_responde_sem_pensar_e_mascara_email(): void
    {
        $http = new FakeHttpClient();
        $http->responses[] = $this->reply('<think>…</think>{"intencao":"administrativo","certeza":"alta"}');
        $r = (new AiClassifier($http, 'http://x', 'qwen3:1.7b'))->classify('meu email é ana@exemplo.com, quero a ata');
        $this->assertSame('administrativo', $r['intent']);
        $sent = $http->calls[0]['body']['messages'][1]['content'];
        $this->assertStringEndsWith('/no_think', $sent);
        $this->assertStringContainsString('[email]', $sent);
    }

    public function test_base_de_conhecimento_nao_tem_frases_de_conferencia(): void
    {
        $system = \ConnectTalk\Jack\Classifier::normalize(AiClassifier::instructions());
        foreach (\ConnectTalk\Jack\Scenarios::load(__DIR__ . '/../Fixtures/jack/triagem-conferencia.csv') as $r) {
            if (str_word_count($r['frase']) < 3) {
                continue; // "alo", "oi": palavras soltas, não frases
            }
            $this->assertStringNotContainsString(\ConnectTalk\Jack\Classifier::normalize($r['frase']), $system, 'frase de conferência na base: o placar perde o valor');
        }
        foreach (AiClassifier::INTENTS as $i) {
            $this->assertStringContainsString("- $i:", AiClassifier::instructions());
        }
    }
}
