# App do Connect Talk para iPhone e Android

App dos atendentes da Connect, em React Native (Expo), na pasta [`mobile/`](../mobile). Decisão na
[ADR 0007](decisions/0007-app-movel-react-native.md). Layout aprovado pelo Djalma em 27/11/2026 (padrão da lista de
mensagens do iPhone, com as cores e o símbolo da Connect).

## O que o app faz (1.0)

| Aba / tela | O que mostra | Rota do Talk |
|---|---|---|
| Entrar | Endereço do Talk (uma vez) e "Entrar pelo Connect ERP"; no laboratório, e-mail e senha | `GET /api/app/config`, `/app/entrar`, `POST /api/app/token` ou `POST /api/app/login` |
| Fila | Quem espera, filtro por setor, tempo de espera (fica laranja depois de `QUEUE_WARN_MINUTES`), botão Atender | `GET /api/queue`, `POST /api/conversations/{id}/accept` |
| Meus | Minhas conversas: Todos, Não lidas, Aguardando, Encerrados | `GET /api/conversations?tab=mine` (e `closed`) |
| Conversa | Mensagens, foto inline, nota interna, resposta com `/` para respostas rápidas, anexo (foto ou documento), Atender, Transferir (setor, pessoa, motivo), Aguardando cliente, Encerrar, Reabrir | `GET /api/conversations/{id}` e as ações `reply`, `notes`, `attach`, `transfer`, `status`, `close`, `read` |
| Avisos | O mesmo sino da web (fila, transferências, não lidas, agendamentos, avaliações) | `GET /api/notifications` |
| Você | Nome, papel, setores, endereço do Talk, versão e Sair | `GET /api/me`, `POST /api/app/logout` |

Com o app aberto, as listas se atualizam sozinhas pelo tempo real (`/events`, o mesmo SSE da web). Com o app fechado,
quem avisa é a notificação:

- **Novo na fila · Setor**: conversa nova que a pessoa pode pegar (setor dela, fila Geral; gerente e supervisor também);
- **Nova mensagem**: o cliente escreveu numa conversa que é da pessoa;
- **Transferido para você** ou **Transferido para a fila · Setor**.

Tocar na notificação abre a conversa. Quem fez a ação não é avisado.

Ainda não está no app (fica na web): contatos, agendamentos, envio em massa, painel, equipe, conexões do WhatsApp,
configurações, abrir chamado no SAC e 2ª via. Áudio e documento aparecem como cartão com o nome (abrir o arquivo é na web).

## Segurança e LGPD

- O app não guarda senha. No celular fica só o token do aparelho (`tka_…`), no cofre do sistema (Keychain no iPhone,
  Keystore no Android, via `expo-secure-store`). No servidor fica só o hash.
- O token vence depois de `APP_TOKEN_DAYS` (30) dias sem uso e cai ao Sair, ao desativar a pessoa no Talk, ao trocar a
  senha (modo senha) ou quando o ERP diz que ela perdeu a permissão do Talk.
- O login pelo ERP passa pelo navegador do sistema, com o 2FA do ERP. O código de volta vale 2 minutos, uma vez só, e
  precisa vir com o mesmo `state` que o app gerou. Códigos errados contam no limite de tentativas (10 por IP em 15 min).
- Notificação: `PUSH_PREVIEW=nome` (padrão) mostra só o nome do cliente. `texto` mostra o começo da mensagem e manda esse
  texto para o Expo, a Apple e o Google; só ligue com o de acordo do responsável pela LGPD. `nada` esconde até o nome.

## Servidor do Talk (o que muda)

- Migração `030_app_movel.sql`: tabelas `talk_app_tokens` e `talk_app_login_codes`. Roda sozinha com `bin/migrate.php`.
- O worker (`bin/talk-worker`) manda as notificações a cada 3 s (`PUSH=on`). No laboratório, cada pedido também manda.
- `.env`: `APP_TOKEN_DAYS`, `PUSH`, `PUSH_PREVIEW`, `EXPO_ACCESS_TOKEN` (opcional) e `APP_SCHEME` (padrão `connecttalk`).
- `public/.htaccess` passa o cabeçalho `Authorization` ao PHP.
- O celular precisa alcançar o Talk por HTTPS fora do escritório (Cloudflare Tunnel, [raspberry-pi.md](raspberry-pi.md)),
  e o endereço digitado no app tem de ser o mesmo `TALK_URL` do `.env` do ERP (é para lá que o ERP devolve o login).
- O worker precisa sair para `https://exp.host` (serviço de push do Expo).

## Rodar no laboratório

Precisa de Node 22 e de um celular (ou simulador). O login pelo ERP volta ao app pelo endereço `connecttalk://`, então
use um **build de desenvolvimento** (o Expo Go não abre esse endereço).

```bash
cd mobile
npm install
npx eas-cli@latest login            # conta do Expo (gratuita)
npx eas-cli@latest init             # grava o projectId no app.json (precisa para as notificações)
npx eas-cli@latest build --profile development --platform android   # ou ios (precisa da conta Apple)
npx expo start --dev-client         # depois de instalar o build no celular
```

No app, em "Endereço do Talk", use o endereço que o celular alcança (no Mac, o IP da máquina na rede, por exemplo
`http://192.168.0.10:8080`, ou o túnel). Com `TALK_LOGIN=senha` o app pede e-mail e senha do Talk.

Testes rápidos sem celular: `npm test` (funções de data e texto) e `npx expo export --platform android` (o app compila).

## Publicar nas lojas

1. Contas: Apple Developer (US$ 99/ano) e Google Play Console (US$ 25 uma vez), no nome da Connect.
2. Ícone definitivo em `mobile/assets/` (hoje é o provisório: símbolo da Connect em branco sobre o verde `#12804A`;
   as opções estão no layout aprovado). Apple e Google recusam ícone parecido com o de outra marca.
3. `npx eas-cli@latest build --profile production --platform all` e `npx eas-cli@latest submit`.
4. Para a Apple: conta de teste para a revisão (um usuário do ERP só com acesso ao Talk e dados fictícios), política de
   privacidade publicada e a explicação de que o app é de uso interno da Connect. Se a Apple pedir, dá para distribuir só
   para a equipe (Apple Business Manager / app personalizado) em vez da App Store aberta.
5. Testes antes: build `preview` (APK direto no Android) e TestFlight no iPhone.

## Arquivos

```
mobile/
  app.json, eas.json         nome, ícone, connecttalk://, identificador br.com.connect.talk, builds
  src/app/                   telas (Expo Router): login, (tabs)/index (Fila), meus, avisos, voce, conversa/[id]
  src/lib/api.js             chamadas ao Talk com o token
  src/lib/auth.js            entrar (ERP ou senha) e sair
  src/lib/push.js            permissão e registro da notificação
  src/lib/realtime.js        /events (SSE) com o app aberto
  src/components/            peças das listas e o símbolo da Connect
src/Service/AppTokens.php    token por aparelho e código de login
src/Service/PushNotifier.php notificações pelo Expo
```
