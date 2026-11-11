# preflow/ai

A small, provider-neutral client for text and JSON completions. Callers build an `AiRequest`
and get an `AiResponse` back from an `AiClient`; Claude and Gemini providers implement the
interface, `fake` answers without a key, and a provider of your own plugs in through config
(an `AiClientFactory`).

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

AI is off until you turn it on: the switch in `config/ai.php`, and a provider that can run. The
built-ins need their key in the environment; `fake` and a provider of your own may need none (the
provider says, see below). Without both, `Ai::enabled()` is false, nothing is called, and nothing
throws on render. Listing the provider without a `config/ai.php` is harmless.

`config/ai.php`:

```php
return [
    'enabled'  => true,
    'provider' => 'claude',                       // 'claude', 'gemini', 'fake' or an AiClientFactory class
    'models'   => ['claude' => 'claude-sonnet-5-5', 'gemini' => 'gemini-2.5-flash'],
    'keys'     => ['claude' => 'ANTHROPIC_API_KEY', 'gemini' => 'GEMINI_API_KEY'],
    'options'  => [],                             // more settings per provider, by its name or class
    'pricing'  => [],                             // 'model' => [input, output] USD per million tokens
    'storage'  => 'storage/ai',
    'tuning'   => [],                             // instructions for the translator (preflow/i18n)
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
- `provider`: which one is used: `'claude'`, `'gemini'`, `'fake'` or the class of a
  `Preflow\Ai\AiClientFactory`. Anything else stops the boot with a message naming these forms.
  `models` has one model per provider, `keys` the name of the environment variable that holds each
  provider's key, and `options` more settings per provider; all three are keyed by the provider's
  name or class. `models` has no fallback for the built-ins: without an entry for the chosen
  provider every request goes out without a model and fails (`ai:check` too).
- Keys come from the environment only. They are never read from `config/ai.php`, never stored,
  logged or rendered. `Ai::status()` and the admin screens say only "key present: yes/no".
- `storage`: where runtime files go, relative to the app root (or absolute). It holds
  `usage-YYYY-MM.jsonl` (the usage log), `settings.json` (tuning and glossary edits made in the
  admin) and `jobs/{id}.json` (whole-type translation runs). Keep it out of git.
- `tuning`, `translation`: instructions and the glossary for the machine translator; other AI
  requests don't get them. See `preflow/i18n`.

## Providers and defaults

| Provider | Model in the example | Key variable |
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

### A provider of your own

A local model server, another vendor, a proxy: write an `AiClient` (see Concepts) and a factory
that builds it, and name the factory in `config/ai.php`:

```php
'provider' => App\Ai\LocalModels::class,
'models'   => [App\Ai\LocalModels::class => 'llama-3.1-8b'],
'options'  => [App\Ai\LocalModels::class => ['url' => 'http://127.0.0.1:11434/v1']],
// 'keys'  => [App\Ai\LocalModels::class => 'LOCAL_AI_KEY'],   // only if it needs one
```

```php
final class LocalModels implements Preflow\Ai\AiClientFactory
{
    public function __construct(private readonly Psr\Http\Client\ClientInterface $http) {}

    public function available(#[\SensitiveParameter] array $options): bool
    {
        return ($options['url'] ?? '') !== '';      // no key needed: the server says whether it runs
    }

    public function create(#[\SensitiveParameter] array $options): Preflow\Ai\AiClient
    {
        return new OpenAiCompatibleClient($this->http, $options['url'], $options['model'], $options['key']);
    }
}
```

- The container builds the factory on first use, after every service provider registered, so its
  constructor may take services and `#[Config]` values.
- `$options` is the provider's `options` entry plus `model` (its `models` entry, `''` when none) and
  `key` (the value of the variable its `keys` entry names; `null` when none). Never put the key in
  an exception message.
- `available()` decides whether AI is on (with the switch). The built-ins answer "the key is
  present"; `Ai::status()` reports `available`, `keyPresent` and `usesKey` (whether a key variable
  is configured), never the key.
- `create()` is called once; throw `AiUnavailable` when the provider can't be used after all.
- Keep `available()` cheap. If it throws, AI counts as not available and the message is shown as
  the reason. A factory that can say why it isn't available implements `ExplainsUnavailability`
  (`unavailableReason(array $options): ?string`).
- PHP does not inherit `#[\SensitiveParameter]` from the interface: repeat it on both methods, as
  above, or a debug page's stack trace shows the key.
- Everything that uses `Ai` works with it: the usage log (the client's model and provider names
  are logged; a model the price table doesn't know has an unknown cost, never 0, so add it to
  `pricing` if you want a cost), `ai:check`, `ai:usage`, the machine translator of `preflow/i18n`,
  and Folio's Translate and Settings → AI.

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

`'provider' => 'fake'` runs everything AI without a key and without cost, **on your machine and in
tests only** (it gives every request the same answer). With `app.debug` off it reports "not
available", so AI stays off on a live site even when the setting ships by mistake; `ai:check` and
Settings → AI say why, and one warning goes to the PHP log. Tests that run with debug off set
`'allow_live' => true` in `options.fake`:

```php
'enabled'  => true,
'provider' => 'fake',
'options'  => ['fake' => ['answer' => ['summary' => 'A designer for a small studio.'], 'usage' => [310, 42]]],
```

- `answer`: an array is sent as JSON, a string as text. Default `{"ok":true}`, which `ai:check`
  accepts.
- `usage`: the tokens each call reports, `[input, output]` (default `[10, 10]`).
- The model is `models.fake`, default `fake-model`; the price table doesn't know it, so costs show
  as unknown.

For a script (answers in order, an answer per request, failures), write a factory of your own
that returns a `FakeAiClient` and name it as the provider. For translation, the client's answer has
to be the numbered items back in the shape the translator asks for; `packages/folio/README.md`
("Trying it without a key") has such a script.
