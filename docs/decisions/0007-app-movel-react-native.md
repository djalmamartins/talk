# 0007 — App do Talk para iPhone e Android em React Native

- **Status:** aceito
- **Data:** 2026-11-27
- **Relacionados:** 0003 (API REST), 0005 (Raspberry Pi), 0006 (login pelo ERP).

## Contexto
Pedido do Djalma em 27/11/2026: "Crie um aplicativo para o Talk para iOS e Android". Ele quis ver o layout antes do
código e escolheu o padrão de lista do app de mensagens do iPhone (título grande, filtros em pílula, lista com foto,
prévia e hora, barra de abas flutuante). Entre as opções apresentadas (Capacitor com JS puro, só PWA, Flutter) ele
escolheu **React Native**. O ícone fica para depois; até lá vale o provisório (símbolo da Connect em branco sobre o verde).

## Decisão
1. **Expo (React Native) em `mobile/`**, no mesmo repositório do Talk, em JavaScript (sem TypeScript), com Expo Router.
   Build e publicação nas lojas pelo EAS (nuvem do Expo): ninguém precisa de Mac com Xcode para gerar o app.
2. **O app fala com o mesmo PHP da web**, nas mesmas rotas `/api/*`, `/events` (SSE) e `/media/{id}`. Nada de regra de
   negócio no celular: quem decide o que cada um vê e pode fazer continua sendo o `AccessPolicy` do servidor.
3. **Token por aparelho no lugar do cookie** (`talk_app_tokens`): `Authorization: Bearer tka_…`, só o hash SHA-256 no
   banco, vence após 30 dias sem uso (`APP_TOKEN_DAYS`), revogado ao Sair. Sem cookie não há CSRF. A cada pedido valem as
   mesmas regras da sessão da web: pessoa ativa no Talk, senha sem troca depois do login e, de 10 em 10 minutos, acesso
   confirmado no ERP; perdeu o acesso no ERP, o token é revogado.
4. **Login pelo Connect ERP também no celular** (ADR 0006): o app abre o navegador do sistema em `/app/entrar?state=…`, que
   leva ao ERP; o ERP devolve a `/ambiente/entrar/talk/{código}` como na web e, como o pedido veio do app, o Talk devolve
   `connecttalk://entrar?code=…&state=…` com um código de uso único (2 minutos), trocado em `POST /api/app/token`. A senha e
   o 2FA ficam no ERP; o app nunca vê a senha. Com `TALK_LOGIN=senha` (laboratório) o app pede e-mail e senha do Talk.
5. **Notificações pelo serviço de push do Expo** (que entrega pela Apple e pelo Google). O worker lê os mesmos eventos do
   tempo real (`talk_events`) e avisa: conversa nova na fila que a pessoa pode pegar, mensagem do cliente numa conversa
   dela e transferência para ela ou para a fila do setor dela. Quem fez a ação não é avisado.
6. **LGPD:** por padrão a notificação mostra só o nome do cliente (`PUSH_PREVIEW=nome`). O texto da mensagem só sai do
   servidor com `PUSH_PREVIEW=texto`, porque passa por Expo, Apple e Google. `nada` esconde até o nome.

## Consequências
- Precisa de conta no Expo (gratuita) e das contas de desenvolvedor da Apple (US$ 99/ano) e do Google Play (US$ 25 uma
  vez) para publicar nas lojas. Para testar antes, o build "preview" gera um APK e o TestFlight distribui no iPhone.
- O Talk precisa estar num endereço HTTPS alcançável pelo celular fora do escritório (o Cloudflare Tunnel do ADR 0005).
- O ERP continua com um único endereço de volta (`TALK_URL`): o app precisa usar esse mesmo endereço do Talk.
- Apple e Google recusam ícone que imite outra marca; o ícone da web (balão do WhatsApp) não vai para as lojas.
