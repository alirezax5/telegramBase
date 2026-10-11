# Multi-bot — executando dois bots em um processo

O TelegramBase suporta operação multi-bot em dois níveis: a biblioteca
[`telegramBotPhp`](https://github.com/alirezax5/telegramBotPhp) fornece os
primitivos (instâncias independentes, pilha de tokens, chamadas raw), e o framework adiciona um
registro **`BotManager`** para que os plugins alcancem bots secundários pelo nome.

Ambos os cenários solicitados pelo proprietário foram verificados ao vivo (2026-10-11) com dois bots reais:

| Cenário | O que acontece | Resultado verificado |
|---|---|---|
| **A — checagem de membros via bot #2** | o usuário pergunta ao bot #1; o bot #1 executa `getChatMember()` **com o token do bot #2** | `ok=true`, `status=member`, 13 ms |
| **B — envio delegado** | o usuário pergunta ao bot #1; o bot #1 faz **o bot #2 enviar** a mensagem, dentro do processo do bot #1 | entregue, 239 ms |

---

## Registrando bots extras: `BOT_EXTRA_BOTS`

Adicione a variável de ambiente antes do boot (no `.env` ou no ambiente do processo):

```dotenv
BOT_EXTRA_BOTS="second:398172019:AAxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
```

Regras de formato:

- as entradas são **separadas por vírgula**; cada entrada é `name:token`;
- divida apenas no **primeiro** dois-pontos (um token de bot em si contém dois-pontos);
- o nome `main` é reservado para o bot primário e ignorado nesta lista;
- entradas malformadas são registradas (aviso) e puladas — o boot nunca falha.

O `BotManager` é um singleton criado no primeiro uso; o bot padrão (`main`) é construído
a partir de `Config::bot()` (ou seja, `BOT_TOKEN`), e então os extras são registrados.

## Usando bots a partir de um plugin

```php
use alirezax5\TelegramBase\App\Bot\BotManager;
use alirezax5\TelegramBase\App\Plugin\Contract\PluginInterface;
use telegramBotApiPhp\Telegram;

class MultiBotPlugin implements PluginInterface
{
    public function getPriority(): int { return 1; }

    public function onMessage($message, Telegram $tg): void
    {
        if ($message->text !== '/inchannel') {
            return;
        }

        $bm  = BotManager::getInstance();
        $userId = $tg->fromId();
        $chatId = $tg->chatId();

        // Scenario A — membership check executed by bot #2's identity
        if ($bm->has('second')) {
            $member = $bm->get('second')->getChatMember('@mychannel', $userId);
            $status = $member->result->status ?? 'left';   // member|administrator|creator|left|kicked
            $tg->sendMessage($chatId, "وضعیت عضویت شما (چک توسط ربات ۲): {$status}");
        } else {
            $tg->sendMessage($chatId, 'ربات دوم ثبت نشده — BOT_EXTRA_BOTS را تنظیم کنید');
        }

        // Scenario B — bot #1 sends AS bot #2 (token stack on the main instance)
        if ($bm->has('second')) {
            $tg->switchToken($bm->get('second')->getToken());
            try {
                $tg->sendMessage($chatId, 'این پیام با هویت ربات ۲ ارسال شد (داخل پروسه ربات ۱)');
            } finally {
                $tg->restoreToken();
            }
        }
    }
}
```

## API do `BotManager`

| Método | Comportamento |
|---|---|
| `getInstance(): BotManager` | acessor do singleton |
| `get(?string $name): Telegram` | retorna o bot nomeado; **nomes desconhecidos voltam ao `main`** (aviso registrado) |
| `has(string $name): bool` | o nome está registrado? — **sempre verifique antes do `get()` de um bot não padrão** |
| `add(string $name, Telegram $bot): void` | registra/sobrescreve um bot em tempo de execução (sobrescrever registra um aviso) |
| `names(): string[]` | todos os nomes registrados (`main` primeiro) |
| `setDefaultToken(string $token): Telegram` | sobrescreve o token do bot **main** globalmente — afeta todos os chamadores |

## Por que o `has()` importa

`get('typo-name')` não lança exceção — ele silenciosamente retorna o bot **main**. Um
`getChatMember()` destinado ao bot #2 seria então executado pela identidade do bot #1 e
retornaria `chat member not found` ou pior, uma resposta errada-mas-plausível. Proteja todo
acesso não padrão com `has()`.

## Gestão de tokens: vários bots em um arquivo

Uma única implantação (um arquivo de entrada + um `.env`) pode atender qualquer
número de identidades de bots. Três estratégias são suportadas — ordenadas por recomendação:

| # | Estratégia | Onde os tokens ficam | Melhor para | Verificado |
|---|---|---|---|---|
| 1 | **Registro estático** — `BOT_TOKEN` + `BOT_EXTRA_BOTS` no `.env` | um `.env`, pares `name:token` | um conjunto fixo de bots implantados juntos | 23/23 checagens (parse / identidade / degradação / escala) |
| 2 | **Registro dinâmico** — `BotManager::add($name, new Telegram($token))` em tempo de execução | seu banco de dados/painel de configuração; passado para o processo | bots provisionados por um painel de administração, bots por locatário | checagens de identidade + envio delegado |
| 3 | **Bots gerenciados** — tokens filhos obtidos ao vivo do Telegram (próxima seção) | **em lugar nenhum do disco** | um bot gerenciador criando bots filhos em nome dos usuários | suíte TM-2, 8/8 |

Regras que a suíte de testes ao vivo comprova (2026-10-11, dois bots reais + entradas sintéticas):

- entradas malformadas de `BOT_EXTRA_BOTS` são registradas (aviso) e **puladas — o boot nunca falha**;
- um token morto/revogado falha de forma limpa (`ok=false`, `Unauthorized`) e nunca contamina
  os outros bots: `get('dead')` dá erro, `get('alive')` e `main` permanecem saudáveis;
- `main` é reservado; em nomes duplicados, prevalece o último, com um aviso;
- os dois-pontos do próprio token sobrevivem à divisão `name:token` (apenas o primeiro dois-pontos);
- isolamento de identidade: o `getMe()` de cada instância registrada corresponde ao prefixo de id
  do seu token; a pilha de tokens é por instância (switch/restore no `main` deixa
  `second` intacto);
- buscas no registro são O(1): 10.000 chamadas `get()` com 32 bots registrados em ~1 ms.

Nota sobre webhooks: nos modos `webhook_*` todo update que chega ao `webhook.php` é
processado sob a identidade do bot **main** (um `BOT_WEBHOOK_SECRET` compartilhado).
Para atender vários bots via webhooks você **não** precisa de um diretório por bot —
o padrão `secret_token` da próxima seção roda **qualquer número de bots a partir de
um único arquivo de webhook**, comprovado ao vivo.

## Um arquivo de webhook, vários bots — o padrão `secret_token`

Você NÃO precisa de uma pasta por bot. **Um único arquivo de webhook pode atender
qualquer número de bots.** O truque é o parâmetro `secret_token` do `setWebhook`:

1. Escolha um **segredo aleatório diferente para cada bot** (apenas letras, dígitos,
   `_` e `-` são permitidos).
2. Registre a **mesma URL de webhook** para todos os bots, cada um com o seu próprio
   segredo:
   `setWebhook?url=https://example.com/hook.php&secret_token=<this-bots-secret>`
3. A cada update, o Telegram devolve esse segredo no cabeçalho
   `X-Telegram-Bot-Api-Secret-Token`.
4. Seu arquivo lê o cabeçalho, procura o segredo em um pequeno registro
   (`segredo → token do bot`) e processa o update sob a identidade desse bot.

```text
             ┌─ bot A: setWebhook(url=hook.php, secret=SECRET_A)
 Telegram ───┤
             └─ bot B: setWebhook(url=hook.php, secret=SECRET_B)
                         │   POST + header X-Telegram-Bot-Api-Secret-Token
                         ▼
               ┌───────────────────┐   SECRET_A → token A → atuar como bot A
               │     hook.php      │
               │  registro:        │
               │  secret → token   │   SECRET_B → token B → atuar como bot B
               └───────────────────┘
```

Por que esta é a forma recomendada de executar uma fazenda de bots:

- **o token do bot nunca aparece em uma URL** — ele não pode vazar para logs de
  acesso, histórico do navegador ou proxies;
- o segredo também serve como **verificação de autenticidade** — uma requisição
  sem o segredo correto não vem do Telegram; responda `403` e descarte-a;
- **adicionar o bot #N+1 custa uma chamada `setWebhook` e uma linha no registro** —
  sem arquivos novos, sem nova implantação.

Prova ao vivo (2026-10-11, dois bots reais, UM arquivo, tráfego real de usuários):

| Evento | Identidade roteada | Resultado |
|---|---|---|
| `/start` real no bot B | @instaroonxbot | respondeu, `ok:true` |
| toque real no botão inline do bot B (×2) | @instaroonxbot | `answerCallbackQuery` + resposta, `ok:true` |
| toque real no botão inline do bot A | @emojiCustom_bot | `answerCallbackQuery` + resposta, `ok:true` |
| POST sintético com segredo A / segredo B | bot correto a cada vez | respondeu, `ok:true` |

7/7 entregas — cada update chegou ao mesmo arquivo e cada resposta carregou a
identidade correta do bot. O token do bot nunca esteve em nenhuma URL ou cabeçalho.

Regras de hardening para produção:

- o segredo **é a chave de roteamento** — ele deve ser único por bot;
- gere-o aleatoriamente, com 32+ caracteres de `[A-Za-z0-9_-]` (o Telegram rejeita
  outros caracteres); um segredo de 2 caracteres é efetivamente nenhum segredo;
- rejeite segredos desconhecidos ou ausentes com `403` antes de tocar em qualquer
  outra coisa;
- nunca coloque tokens em logs; no máximo, registre um prefixo curto do segredo;
- rotacionar um segredo = executar `setWebhook` novamente para aquele bot (leva segundos).

## Bots gerenciados (Managed Bots — Bot API 9.6)

A Telegram Bot API 9.6 (2026-04-03) adicionou **Managed Bots** (bots gerenciados): um bot com
`can_manage_bots` (habilitado no Mini App do @BotFather) pode ter outros bots
criados e controlados sob ele. O token filho nunca precisa ser armazenado —
o gerenciador o obtém ao vivo com `getManagedBotToken(user_id)`.

Suporte do framework (v2.4.x):

| Elemento da API (Bot API 9.6) | Superfície do framework |
|---|---|
| `User.can_manage_bots` (getMe) | `$tg->callRaw('getMe')->result->can_manage_bots` |
| update `managed_bot` (`ManagedBotUpdated`) | despachado para `onManagedBot($managedBot, $tg)` — adicionado à whitelist de plugins |
| `KeyboardButtonRequestManagedBot` | `Keyboard::reply()->requestManagedBot($text, $requestId, $suggestedName, $suggestedUsername)` |
| `getManagedBotToken` / `replaceManagedBotToken` / `get·setManagedBotAccessSettings` | `BotManager::registerManagedBot()` / `refreshManagedBot()` / `managedBotAccessSettings()` |
| campo `ManagedBotCreated` da mensagem | visível no payload dentro de `onMessage` |

Fluxo de ponta a ponta (esboço de plugin):

```php
use alirezax5\TelegramBase\App\Bot\BotManager;
use alirezax5\TelegramBase\App\Button\Keyboard;

// 1) offer creation (private chats only)
$tg->sendMessage($chatId, 'Create your own bot:', [
    'reply_markup' => Keyboard::reply()
        ->requestManagedBot('➕ Create my bot', 1, 'MyHelperBot', 'my_helper_bot')
        ->toArray(),
]);

// 2) the user completes the flow in Telegram → managed_bot update arrives
public function onManagedBot($managedBot, $tg): void
{
    // fetches the child token live (getManagedBotToken) and registers it
    BotManager::getInstance()->registerManagedBotFromUpdate($managedBot);
}

// 3) use the child bot — registered under its bot id
BotManager::getInstance()->get((string) $managedBot->bot->id)
    ->sendMessage($chatId, 'hello from the child bot');
```

Pré-requisitos (ações do proprietário, uma única vez):

1. Habilite o **gerenciamento de outros bots** para o bot gerenciador no Mini App
   do @BotFather — até lá, o `getMe` reporta `can_manage_bots: false` e a
   solicitação de criação falha.
2. A criação do bot filho em si sempre acontece do lado do Telegram (consentimento
   do usuário); o framework apenas recebe o update `managed_bot` e obtém
   o token.

Higiene dos tokens: os tokens gerenciados vivem apenas em memória (no registro) — nada é
gravado no `.env` nem em logs. Rotacionar um token filho via
`BotManager::refreshManagedBot($userId, $name)` revoga o token antigo
imediatamente e re-registra o novo.

### Bots gerenciados — ponta a ponta ao vivo (2026-10-11)

Depois que o proprietário habilitou o **gerenciamento de outros bots** no Mini App
do @BotFather, o `getMe` passou a reportar `can_manage_bots: true` em ambos os bots
de teste e o fluxo completo foi exercitado ao vivo:

- o botão de criação construído por `Keyboard::reply()->requestManagedBot(...)` foi
  enviado em um chat privado — o Telegram aceitou o formato de teclado do framework;
- detalhe de campo que vale a pena conhecer: para `KeyboardButtonRequestManagedBot`,
  o `request_id` deve ser um **Number** (diferente de `request_chat` /
  `request_users`, em que ele é uma String — o Telegram rejeita uma string com
  `Bad Request: can't parse KeyboardButton: Field "request_id" must be a valid Number`);
- quando o usuário completa o formulário de criação, o gerenciador recebe um
  update `managed_bot` e obtém o token filho ao vivo com `getManagedBotToken(user_id)`
  — a demonstração então fez o **bot filho falar no chat do proprietário** com o
  seu próprio token recém-obtido (zero em disco).

Habilitar os mesmos interruptores do Mini App também alterou, em ambos os bots de
teste: `supports_guest_queries`, `has_topics_enabled`,
`supports_join_request_queries`, `can_connect_to_business` → todos `true`.

- Formato real do update (capturado em 2026-10-11): `managed_bot.user` = a pessoa que criou o bot filho (um usuário comum), `managed_bot.bot` = o próprio bot filho (`{id, is_bot: true, username, ...}`). Passe `managed_bot.bot.id` para `getManagedBotToken` — o id do criador retorna `Bad Request: user is not a bot`. Na demonstração, o token do bot filho foi obtido ao vivo e o filho entregou uma mensagem real ao seu criador — zero tokens em disco.

### Onde guardar tokens e segredos

| Armazenamento | Quando se encaixa | Atenção |
|---|---|---|
| **`.env`** (`BOT_TOKEN` + `BOT_EXTRA_BOTS`) | um conjunto pequeno e fixo de bots | legível apenas por root; mudanças exigem reinício |
| **Tabela de registro no banco de dados** (`bots`: id, name, token, secret, status) | bots adicionados/removidos em tempo de execução, painéis de administração | criptografe a coluna de token em repouso (ex.: sodium); faça cache do registro para que um update ≠ uma consulta ao banco |
| **Bots gerenciados (zero em disco)** | um bot gerenciador criando bots filhos para os usuários | precisa de `can_manage_bots`; token obtido ao vivo via `getManagedBotToken` |
| **Segredos derivados (HMAC)** — nada armazenado | você controla uma chave mestra | `secret = base64url(hmac_sha256(master_key, 'bot:' . bot_id))`; rotacionar a chave mestra significa re-registrar todos os webhooks |

Recomendação para uma fazenda de bots em crescimento: **registro no banco de dados
(token criptografado em repouso) + busca em cache + roteamento `secret_token`**, e
**Bots gerenciados** para os bots filhos criados pelos usuários. Mantenha o `.env`
apenas para o primeiro bot gerenciador.

### Mantendo os dados de cada bot separados

Quando vários bots compartilham uma única implantação, os dados de cada bot devem
permanecer exclusivos daquele bot. Coloque **cada camada de armazenamento no
namespace do id do bot** (`b{bot_id}`):

| Camada | Regra |
|---|---|
| Cache | prefixo de chave `b{bot_id}:` (defina `CACHE_PREFIX` por bot no boot) |
| Sessões | escopo por **id do bot + id do usuário** — a mesma pessoa conversando com dois bots são duas sessões diferentes |
| Offset do getUpdates | **um arquivo/chave de offset por bot** — um offset compartilhado entregaria os updates de um bot a outro |
| Fila | um nome de fila por bot (`tgbase_b{bot_id}_updates`) ou marque os payloads com `bot_id` |
| Armazenamento de arquivos | `data/bots/{bot_id}/…` |
| Logs | um arquivo de log por bot (`logs/bot_{id}.log`) |
| Banco de dados | tabelas compartilhadas recebem uma coluna `bot_id`; isolamento verdadeiro = um banco de dados separado por bot |

O front-controller do webhook resolve `segredo → bot` primeiro e, então, inicia o
framework com o token e o namespace daquele bot — toda leitura/escrita posterior
permanece automaticamente dentro da sandbox do próprio bot.

## Semântica de entrega

As chamadas multi-bot usam o mesmo cliente HTTP de qualquer outra chamada de API:

- retentativas/backoff e o tratamento de `retry_after` aplicam-se por instância;
- a pilha de tokens é **estado por instância** — nos workers de fila cada update é
  processado por um despacho novo, então os pares switch/restore vivem dentro do
  tempo de vida de um único update; nunca conte com o estado da pilha entre updates;
- nos modos `webhook_*` as mesmas regras se aplicam dentro da única requisição FPM.

## Ferramenta de verificação ao vivo

`integration/TestBot/Plugin/MultiBotTestPlugin.php` (ferramenta de teste, chats privados):

- `/multibot` — suíte completa: identidade, isolamento, envio cruzado (Cenário B),
  membros via bot #2 (Cenário A), switch/restore da pilha de tokens;
- `/mbchannel <chat>` — `getChatMember` do bot #2 em um `@channel`/chat id explícito
  (o bot #2 deve ser admin/membro daquele chat).

Registro de evidências: `runtime/p331/multibot.log` — última execução ao vivo
(2026-10-11 12:26:19): identidade ✔, isolamento ✔, envio cruzado ✔ (239 ms),
membros ✔ (13 ms), pilha de tokens ✔ — **5/5 PASS em 393 ms**.

Execuções adicionais da ferramenta (2026-10-11, runtime ao vivo após a sincronização):

- **Suíte TM-1 de gestão de tokens** — 23/23 PASS em quatro casos:
  `parse` (7), `identity` (8, dois bots reais), `degrade` (5, token revogado),
  `scale` (3, 32 bots / 10k buscas).
- **Suíte TM-2 de bots gerenciados** — 8/8 PASS: o `getMe` ao vivo expõe
  `can_manage_bots`; `getManagedBotToken` / `getManagedBotAccessSettings`
  alcançáveis com contratos de erro limpos; o update `managed_bot` sintético
  despacha `before → onManagedBot`; um `registerManagedBot()` inválido lança
  exceção de forma limpa sem contaminar o registro; o formato do teclado de
  `requestManagedBot()` corresponde a `KeyboardButtonRequestManagedBot`.
