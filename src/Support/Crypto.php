<?php
declare(strict_types=1);

namespace ConnectTalk\Support;

/** Cifra a configuração dos canais (tokens) com libsodium. Chave: TALK_KEY (base64, 32 bytes). */
final class Crypto
{
    public function __construct(private string $key)
    {
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \InvalidArgumentException('TALK_KEY precisa ter 32 bytes (base64 de 32 bytes)');
        }
    }

    public static function fromBase64(string $b64): self
    {
        return new self((string) base64_decode($b64, true));
    }

    public function encrypt(array $data): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = sodium_crypto_secretbox(json_encode($data, JSON_THROW_ON_ERROR), $nonce, $this->key);
        return base64_encode($nonce . $box);
    }

    public function decrypt(string $payload): array
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Configuração do canal inválida');
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key);
        if ($plain === false) {
            throw new \RuntimeException('Não foi possível decifrar a configuração do canal');
        }
        return json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
    }
}
