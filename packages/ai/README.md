# preflow/ai

A small, provider-neutral client for text and JSON completions. Callers build an `AiRequest`
and get an `AiResponse` back from an `AiClient`; Claude and Gemini providers implement the
interface, and `Preflow\Ai\Testing\FakeAiClient` stands in for them in tests.

Nothing in this package knows about translation. It is the general AI connection; the machine
translator in `preflow/i18n` and the AI screens in Folio are users of it. It does not depend on
Folio.

## Install

```
composer require preflow/ai
```

Add the provider to `config/providers.php`. (`preflow/i18n`'s `MachineServiceProvider` registers
it for you when it is listed and `AiServiceProvider` is not.)

```php
return [
    Preflow\Ai\AiServiceProvider::class,
];
```

## Setup

AI is off until you turn it on in two places: the switch in `config/ai.php` and the provider's
key in the environment. Both are needed. Without either, `Ai::enabled()` is false, nothing is
called, and nothing throws on render. Listing the provider without a `config/ai.php` is
harmless.

`config/ai.php`:

```php
return [
    'enabled'  => true,
    'provider' => 'claude',                       // 'claude' or 'gemini'
    'models'   => ['claude' => 'claude-sonnet-5-5', 'gemini' => 'gemini-2.5-flash'],
    'keys'     => ['claude' => 'ANTHROPIC_API_KEY', 'gemini' => 'GEMINI_API_KEY'],
    'pricing'  => [],                             // 'model' => [input, output] USD per million tokens
    'storage'  => 'storage/ai',
    'tuning'   => [],                             // instructions for every AI request
    'translation' => [                            // used by preflow/i18n, see its README
        'languages'   => [],
        'types'       => [],
        'glossary'    => [],
        'batch_chars' => 12000,
    ],
];
```

`.env`:

```
ANTHROPIC_API_KEY=sk-ant-...
# or
GEMINI_API_KEY=...
```

- `enabled`: the master switch. Default `false`.
- `provider`: which one is used. `models` has one model per provider, `keys` the name of the
  environment variable that holds each provider's key.
- Keys come from the environment only. They are never read from `config/ai.php`, never stored,
  logged or rendered. `Ai::status()` and the admin screens say only "key present: yes/no".
- `storage`: where runtime files go, relative to the app root (or absolute). It holds
  `usage-YYYY-MM.jsonl` (the usage log), `settings.json` (tuning and glossary edits made in the
  admin) and `jobs/{id}.json` (whole-type translation runs). Keep it out of git.
- `tuning`, `translation`: instructions and the glossary. See `preflow/i18n`.

## Providers and defaults

| Provider | Default model | Key variable |
|---|---|---|
| `claude` (the official `anthropic-ai/sdk`) | `claude-sonnet-5-5` | `ANTHROPIC_API_KEY` |
| `gemini` (REST) | `gemini-2.5-flash` | `GEMINI_API_KEY` |

The differences between providers stay inside them: how JSON is enforced, whether a temperature
is accepted, refusals and Gemini's recitation block (which comes back as `StopReason::Blocked`, so
a caller can split the batch and retry). Gemini retries once after two seconds on a rate limit
(429) or a server error (5xx); Claude's SDK retries once too. Both give up on a request after
120 seconds, so one try and its retry fit what shared hosting allows a web request.

`AiRequest::$lowReasoning` asks for as little thinking as the model allows, for plain work such as
translation: Claude gets effort `low` (`output_config.effort`; current Claude models refuse
`thinking: disabled`), Gemini `thinkingConfig.thinkingBudget: 0`.

Claude requests mark the tuning (the stable system part) for prompt caching, so the later
requests of a batch read it from the cache at a fraction of the price.

## Concepts

- `AiRequest`: a purpose label (for usage logs), the prompt, an optional `Tuning` (the stable
  system part, so providers can cache it across a batch), an optional JSON schema and limits.
- `AiResponse`: text, a `StopReason` (`Complete`, `Blocked`, `Truncated`, `Refused`),
  `TokenUsage`, provider and model. `json()` decodes the answer and throws `AiResponseInvalid`
  when it is not valid JSON.
- `Tuning`: an ordered list of instruction texts plus an optional answer template. `merge()`
  appends the later one's instructions, and its template wins when it has one.
- Errors: `AiUnavailable` means stop everything (AI off, no key, bad key, no credit, provider
  down); `AiRequestFailed` affects only the one request.

```php
$client = $container->get(Preflow\Ai\Ai::class)->client(); // throws AiUnavailable when AI is off

$response = $client->complete(new AiRequest(
    purpose: 'summary',
    prompt: 'Summarise: ...',
    tuning: new Tuning(['Answer in one sentence.']),
));
echo $response->text;
```

`Ai::client()` returns a client that writes the usage log for you.

## Pricing and token usage

Cost is worked out from a price table in USD per million tokens (input, output):

| Model | Input | Output |
|---|---|---|
| `gemini-2.5-flash` | 0.30 | 2.50 |
| `claude-haiku-5-5` | 0.10 | 0.50 |
| `claude-sonnet-5-5` | 2.00 | 10.00 |
| `claude-opus-5-5` | 4.00 | 20.00 |

Add a model or change a price with `pricing` in `config/ai.php`; your entries are merged over
this table. A model without a price has a cost of `null`, shown as "unknown" or "—". It is never
shown as 0, because a silent "free" would hide spending.

`TokenUsage` has three numbers, and their meaning matters for the cost:

- `input`: **all** prompt tokens, including the cached ones.
- `cachedInput`: the part of `input` that came from the provider's cache.
- `output`: the answer's tokens.

Cached tokens are billed at 10 percent of the input price, the rest at the full price. For Claude,
the tokens used to create a cache entry are folded into `input` at the normal price. That is an
approximation: Anthropic bills cache creation a little higher.

## Usage log

Every call through `Ai::client()` appends one JSON line to `storage/ai/usage-YYYY-MM.jsonl`: time,
purpose, provider, model, input, output and cached tokens, the cost, and whether the call failed.
**It never stores the request or the response text.** A failed request is logged too (with 0
tokens), so you can see how often a run needed retries. A broken log never breaks a call.

Two commands read it (they need `preflow/devtools`):

```
php preflow ai:check
php preflow ai:usage [--month=YYYY-MM]
```

- `ai:check` prints provider, model, whether the key is present and whether AI is enabled, then
  sends one tiny request (asking for `{"ok":true}`) and prints the tokens and cost. Use it after
  deploying to see that key, model and network work. Exit code 0 only when the model answered as
  asked; 1 when AI is off or the request failed. It never prints the key.
- `ai:usage` lists calls, failed calls, tokens and cost per purpose and model for a month (this
  one by default). A `+` after a cost means some calls were of a model with no price.

Folio shows the same numbers on Settings → AI.

## Testing

Tests never call a real provider.

```php
$client = new FakeAiClient([FakeAiClient::json(['ok' => true])]);
```

The script is consumed in order and the last entry repeats. Entries may be responses,
throwables or callables receiving the request. `requests()` returns what was sent, so a test can
check the prompt. `FakeAiClient` lives in this package (not in `preflow/testing`), so testing
needs no extra dependency.

### Live smoke tests

There is one opt-in test per provider that calls the real API. They carry the `live` group, which
the default `phpunit.xml` excludes, and each skips itself when its key is not set:

```
ANTHROPIC_API_KEY=... GEMINI_API_KEY=... vendor/bin/phpunit --group live
```

They make billed calls (a few tokens each). `ANTHROPIC_MODEL` and `GEMINI_MODEL` pick another
model.

### Trying it without a key

To click through the AI screens locally without a provider, bind an `Ai` whose client is a
`FakeAiClient`. The constructor takes the config, the usage log, the pricing, a function that
returns the "key" and a function that builds the client. Put this in a provider listed after the
Folio and AI providers, **on your machine only**:

```php
final class FakeAiProvider extends Preflow\Core\Container\ServiceProvider
{
    public function register(Preflow\Core\Container\Container $container): void
    {
        $container->singleton(Preflow\Ai\Ai::class, static fn ($c) => new Preflow\Ai\Ai(
            ['enabled' => true, 'provider' => 'claude', 'models' => ['claude' => 'claude-sonnet-5-5'], 'keys' => ['claude' => 'FAKE']],
            $c->get(Preflow\Ai\Usage\UsageLog::class),
            $c->get(Preflow\Ai\Usage\Pricing::class),
            static fn (): string => 'fake',
            static fn () => new Preflow\Ai\Testing\FakeAiClient([Preflow\Ai\Testing\FakeAiClient::json(['ok' => true])], 'claude-sonnet-5-5'),
        ));
    }
}
```

For translation, the client's answer has to be the numbered items back in the shape the translator
asks for; `packages/folio/README.md` ("Trying it without a key") has a fake that answers every
text with `[de] ` and the text.
