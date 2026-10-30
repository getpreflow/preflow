# AI connection and auto-translate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A general AI connection (`preflow/ai`) and AI translation in core (`preflow/i18n`) usable without Folio. Folio adapts it: a Translate button with review, whole-type runs, and "not checked" markers. BG Genius flows use it.

**Architecture:** `preflow/ai` puts Claude and Gemini behind `AiClient`, and adds tuning, a usage log, pricing and a settings store. `preflow/i18n` gets `Machine\*`:
- units, a markup check, the glossary, `AiTranslator`;
- a `TextSource` interface and a `JobRunner` for whole collections.

Folio implements `TextSource` for its records, so its screens only call core. BG Genius implements the write-back for its `flow` field and reuses Folio's endpoints.

**Tech Stack:**
- PHP 8.4+ (8.5 on the sites);
- `anthropic-ai/sdk` ^1.2 for Claude;
- PSR-18 via `symfony/http-client` and `nyholm/psr7` for Gemini (and as the SDK's HTTP client);
- PHPUnit 11; htmx and Twig in Folio.

**Spec:** `docs/superpowers/specs/2026-10-30-ai-translation-design.md` (read it first; owner-approved 2026-10-30).

## Global Constraints

- **Decoupling (owner):** no class in `preflow/ai` or `preflow/i18n` imports `Preflow\Folio\*`. Folio code only calls core. BG Genius code stays in BG Genius.
- AI is off unless `config/ai.php` `enabled` is true **and** the provider's key env var is set. With AI off:
  - no button, no bulk action and no settings nav entry are shown;
  - endpoints answer 404;
  - nothing throws on render.
- Keys come only from env (`ANTHROPIC_API_KEY`, `GEMINI_API_KEY`). They are never stored, logged or rendered; the status shows only "key present yes/no".
- Default models: `claude-sonnet-5-5`, `gemini-2.5-flash`.
- Default prices in USD per million tokens (input/output):

  | Model | Input | Output |
  |---|---|---|
  | `gemini-2.5-flash` | 0.30 | 2.50 |
  | `claude-haiku-5-5` | 0.10 | 0.50 |
  | `claude-sonnet-5-5` | 2.00 | 10.00 |
  | `claude-opus-5-5` | 4.00 | 20.00 |

  A project can override them in `config/ai.php` `pricing`.
- The usage log never holds request or response text.
- Nothing AI-made reaches the public site without review:
  - suggestion endpoints never write;
  - bulk runs write working copies of publishing types;
  - a run on a non-publishing type needs an explicit confirmation checkbox.
- Tests never call a real provider. `Preflow\Ai\Testing\FakeAiClient` ships in `preflow/ai` (ruling: not in `preflow/testing`, so testing needs no ai dependency). One opt-in smoke test per provider is skipped without its key env var.
- Fingerprints use the existing algorithm: `substr(hash('sha256', trim($text)), 0, 16)`.
- Runtime files live under `storage/ai/` (configurable `config/ai.php` `storage`):
  - `usage-YYYY-MM.jsonl`;
  - `settings.json` for stored tuning and glossary edits;
  - `jobs/{id}.json`.
- New packages ship en and de UI texts via `extra.preflow.translations`, like `preflow/codes`.
- Follow each package's existing style (final classes, readonly value objects, docblocks explaining *why*, `declare(strict_types=1)`).
- The version is 0.47.0. Constraints:
  - new packages require siblings as `^0.47 || @dev`;
  - `preflow/i18n` and `preflow/folio` require `preflow/ai` `^0.47 || @dev`;
  - add `conflict` floors like the codes package does.

## Review Focus

1. **The model's reply is malformed.** It may come back with fewer, extra or reordered items, or be wrong JSON. Items map back by id: missing ones count as failed, extra ones are ignored, and nothing is shifted onto the wrong text. Task 7 tests this.
2. **The record changes between "suggest" and "save", or during a bulk run.** Writing goes through the version check: a conflict skips that record and reports it, and never overwrites. Task 10 tests this.
3. **AI is off or the key is missing.** Forms, lists and settings render exactly as before, and the AI endpoints return 404. Task 12 tests this.
4. **HTML with attributes and placeholders.** For example `<a href="…">`, Trix's `<div>`/`<br>`, `{count}` next to `:name`. The markup check compares tag names in order and the placeholder multiset, not attributes. A result that drops a tag or a placeholder fails. Task 5 tests this.
5. **A long record (a flow with 300 texts).** It is split into requests by a character budget, never sent as one request. A blocked request is halved, down to single units. Task 7 tests this.
6. **Saving a form again without touching an AI text.** The "not checked" marker survives a plain re-save and clears as soon as the text changes. Task 9 tests this.

---

### Task 1: `preflow/ai` package skeleton, value objects, fake client

**Files:**
- Create the package `packages/ai/`:
  - `composer.json` (name `preflow/ai`; require `php >=8.4`, `preflow/core ^0.38 || @dev`, `anthropic-ai/sdk ^1.2`, `symfony/http-client ^7.3 || ^8.0`, `nyholm/psr7 ^1.8`, `psr/http-client ^1`; autoload `Preflow\Ai\` → `src/`; `extra.preflow.translations` `{"ai": {"path": "lang", "scope": "admin"}}`);
  - `README.md`, `LICENSE` (copy from codes), `lang/en/ai.php`, `lang/de/ai.php`.
- Create in `packages/ai/src/`:
  - `AiClient.php`, `AiRequest.php`, `AiResponse.php`, `StopReason.php`, `TokenUsage.php`, `Tuning.php`;
  - `AiUnavailable.php`, `AiRequestFailed.php`, `AiResponseInvalid.php`;
  - `Testing/FakeAiClient.php`.
- Modify the monorepo wiring:
  - root `composer.json`: require-dev `preflow/ai: @dev` and a path repository;
  - `phpunit.xml`: a testsuite directory and a source directory;
  - `.github/workflows/split.yml`: `{ local: 'packages/ai', remote: 'ai' }`.
- Test: `packages/ai/tests/TuningTest.php`, `AiResponseTest.php`, `FakeAiClientTest.php`.

**Interfaces (Produces):**
```php
interface AiClient {
    /** @throws AiUnavailable (stop everything) | AiRequestFailed (this request only) */
    public function complete(AiRequest $request): AiResponse;
    public function provider(): string;   // 'claude' | 'gemini' | 'fake'
    public function model(): string;
}
final readonly class AiRequest {
    public function __construct(
        public string $purpose,            // usage-log label, e.g. 'translate'
        public string $prompt,             // user content
        public Tuning $tuning = new Tuning(),  // system part; stable across a batch → cached
        public ?array $schema = null,      // JSON schema of the expected answer, null = free text
        public ?float $temperature = null, // providers that can't take it ignore it
        public int $maxOutputTokens = 8000,
    ) {}
}
final readonly class Tuning {
    /** @param list<string> $instructions */
    public function __construct(public array $instructions = [], public ?string $template = null) {}
    public function with(string ...$instructions): self;  // appends non-empty, trimmed
    public function merge(self $later): self;              // appends later's instructions; later template wins when set
    public function isEmpty(): bool;
    public function system(): string;                      // instructions joined by "\n\n", then "Answer template:\n{template}" when set
}
enum StopReason: string { case Complete = 'complete'; case Blocked = 'blocked'; case Truncated = 'truncated'; case Refused = 'refused'; }
final readonly class TokenUsage { public function __construct(public int $input = 0, public int $output = 0, public int $cachedInput = 0) {} }
final readonly class AiResponse {
    public function __construct(public ?string $text, public StopReason $stop, public TokenUsage $usage, public string $provider, public string $model, public ?string $detail = null) {}
    /** Decoded JSON; strips one surrounding ``` / ```json fence. @throws AiResponseInvalid on null text or bad JSON */
    public function json(): mixed;
}
final class FakeAiClient implements AiClient {
    /** @param list<AiResponse|\Throwable|callable(AiRequest): (AiResponse|\Throwable)> $script consumed in order; the last entry repeats */
    public function __construct(array $script = [], string $model = 'fake-model');
    public static function json(mixed $data, TokenUsage $usage = new TokenUsage(10, 10)): AiResponse;  // Complete response with json_encode($data)
    /** @return list<AiRequest> */ public function requests(): array;
}
```

- [ ] **Step 1: Write failing tests**
  - `TuningTest`:
    - `test_with_appends_trimmed_non_empty`: `(new Tuning(['A']))->with(' B ', '', '  ')->instructions === ['A','B']`.
    - `test_merge_appends_and_later_template_wins`: `(new Tuning(['A'],'T1'))->merge(new Tuning(['B'],'T2'))` gives `['A','B']` and `'T2'`; merging with a null template keeps `'T1'`.
    - `test_system_joins_and_appends_template`: `"A\n\nB\n\nAnswer template:\nT"`.
  - `AiResponseTest`:
    - `test_json_strips_fence`: `` "```json\n[1]\n```" `` → `[1]`.
    - `test_json_throws_on_null_text_and_bad_json`.
  - `FakeAiClientTest`:
    - `test_script_in_order_last_repeats_and_requests_recorded`;
    - `test_throwable_entry_is_thrown`;
    - `test_callable_entry_receives_request`.
- [ ] **Step 2:** Run `vendor/bin/phpunit packages/ai/tests`. It fails because the classes don't exist.
- [ ] **Step 3:** Implement the files above. Run `composer update preflow/ai anthropic-ai/sdk symfony/http-client` at the root.
  - If Composer asks about the `php-http/discovery` plugin, add `"php-http/discovery": false` to the root `config.allow-plugins`. Implementations are required explicitly, so the plugin isn't needed.
- [ ] **Step 4:** Run the tests. They pass, and the full suite stays green (`vendor/bin/phpunit`).
- [ ] **Step 5:** Commit: `feat(ai): preflow/ai package with request, response, tuning and a fake client`.

### Task 2: Claude provider

**Files:**
- Create: `packages/ai/src/Providers/ClaudeClient.php`
- Test: `packages/ai/tests/Providers/ClaudeClientTest.php`

**Interfaces:**
- Consumes: Task 1.
- Produces: `new ClaudeClient(\Anthropic\Client $sdk, string $model = 'claude-sonnet-5-5')` implements `AiClient` (`provider()` is `'claude'`), and `ClaudeClient::fromKey(string $apiKey, string $model, ?\Psr\Http\Client\ClientInterface $http = null): self`.

The mapping is pinned by tests:
- The system prompt is `$request->tuning->system()`, sent as a text block with ephemeral cache control, so the prefix is cached across a batch. Omit it when the tuning is empty.
- The prompt is the single user message.
- `maxTokens` comes from the request.
- Temperature is not sent.
- When `$request->schema` is set, the answer is enforced with the SDK's structured-output support if this SDK version has it. Otherwise "Answer with JSON only, matching this schema: …" is appended to the system prompt.
- `stopReason` maps as follows:

  | `stopReason` | Maps to |
  |---|---|
  | `end_turn` | `Complete` |
  | `max_tokens` | `Truncated`, text null |
  | `refusal` | `Refused`, text null, detail = category |

- Usage: `inputTokens`, `outputTokens`, and the cache read tokens as `cachedInput`.
- Errors:
  - HTTP 401, 402 or 403, or a 400 whose message contains "credit balance", is `AiUnavailable`.
  - Any other status error, or a connection error after the SDK's retries, is `AiRequestFailed`. The message is "Claude returned HTTP {status}: {detail}", never containing the key.

**Never guess SDK names.** Read the SDK in `vendor/anthropic-ai/sdk` first, or use the working reference `crelish.spielegesellschaft/services/llm/ClaudeClient.php`, which uses `$client->messages->create(maxTokens:, messages:, model:)` (named args are camelCase; nested array keys keep the wire names). Check how its client takes a PSR-18 client and how system blocks, cache control and structured output are passed.

- [ ] **Step 1: Write failing tests.** Drive the real SDK with a PSR-18 stub that returns canned HTTP responses (nyholm `Response`) and records the outgoing request.
  - `test_sends_model_max_tokens_system_with_cache_control_and_user_prompt`: decode the recorded body; the system block text equals `tuning->system()` and carries cache control.
  - `test_omits_system_when_tuning_empty`.
  - `test_maps_end_turn_text_and_usage_including_cache_reads`.
  - `test_max_tokens_is_truncated_with_null_text`.
  - `test_refusal_is_refused_with_category`.
  - `test_401_402_403_and_credit_balance_400_throw_unavailable`: a data provider.
  - `test_500_after_retries_throws_request_failed_without_key_in_message`.
- [ ] **Step 2:** Run the tests and confirm they fail.
- [ ] **Step 3:** Implement `ClaudeClient`.
- [ ] **Step 4:** Run the tests and confirm they pass.
- [ ] **Step 5:** Add an opt-in smoke test, `packages/ai/tests/Providers/LiveProvidersTest.php::test_claude_answers_json`, skipped unless `ANTHROPIC_API_KEY` is set. It asks for `{"ok":true}`.
- [ ] **Step 6:** Commit: `feat(ai): Claude provider`.

### Task 3: Gemini provider

**Files:**
- Create: `packages/ai/src/Providers/GeminiClient.php`
- Test: `packages/ai/tests/Providers/GeminiClientTest.php`; add `test_gemini_answers_json` to `LiveProvidersTest` (skipped without `GEMINI_API_KEY`).

**Interfaces:**
- Produces: `new GeminiClient(string $apiKey, string $model = 'gemini-2.5-flash', ?ClientInterface $http = null, ?RequestFactoryInterface $requests = null, ?StreamFactoryInterface $streams = null)`. The defaults are symfony `Psr18Client` and nyholm `Psr17Factory`. `provider()` is `'gemini'`.

The REST call:
- `POST https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent`, with header `x-goog-api-key: {key}` (the key never goes in the URL).
- Body:
  - `systemInstruction.parts[0].text`: the tuning (omitted when empty);
  - `contents[0].parts[0].text`: the prompt;
  - `generationConfig`: `maxOutputTokens`, `temperature` (when set), and `responseMimeType: application/json` plus `responseSchema` when a schema is set.

Response mapping (`finishReason`):

| `finishReason` | Maps to |
|---|---|
| `STOP` | `Complete` |
| `MAX_TOKENS` | `Truncated` |
| `RECITATION`, `SAFETY`, `BLOCKLIST`, `PROHIBITED_CONTENT`, or a `promptFeedback.blockReason` | `Blocked`, detail = the reason |

- Usage comes from `usageMetadata`: `promptTokenCount` is input; `candidatesTokenCount` plus `thoughtsTokenCount` is output; `cachedContentTokenCount` is cached input.
- Errors:
  - 401, 402 or 403, or a 400 whose body contains `API_KEY_INVALID`, is `AiUnavailable`.
  - 429 and 5xx get one retry after 2 seconds (the sleeper is injectable for tests), then `AiRequestFailed`. Other errors are `AiRequestFailed`.

- [ ] **Step 1: Write failing tests.** Use a recording PSR-18 stub.
  - `test_request_url_header_body`: the key is in the header and not in the URL; the schema is passed as `responseSchema`.
  - `test_finish_reasons_map`: a data provider covering the table above.
  - `test_usage_counts_thoughts_as_output`.
  - `test_unavailable_statuses`.
  - `test_retries_once_on_429_then_fails`: the injected sleeper records `[2]`.
- [ ] **Step 2:** Run the tests and confirm they fail.
- [ ] **Step 3:** Implement `GeminiClient`.
- [ ] **Step 4:** Run the tests and confirm they pass.
- [ ] **Step 5:** Commit: `feat(ai): Gemini provider`.

### Task 4: Wiring — config, `Ai` service, usage log, pricing, settings store, commands

**Files:**
- Create in `packages/ai/src/`:
  - `Ai.php`, `AiStatus.php`, `AiServiceProvider.php`, `LoggedAiClient.php`;
  - `Usage/UsageLog.php` (interface), `Usage/FileUsageLog.php`, `Usage/UsageEntry.php`, `Usage/Pricing.php`;
  - `Settings/AiSettingsStore.php` (interface), `Settings/FileAiSettingsStore.php`;
  - `Console/AiCheckCommand.php`, `Console/AiUsageCommand.php`.
- Create: `packages/ai/config/ai.php`, the documented default config that the README shows.
- Modify: `packages/folio/src/FolioServiceProvider.php`. Register `AiServiceProvider` when it isn't registered yet, the same way `MediaServiceProvider` is registered (lines 57-58).
- Test: `packages/ai/tests/AiTest.php`, `Usage/FileUsageLogTest.php`, `Usage/PricingTest.php`, `Settings/FileAiSettingsStoreTest.php`, `AiServiceProviderTest.php`.

**Interfaces (Produces):**
```php
final class Ai {
    /** @param array<string,mixed> $config config/ai.php; $env reads an env var (tests inject) */
    public function __construct(array $config, UsageLog $usage, Pricing $pricing, ?\Closure $env = null, ?\Closure $clientFactory = null);
    public function enabled(): bool;             // config enabled === true AND key for the provider present
    public function status(): AiStatus;          // enabled, provider, model, keyPresent (bool) — never the key
    public function client(): AiClient;          // LoggedAiClient around the provider client; @throws AiUnavailable when !enabled()
    public function pricing(): Pricing;
}
final class LoggedAiClient implements AiClient { /* records a UsageEntry after every complete(), also when it throws AiRequestFailed with no usage (0 tokens) */ }
final readonly class UsageEntry { public function __construct(public \DateTimeImmutable $at, public string $purpose, public string $provider, public string $model, public TokenUsage $usage, public ?float $costUsd, public bool $failed = false) {} }
interface UsageLog { public function record(UsageEntry $e): void; /** @return list<UsageEntry> */ public function between(\DateTimeImmutable $from, \DateTimeImmutable $to): array; }
final class Pricing { public function __construct(array $perMillion /* model => [input, output] */); public function cost(string $model, TokenUsage $u): ?float; /* cached input billed at 10% of input */ public function has(string $model): bool; }
interface AiSettingsStore { public function get(string $section): array; public function put(string $section, array $data): void; }  // sections: 'tuning', 'glossary'
```

The `config/ai.php` keys are pinned:
- `enabled`: false;
- `provider`: `'claude'`;
- `models`: `['claude' => 'claude-sonnet-5-5', 'gemini' => 'gemini-2.5-flash']`;
- `keys`: `['claude' => 'ANTHROPIC_API_KEY', 'gemini' => 'GEMINI_API_KEY']`;
- `pricing`: the Global Constraints table, merged over the defaults;
- `storage`: `'storage/ai'`;
- `tuning`: project-level instructions, a list of strings;
- `translation`: `['languages' => [locale => list<string>], 'types' => ['{type}' => list<string>], 'glossary' => list<array{term:string, translations: array<string, string|null>, types?: list<string>, note?: string}>, 'batch_chars' => 12000]`. A translation value of `null` means "keep as is". Field- and kind-level defaults are not here: they live in the model JSON field config (`"ai": {"tuning": "…", "kinds": {"quiz-option": "…"}, "translate": false}`), as the spec says.

`AiServiceProvider` binds `Ai`, `UsageLog`, `Pricing` and `AiSettingsStore` as singletons. It reads `config/ai.php` (an absent file means AI is off), registers both commands, and adds the `ai` translations as `addDefaultsIfMissing('ai', …, 'admin')`.

The commands:
- `ai:check` prints the status and sends one tiny request, asking for JSON `{"ok":true}`. Exit codes: 0 when OK, 1 when off or unavailable.
- `ai:usage [--month=YYYY-MM]` prints calls, tokens and cost per purpose and model.

- [ ] **Step 1: Write failing tests.**
  - `AiTest`:
    - `test_disabled_without_flag_or_key`: four combinations; only flag + key is enabled.
    - `test_status_never_contains_key`: serialize the status; it doesn't contain the key string.
    - `test_client_throws_unavailable_when_disabled`.
    - `test_client_is_logged_and_records_usage_with_cost`: uses a client factory returning `FakeAiClient`.
  - `FileUsageLogTest`:
    - `test_appends_jsonl_per_month_and_reads_range`;
    - `test_no_prompt_or_text_in_file`.
  - `PricingTest`:
    - `test_cost_for_known_model`: sonnet, 1M input + 1M output = 12.0;
    - `test_unknown_model_is_null`;
    - `test_cached_input_at_ten_percent`.
  - `FileAiSettingsStoreTest`:
    - `test_put_get_roundtrip_and_missing_section_is_empty`;
    - `test_atomic_write`: written through a temp file and rename.
  - `AiServiceProviderTest`:
    - `test_absent_config_means_disabled`;
    - `test_commands_registered`.
- [ ] **Step 2:** Run the tests and confirm they fail.
- [ ] **Step 3:** Implement everything above.
- [ ] **Step 4:** Run the tests and confirm they pass; the full suite stays green.
- [ ] **Step 5:** Commit: `feat(ai): config, Ai service, usage log, pricing, settings store, ai:check and ai:usage`.

### Task 5: i18n machine translation — units, markup check, glossary

**Files:**
- Modify: `packages/i18n/composer.json`. Require `preflow/ai ^0.47 || @dev`.
- Create in `packages/i18n/src/Machine/`:
  - `TextFormat.php`, `TranslationUnit.php`, `TranslationResult.php`, `UnitStatus.php`, `Scope.php`;
  - `MarkupCheck.php`, `Glossary.php`, `GlossaryEntry.php`.
- Test: `packages/i18n/tests/Machine/MarkupCheckTest.php`, `GlossaryTest.php`.

**Interfaces (Produces):**
```php
enum TextFormat: string { case Plain = 'plain'; case Html = 'html'; case Markdown = 'markdown'; }
enum Scope: string { case Needed = 'needed'; case All = 'all'; }   // needed = missing + outdated
enum UnitStatus: string { case Translated = 'translated'; case Failed = 'failed'; }
final readonly class TranslationUnit {
    /** @param array<string,string> $context e.g. ['type' => 'Game', 'record' => 'Cascadia'] */
    public function __construct(
        public string $id,             // the path, unique within one item
        public string $label,          // "Step 3 › Option B"
        public string $source,
        public string $to,
        public string $from,
        public TextFormat $format = TextFormat::Plain,
        public ?string $kind = null,
        public ?string $previousSource = null,   // outdated: the source the current translation was made from
        public ?string $current = null,          // outdated/redo: the current translation
        public array $context = [],
        public ?string $tuning = null,           // field/kind-level instructions, already resolved by the source
        public ?string $type = null,             // collection key, for glossary type limits
    ) {}
}
final readonly class TranslationResult {
    /** @param list<string> $warnings e.g. 'glossary:Worker Placement' */
    public function __construct(public string $id, public UnitStatus $status, public ?string $text, public array $warnings = [], public ?string $error = null) {}
}
final class MarkupCheck { public static function matches(string $source, string $result, TextFormat $format): bool; }
final readonly class GlossaryEntry { public function __construct(public string $term, public array $translations /* locale => string|null(keep) */, public array $types = [], public ?string $note = null) {} }
final class Glossary {
    /** @param list<GlossaryEntry> $entries */ public function __construct(array $entries);
    /** Entries whose term occurs (case-insensitive, whole words) in any unit's source, limited to the unit type when the entry has types, and having a rule for $to. @param list<TranslationUnit> $units @return list<GlossaryEntry> */
    public function relevant(array $units, string $to): array;
    /** Terms of relevant entries whose required rendering (the term itself when "keep") is absent from $result (case-insensitive). @return list<string> */
    public function mismatches(TranslationUnit $unit, string $result): array;
    /** @return list<array<string,mixed>> */ public function toArray(): array; public static function fromArray(array $rows): self;
}
```

The `MarkupCheck` rules:
- **Html:** the sequence of tag names (opening and closing, lowercased, ignoring attributes and self-closing slashes) must be equal.
- **All formats:** the multiset of placeholders must be equal. Placeholders are `{name}`, `{0}` and `:name` (regex `(?<![\w:]):[A-Za-z_]\w*`).
- **Plain:** a result containing a tag (`<[a-z][^>]*>`) fails when the source contains none.
- **Markdown:** placeholders only.

- [ ] **Step 1: Write failing tests.**
  - `MarkupCheckTest`:
    - `test_html_same_tags_different_attributes_ok`: `<a href="x">A</a>` against `<a class="y" href="z">B</a>`.
    - `test_html_dropped_tag_fails`.
    - `test_trix_div_br_kept`.
    - `test_placeholders_multiset`: `{count} :name` against `:name {count}` passes; a missing `:name` fails; "Note: 10:30" holds no placeholder.
    - `test_plain_result_with_tag_fails`.
  - `GlossaryTest`:
    - `test_relevant_whole_word_case_insensitive`: "worker placement" matches "Worker Placement"; "Workers" doesn't match "Worker".
    - `test_type_limited_entry_only_for_that_type`.
    - `test_keep_rule_requires_term_itself`.
    - `test_mismatch_lists_missing_rendering`.
    - `test_roundtrip_array`.
- [ ] **Step 2:** Run the tests and confirm they fail.
- [ ] **Step 3:** Implement everything above.
- [ ] **Step 4:** Run the tests and confirm they pass.
- [ ] **Step 5:** Commit: `feat(i18n): translation units, markup check and glossary`.

### Task 6: Translation settings — layered tuning and glossary from config plus stored edits

**Files:**
- Create: `packages/i18n/src/Machine/TranslationSettings.php`
- Test: `packages/i18n/tests/Machine/TranslationSettingsTest.php`

**Interfaces:**
- Consumes: `AiSettingsStore` and the `config/ai.php` arrays (Task 4); `Tuning`; `Glossary` (Task 5).
- Produces:
```php
final class TranslationSettings {
    public function __construct(array $aiConfig, AiSettingsStore $store);
    /** System levels: project → language($to) → "{type}"; each level: a stored edit replaces the config default of the same level */
    public function tuning(string $to, ?string $type = null): Tuning;
    /** Unit levels: "{type}.{field}" then "{type}.{field}:{kind}"; defaults from $fieldAi (model JSON field config "ai": tuning / kinds), stored edits replace per level; null when empty */
    public function unitTuning(string $type, string $field, ?string $kind, array $fieldAi = []): ?string;
    public function glossary(): Glossary;               // stored 'glossary' section when non-empty, else config
    /** @return array<string, list<string>> every level key => instructions, merged view for the Settings screen */
    public function levels(): array;
    public function saveLevel(string $levelKey, list<string> $instructions): void;  // keys: 'project', 'lang:{locale}', '{type}', '{type}.{field}', '{type}.{field}:{kind}'
    public function saveGlossary(Glossary $g): void;
}
```

- [ ] **Step 1: Write failing tests.**
  - `test_levels_merge_in_order`: project A, lang:de B, game C give `['A','B','C']`.
  - `test_unit_tuning_field_then_kind_from_model_json_and_stored_override`: `$fieldAi = ['tuning' => 'D', 'kinds' => ['quiz-option' => 'E']]` gives "D\nE"; a stored `game.flow` level "X" gives "X\nE".
  - `test_stored_level_replaces_config_level_only`.
  - `test_other_language_not_included`.
  - `test_glossary_stored_wins_over_config`.
  - `test_save_level_empty_list_removes_stored_override`.
- [ ] **Step 2:** Run the tests and confirm they fail.
- [ ] **Step 3:** Implement `TranslationSettings`.
- [ ] **Step 4:** Run the tests and confirm they pass.
- [ ] **Step 5:** Commit: `feat(i18n): layered translation tuning and glossary settings`.

### Task 7: `AiTranslator` — prompts, batching, split-retry, validation, estimate

**Files:**
- Create in `packages/i18n/src/Machine/`: `MachineTranslator.php` (interface), `AiTranslator.php`, `Estimator.php`, `Estimate.php`.
- Modify: `packages/i18n/src/` service registration (wherever i18n binds services; else `AiServiceProvider` binds `MachineTranslator` when i18n is present). Bind `MachineTranslator` and `Estimator`.
- Test: `packages/i18n/tests/Machine/AiTranslatorTest.php`, `EstimatorTest.php`.

**Interfaces (Produces):**
```php
interface MachineTranslator {
    /** One text on the fly. @throws AiUnavailable|TranslationFailed */
    public function translate(string $text, string $to, ?Tuning $tuning = null, ?string $from = null, TextFormat $format = TextFormat::Plain): string;
    /** @param list<TranslationUnit> $units @return list<TranslationResult> one per unit, same order; @throws AiUnavailable (only that) */
    public function translateUnits(array $units, ?string $note = null): array;
}
final class AiTranslator implements MachineTranslator { public function __construct(Ai $ai, TranslationSettings $settings, int $batchChars = 12000); }
final readonly class Estimate { public function __construct(public int $units, public int $characters, public int $inputTokens, public int $outputTokens, public ?float $costUsd, public int $requests) {} }
final class Estimator { public function __construct(Ai $ai, int $batchChars = 12000); /** @param list<TranslationUnit> $units */ public function estimate(array $units): Estimate; }
// TranslationFailed extends \RuntimeException, in Machine/.
```

The behaviour is pinned by the tests:
- **Grouping:** units are grouped by `(from, to, type)`. Within a group, units are packed into requests until the sum of `source` + `current` + `previousSource` lengths would exceed `batchChars`. A single unit over the budget goes alone.
- **The system tuning** is `settings->tuning(to, type)`, merged with the general translation rules:
  - keep HTML and placeholders exactly;
  - never translate proper names, brand names or game names;
  - answer only with the JSON;
  - for items with `previous` and `current`, adapt `current` to the change from `previous` to `source` and keep its wording elsewhere.

  Then the relevant glossary entries are added as lines `"{term}" → "{rendering}"` or `"{term}" → keep as is (+ note)`, and finally the one-off note.
- **The prompt** holds the items as JSON `[{"n":1,"text":…,"format"?:…,"label":…,"kind"?:…,"previous"?:…,"current"?:…,"hint"?:unit tuning}]`. `n` is the 1-based position in the request. The context goes in a header line.
- **The schema** is `{"type":"array","items":{"type":"object","properties":{"n":{"type":"integer"},"text":{"type":"string"}},"required":["n","text"]}}`.
- **Parsing:** results map by `n`. A missing `n` fails that unit ("no answer"); unknown or duplicate `n` are ignored after the first. A non-array answer fails the whole request.
- **A result that fails** `MarkupCheck` is Failed ("markup changed"). Glossary mismatches become warnings `glossary:{term}`.
- **A request answered `Blocked`, `Truncated` or `Refused`, or throwing `AiRequestFailed`,** is split in halves and retried, recursively, down to single units. A single unit that still fails is Failed with the reason.
- **Gemini's `Blocked` with detail `RECITATION`** on a single unit is retried once with temperature 1.0 and the added instruction "Word it freely in your own words."
- **`AiUnavailable`** propagates immediately.
- **`translate()`** wraps one unit and throws `TranslationFailed` when the unit fails.
- **Estimates:** tokens are `ceil(chars / 4)`.
  - Input per request: the system prompt size + items + 200 overhead.
  - Output: `1.3 × source tokens`.
  - Cost comes from `Ai::pricing()` for the configured model, null when unknown.
  - `requests` is the batch count.

- [ ] **Step 1: Write failing tests** with `FakeAiClient` and an `Ai` built with a client factory.
  - `test_maps_results_by_n_not_order`: the reply is reordered.
  - `test_missing_item_fails_only_that_unit_extra_ignored`.
  - `test_markup_violation_fails_unit`.
  - `test_glossary_terms_in_system_and_mismatch_warning`: assert on `requests()[0]->tuning->system()`.
  - `test_outdated_unit_sends_previous_and_current`.
  - `test_batches_split_by_char_budget`: 300 units of 100 chars at `batchChars` 12000 make `ceil(30000/12000) = 3` requests at most… assert every request's item chars are ≤ 12000 and every unit is answered once.
  - `test_blocked_request_halves_down_to_single_unit`: script `Blocked` for any request containing unit 3; only unit 3 fails.
  - `test_recitation_single_unit_retried_at_temperature_one`.
  - `test_unavailable_propagates`.
  - `test_translate_single_text_and_failure_throws`.
  - `EstimatorTest::test_counts_tokens_requests_and_cost`.
  - `EstimatorTest::test_unknown_model_cost_null`.
- [ ] **Step 2:** Run the tests and confirm they fail.
- [ ] **Step 3:** Implement everything above. Keep the prompt text in one private method so tests can read it, and assert on the key phrases only.
- [ ] **Step 4:** Run the tests and confirm they pass.
- [ ] **Step 5:** Commit: `feat(i18n): AiTranslator with batching, split retry, markup and glossary checks, estimates`.

### Task 8: `TextSource`, `JobRunner`, machine marks

**Files:**
- Create in `packages/i18n/src/Machine/`:
  - `TextSource.php`, `TextSources.php`, `WriteOutcome.php`, `MachineMarks.php`;
  - `Job.php`, `JobStore.php` (interface), `FileJobStore.php`, `JobRunner.php`.
- Test: `packages/i18n/tests/Machine/JobRunnerTest.php`, `MachineMarksTest.php`, `FileJobStoreTest.php`, plus an in-memory `ArrayTextSource` under `tests/Machine/Support/`.

**Interfaces (Produces):**
```php
interface TextSource {
    public function collection(): string;                  // e.g. Folio type key
    public function label(): string;                       // "Games"
    /** @return list<string> */ public function items(): array;
    public function title(string $id): string;
    /** Units of one item for $to: Needed = missing + outdated (+ previousSource/current), All = every text with a source. Opted-out fields never appear. @return list<TranslationUnit> */
    public function units(string $id, string $to, Scope $scope): array;
    /** Write texts (unit id => text) for $to as machine-made (marks them unchecked). Never publishes. */
    public function write(string $id, string $to, array $texts): WriteOutcome;
    public function writesLive(): bool;                    // true when a write is public right away (no publishing)
}
enum WriteOutcomeStatus: string { case Written = 'written'; case Conflict = 'conflict'; case Skipped = 'skipped'; }
final readonly class WriteOutcome { public function __construct(public WriteOutcomeStatus $status, public ?string $message = null, public bool $hadDraftChanges = false) {} }
final class TextSources { public function add(string $collection, \Closure $factory /* fn(): TextSource */): void; public function get(string $collection): TextSource; public function has(string $c): bool; /** @return list<string> */ public function collections(): array; }
final class MachineMarks {   // pure; storage is the source's business. Shape: {locale: {path: fingerprint-of-AI-text}}
    public static function mark(array $marks, string $locale, string $path, string $text): array;
    public static function clear(array $marks, string $locale, ?string $pathPrefix = null): array;
    public static function isUnchecked(array $marks, string $locale, string $path, ?string $current): bool;  // mark exists AND fingerprint(current) === mark
    public static function prune(array $marks, callable $currentText /* fn(locale, path): ?string */): array;  // drops marks whose text changed or vanished
    public static function fingerprint(?string $text): string;
}
final class Job {   // persisted as JSON
    public string $id; public string $collection; public array $locales; public Scope $scope; public ?string $note;
    public string $state;            // 'planned' | 'running' | 'stopped' | 'done'
    public array $items;             // id => ['status' => 'pending'|'done'|'failed'|'conflict'|'skipped', 'translated' => int, 'failed' => int, 'warnings' => int, 'message' => ?string, 'draft_changes' => bool]
    public Estimate $estimate; public float $costUsd;   // actual, summed from usage of this job's requests
}
interface JobStore { public function save(Job $j): void; public function get(string $id): ?Job; }
final class JobRunner {
    public function __construct(TextSources $sources, MachineTranslator $translator, Estimator $estimator, JobStore $store);
    /** @param list<string>|null $ids null = whole collection */
    public function plan(string $collection, array $locales, Scope $scope, ?array $ids = null, ?string $note = null): Job;  // computes units for the estimate, writes nothing
    public function step(string $jobId, int $maxItems = 1): Job;   // processes the next pending item(s) for all locales; AiUnavailable → state 'stopped' with message, rethrown
    public function stop(string $jobId): Job;
}
```

`step` processes one item at a time:
- It gets the units for each locale, translates them, and writes back only the Translated ones.
- `Conflict` leaves the item marked `conflict` with the message.
- Items with no units become `skipped`, with the message "nothing to translate".
- The job state becomes `done` when no item is pending.
- The actual cost is summed from `LoggedAiClient` usage. Expose a per-call cost hook, or compute it from the response usage through `Pricing`; the implementer chooses, and the test pins the sum.

- [ ] **Step 1: Write failing tests.**
  - `MachineMarksTest`:
    - `test_unchecked_until_text_changes`;
    - `test_clear_by_prefix`;
    - `test_prune_drops_changed_and_vanished`.
  - `FileJobStoreTest::test_roundtrip`.
  - `JobRunnerTest` (`ArrayTextSource` + `FakeAiClient`):
    - `test_plan_estimates_without_writing`;
    - `test_step_translates_one_item_per_call_and_finishes`;
    - `test_conflict_reported_and_run_continues`;
    - `test_failed_units_not_written_item_counts_failed`;
    - `test_unavailable_stops_job_and_keeps_done_items`;
    - `test_stop_then_step_does_nothing`;
    - `test_item_with_no_units_skipped`.
- [ ] **Step 2:** Run the tests and confirm they fail.
- [ ] **Step 3:** Implement everything above. Bind `TextSources` (singleton), `JobStore` (`FileJobStore` at `{storage}/jobs`) and `JobRunner` next to `MachineTranslator`.
- [ ] **Step 4:** Run the tests and confirm they pass.
- [ ] **Step 5:** Commit: `feat(i18n): text sources, translation jobs and machine marks`.

### Task 9: Folio — source texts, previous source, "not checked" marker in status and form saves

**Files:**
- Modify: `packages/folio/composer.json` (require `preflow/ai`).
- Modify `packages/folio/src/Content/`:
  - `TranslationStatus.php`;
  - `TranslatableText.php`: add `public ?string $kind = null, public string $format = 'plain'` as trailing constructor parameters. `within()` keeps them.
  - `DraftRecordFactory.php`.
- Modify: `packages/folio/src/Storage/TableSync.php`. Declare the two new system keys exactly the way `TranslationStatus::KEY` is declared there.
- Test: `packages/folio/tests/Content/TranslationStatusTest.php` (extend), `DraftRecordFactoryTest.php` (extend).

**Interfaces:**
- Produces, on `TranslationStatus`:
```php
public const string SOURCES = '_translated_source';  // {locale: {path: default text the translation was made from}}
public const string MACHINE = '_machine_translated'; // MachineMarks shape
/** @return list<TranslatableText> texts of $locale with an unchecked machine mark */
public function unchecked(TypeDefinition $def, DynamicRecord $record, string $locale): array;
public function previousSource(DynamicRecord $record, string $locale, string $path): ?string;  // from SOURCES only
/** Returns a copy with machine marks set for $paths in $locale (their current texts). */
public function markMachine(TypeDefinition $def, DynamicRecord $record, string $locale, array $paths): DynamicRecord;
```

Changes to existing behaviour:
- `stamp()` also writes `SOURCES[locale][path]` to the default text wherever it writes a fingerprint. It removes the source where it removes a fingerprint, prunes vanished paths the same way, and prunes `MACHINE` with `MachineMarks::prune`.
- `confirm()` also sets `SOURCES` and clears `MACHINE` for the confirmed paths.
- `TranslationStatus::texts()` sets `format: 'html'` for `RichTextFieldType`.
- `DraftRecordFactory::build()` reads the posted `_ai_unchecked[]` (paths). After stamping, it applies `markMachine` for those paths in the saved locale. Paths that don't exist are ignored.

- [ ] **Step 1: Write failing tests** (in the existing test files).
  - `test_stamp_stores_source_text_next_to_fingerprint`.
  - `test_confirm_sets_source_and_clears_machine_marks`.
  - `test_unchecked_until_edited`: mark, then a re-save with the same text is still unchecked; a save with changed text clears it.
  - `test_resave_without_change_keeps_mark` (Review Focus 6).
  - `test_rich_text_reported_as_html`.
  - `test_posted_ai_unchecked_paths_marked_on_build`.
  - `test_new_keys_persist_on_sql`: through the existing SQLite scaffold used for `_translated_from`.
- [ ] **Step 2:** Run `vendor/bin/phpunit packages/folio/tests/Content` and confirm the tests fail.
- [ ] **Step 3:** Implement the changes above.
- [ ] **Step 4:** Run the tests and confirm they pass; run the full suite and `composer test:mysql`.
- [ ] **Step 5:** Commit: `feat(folio): store translation sources and mark machine-made texts as not checked`.

### Task 10: Folio — write-back contract and `FolioTextSource`

**Files:**
- Create:
  - `packages/folio/src/Field/AcceptsTranslatedTexts.php`;
  - `packages/folio/src/Content/FolioTextSource.php`.
- Modify:
  - `packages/folio/src/Field/Types/BlocksFieldType.php`: implement the new contract;
  - `packages/folio/src/FolioServiceProvider.php`: at boot, register one `TextSources` entry per multilingual type in `TypeCatalog`.
- Test: `packages/folio/tests/Content/FolioTextSourceTest.php`, `packages/folio/tests/Field/BlocksTranslatedTextsTest.php`.

**Interfaces:**
- Consumes: Task 8's `TextSource`, `WriteOutcome` and `TranslationUnit`; Task 9's `markMachine` and `SOURCES`.
- Produces:
```php
interface AcceptsTranslatedTexts {
    /** The field value (fromStorage shape) with $texts (paths relative to the field) set for $locale; unknown paths ignored. */
    public function withTranslatedTexts(mixed $value, array $texts, string $locale, array $config, Locales $locales): mixed;
}
final class FolioTextSource implements TextSource {
    public function __construct(string $typeKey, /* TypeCatalog/DataManager, TranslationStatus, FieldTypeRegistry, ?Publisher, RecordLabeler, TranslationSettings */);
}
```

The rules:
- **Field `ai` config** comes from the model JSON field config: `"ai": {"translate": false}` excludes the field (all its paths). `unit->tuning` is `TranslationSettings::unitTuning($typeKey, $topLevelField, $text->kind, $fieldConfig['ai'] ?? [])`, and `unit->type` is the type key. `AiTranslator` adds the project, language and type levels itself.
- **Units:**
  - `id` is the TranslatableText path, and `label` is its label.
  - `format` and `kind` come from the TranslatableText.
  - `context` is `['type' => type label, 'record' => RecordLabeler title]`.
  - `Needed` means `missing() ∪ outdated()`. Outdated units carry `current`, plus `previousSource` from `TranslationStatus::previousSource()`, falling back to a fingerprint search over `Publisher::revisions()` (newest first); it stays null when nothing is found.
  - `All` means every text with a default value, carrying `current` when one exists.
- **Writing:**
  - Load the working copy (the Publisher working copy for publishing types).
  - Set each text: top-level translatable fields through `LocaleValue` maps; for structured fields, group by the field-name prefix and call `withTranslatedTexts` (skip fields that don't implement it).
  - `stamp(before, after, locale)`, then `markMachine` for all written paths.
  - Save with `Publisher::saveDraft($record, $loadedVersion)`. A `VersionConflict` is a `Conflict`.
  - Report `hadDraftChanges` when the working copy differed from the published snapshot before the write.
  - Types without publishing save through the repository the way `AdminController` does; `writesLive()` returns true for them.

- [ ] **Step 1: Write failing tests.**
  - `BlocksTranslatedTextsTest`:
    - `test_sets_leaf_in_locale_keeps_others`;
    - `test_nested_blocks`;
    - `test_unknown_path_ignored`.
  - `FolioTextSourceTest` (on the publishing scaffold):
    - `test_needed_units_missing_and_outdated_with_previous_source_and_current`;
    - `test_previous_source_from_revisions_when_sources_absent`;
    - `test_opted_out_field_excluded`;
    - `test_write_saves_draft_not_published_and_marks_unchecked`;
    - `test_version_conflict_is_conflict_outcome` (Review Focus 2): bump the version between load and save through a hook or a subclassed store;
    - `test_had_draft_changes_flag`;
    - `test_non_publishing_type_writes_live_flag`;
    - `test_registered_per_multilingual_type`.
- [ ] **Step 2:** Run the tests and confirm they fail.
- [ ] **Step 3:** Implement everything above.
- [ ] **Step 4:** Run the tests and confirm they pass; run the full suite.
- [ ] **Step 5:** Commit: `feat(folio): FolioTextSource and write-back for structured fields`.

### Task 11: Folio — "not checked" in lists, tabs, fields and publishing

**Files:**
- Modify:
  - `packages/folio/src/Http/AdminController.php`: the form model next to `outdated` (≈1237-1250), the list badges, and a list filter `unchecked`;
  - `packages/folio/templates/admin/form.twig`: a per-field note "not checked yet" plus an accept button posting to the existing confirm route with the field path;
  - the list template(s) that show the outdated/missing badges;
  - `packages/folio/src/Publishing/Publisher.php`;
  - `packages/folio/src/FolioServiceProvider.php`: read `publishing.checked_translations`;
  - `packages/folio/lang/{en,de}/folio.php`.
- Test: extend `packages/folio/tests/Publishing/` (the publisher tests) and the Http form/list tests where outdated badges are tested.

**Interfaces:**
- `Publisher::publish()` with `checked_translations: true` throws a `ValidationException` keyed `_unchecked.{locale}` with message `folio.publish.unchecked` (`:language`, `:texts`, the first 5 labels plus "(+N)", like `requireCompleteLanguages`).
- When false (the default), `publish()` succeeds. `Publisher::uncheckedNotice(DynamicRecord): ?string` returns the warning text the controller flashes after publishing.
- UI copy:

  | Key | en | de |
  |---|---|---|
  | `folio.form.unchecked` | "Machine-translated, not checked yet" | "Maschinell übersetzt, noch nicht geprüft" |
  | `folio.list.unchecked` (badge) | ":count not checked" | ":count ungeprüft" |
  | `folio.publish.unchecked_warning` | "Published with :count machine translations not checked yet (:language)." | "Veröffentlicht mit :count ungeprüften maschinellen Übersetzungen (:language)." |

- [ ] **Step 1: Write failing tests.**
  - `test_publish_refused_with_unchecked_when_setting_on`.
  - `test_publish_allowed_and_notice_when_off`.
  - `test_form_shows_unchecked_note_and_accept_button`.
  - `test_list_badge_and_filter_unchecked`.
  - `test_tab_count_includes_unchecked`.
- [ ] **Step 2:** Run the tests and confirm they fail.
- [ ] **Step 3:** Implement the changes above.
- [ ] **Step 4:** Run the tests and confirm they pass.
- [ ] **Step 5:** Commit: `feat(folio): show and enforce not-checked machine translations`.

### Task 12: Folio — Translate button, suggestion endpoint, side-by-side review

**Files:**
- Create:
  - `packages/folio/src/Http/AiTranslateController.php`;
  - `packages/folio/assets/admin/ai-translate.js` and its CSS in Folio's admin asset system (follow how `AdminAssets` registers admin JS/CSS);
  - `packages/folio/templates/admin/partials/ai-translate-dialog.twig`.
- Modify:
  - `packages/folio/src/Routing/FolioRoutes.php`;
  - `packages/folio/src/FolioServiceProvider.php`: bind the controller and declare the permissions `ai.translate` ("Use AI translation") and `ai.settings` ("AI settings") in `declarePermissions()`;
  - `packages/folio/templates/admin/form.twig`: the button in the language area, shown only when `ai_translate` is set in the view model;
  - `AdminController` sets `ai_translate` when: `Ai::enabled()`, the type is multilingual, the locale isn't the default, and the user has `ai.translate` and the type's edit permission;
  - lang files.
- Test: `packages/folio/tests/Http/AiTranslateControllerTest.php`, `packages/folio/tests/Http/AiTranslateFormTest.php`, and a node test `packages/folio/tests/js/ai-translate.test.mjs` if Folio has node tests (otherwise put the JS path mapping in a small pure module and test it with node like the BG Genius flow editor tests: `node --test`).

**Routes:**
- `POST {prefix}/{type}/{id}/_ai/estimate`, body `locale, scope, prefix?`. Returns JSON `{units, requests, costUsd}`.
- `POST {prefix}/{type}/{id}/_ai/suggest`, body `locale, scope, note?, prefix?`. Returns JSON:
  - `{texts: {path: {text, source, previous: current|null, warnings: []}}, failed: {path: reason}, costUsd}`.
  - `prefix` limits the result to paths under it; BG Genius uses `flow`.
  - It never writes. When AI is off, unknown routes answer 404; without the permission they answer 403. `AiUnavailable` answers 503 with the message.

**Form JavaScript (pinned behaviour):**
- The button opens the dialog: scope (radio: "Missing and outdated" / "All"), a note, and the estimate (fetched on open).
- On Translate it fills every returned path's input in the current form. The name mapping is path `a.b.c` → `a[b][c]`. Trix editors are filled via `editor.loadHTML`, textareas and inputs by value plus an `input` event.
- Under each filled field it shows a review row: source, AI text (the input itself), the previous translation with a word-level diff, warnings, and the buttons Accept and Discard (Discard restores the previous value). "Accept all" is in the dialog footer.
- Before submit, every filled and not-accepted path whose value still equals the AI text gets a hidden `_ai_unchecked[]`.
- Unsaved changes trigger the existing autosave like any typing (no special case).

- [ ] **Step 1: Write failing tests.**
  - PHP:
    - `test_routes_404_when_ai_off` (Review Focus 3);
    - `test_form_renders_unchanged_when_ai_off`: the markup has no button;
    - `test_403_without_permission`;
    - `test_suggest_returns_texts_without_writing`: the record version is unchanged;
    - `test_prefix_limits_paths`;
    - `test_unavailable_503`;
    - `test_estimate`.
  - JS:
    - `path_to_name`;
    - `unchecked_hidden_inputs_only_for_unaccepted_unchanged`.
- [ ] **Step 2:** Run the tests and confirm they fail.
- [ ] **Step 3:** Implement everything above.
- [ ] **Step 4:** Run the tests and confirm they pass. Then check by hand in a local site with `FakeAiClient` bound (document the steps in the README "Trying it without a key"): open a German tab, click Translate, review, save, and see the unchecked note.
- [ ] **Step 5:** Commit: `feat(folio): Translate button with review on the edit form`.

### Task 13: Folio — whole-type run screen

**Files:**
- Modify:
  - `AiTranslateController.php`;
  - `FolioRoutes.php`;
  - the list template: a bulk-bar button "Translate…" and a header link "Translate all…", both shown under the same conditions as Task 12 (with the type's edit permission);
  - lang files.
- Create: `packages/folio/templates/admin/ai/run.twig`, `packages/folio/templates/admin/ai/run-progress.twig`.
- Test: `packages/folio/tests/Http/AiRunTest.php`.

**Routes:**
- `GET {prefix}/{type}/_ai/run?ids[]=…` shows the form: target languages (checkboxes, non-default), scope, note.
- `POST {prefix}/{type}/_ai/run` with `step=estimate` plans the job (`JobRunner::plan`) and shows the summary: "N records, M texts, K requests, about $X". It includes the live-write confirmation checkbox when `writesLive()`; Start is disabled until that box is ticked.
- `POST {prefix}/_ai/jobs/{id}/start` sets the state to `running` and renders the progress partial.
- `POST {prefix}/_ai/jobs/{id}/step` runs `JobRunner::step($id, 1)` and returns the progress partial. While the job is running, the partial carries `hx-post` with `hx-trigger="load delay:300ms"` to the step route.
- `POST {prefix}/_ai/jobs/{id}/stop`.

The progress partial shows done/total, actual cost, per-item status, failed and conflict items with their messages, and the records with `draft_changes` listed separately. It ends with "Review: N records with unchecked texts" linking to the list filter `unchecked`.

- [ ] **Step 1: Write failing tests.**
  - `test_estimate_shows_counts_and_cost`;
  - `test_live_type_requires_confirmation`;
  - `test_step_advances_one_record_and_progress_polls_until_done`;
  - `test_stop_keeps_done_records`;
  - `test_unavailable_shows_message_and_stops`;
  - `test_hidden_when_ai_off`.
- [ ] **Step 2:** Run the tests and confirm they fail.
- [ ] **Step 3:** Implement everything above.
- [ ] **Step 4:** Run the tests and confirm they pass.
- [ ] **Step 5:** Commit: `feat(folio): translate a whole type as drafts with estimate and progress`.

### Task 14: Folio — AI settings screens

**Files:**
- Create: `packages/folio/src/Http/AiSettingsController.php`, `packages/folio/templates/admin/ai/{status,tuning,glossary}.twig`.
- Modify:
  - `FolioRoutes.php`;
  - `FolioServiceProvider.php`: the nav entry under settings, permission `ai.settings`, shown only when `Ai::status()->enabled` or a key is present;
  - lang files.
- Test: `packages/folio/tests/Http/AiSettingsTest.php`.

**Screens:**
- **Status** (`GET {prefix}/ai`):
  - provider, model, key present yes/no, and the enabled flag;
  - a Test button (`POST {prefix}/ai/test`, the same check as `ai:check`);
  - usage for this and the last month per purpose and model (calls, tokens, cost).
- **Tuning** (`GET/POST {prefix}/ai/tuning`): one textarea per level key from `TranslationSettings::levels()` (project, each non-default language, each multilingual type, and each of its fields and kinds that have entries), plus "add a field level". Saving calls `saveLevel`. Config defaults show as placeholders.
- **Glossary** (`GET/POST {prefix}/ai/glossary`): rows of term, one cell per non-default language (text or a "keep" checkbox), types (multi-select), note; add and remove rows. Saving calls `saveGlossary`.

- [ ] **Step 1: Write failing tests.**
  - `test_status_never_renders_key`;
  - `test_tuning_save_and_reload`;
  - `test_glossary_save_keep_and_types`;
  - `test_requires_ai_settings_permission`;
  - `test_test_button_reports_ok_with_fake_client`.
- [ ] **Step 2:** Run the tests and confirm they fail.
- [ ] **Step 3:** Implement everything above.
- [ ] **Step 4:** Run the tests and confirm they pass.
- [ ] **Step 5:** Commit: `feat(folio): AI status, tuning and glossary screens`.

### Task 15: Folio — the button on Settings → Translations and on media alt texts

**Files:**
- Modify:
  - `packages/folio/src/Http/TranslationsController.php`;
  - `templates/admin/translations/index.twig`;
  - `packages/folio/templates/admin/assets/panel.twig` (alt and title inputs, line ≈97);
  - `AiTranslateController.php` (two suggest routes);
  - `FolioRoutes.php`;
  - `ai-translate.js`: the same fill-and-review, with no `_ai_unchecked`.
- Test: `packages/folio/tests/Http/AiLabelsAndMediaTest.php`.

**Routes:**
- `POST {prefix}/translations/_ai/suggest` with `locale, scope(needed|all)`. Units are the `EditableTexts` keys: Needed means keys whose override and default value for that locale are empty while the source locale has text; All means every key. The format is plain (or html when the key's source contains a tag). The id is the key.
- `POST {prefix}/assets/{id}/_ai/suggest` with `locale`. Units are `alt` and `title` from the default locale, when present.
- Both return the same JSON shape as Task 12 and never write. Permission: `ai.translate` plus `translations.edit` or the media edit permission respectively.

- [ ] **Step 1: Write failing tests.**
  - `test_labels_needed_only_empty_overrides`;
  - `test_labels_placeholders_preserved_or_failed`;
  - `test_media_alt_suggest`;
  - `test_never_writes`;
  - `test_hidden_and_404_when_ai_off`.
- [ ] **Step 2:** Run the tests and confirm they fail.
- [ ] **Step 3:** Implement everything above.
- [ ] **Step 4:** Run the tests and confirm they pass.
- [ ] **Step 5:** Commit: `feat(folio): AI translation for interface labels and media alt texts`.

### Task 16: Docs

**Files:**
- Modify: `packages/ai/README.md` (setup, config keys, providers, usage log, `ai:check`/`ai:usage`, `FakeAiClient`, "Trying it without a key").
- Modify: `packages/i18n/README.md` (`MachineTranslator`, `TranslationUnit`, glossary, tuning levels, `TextSource`/`JobRunner` for non-Folio data with a 20-line cron example).
- Modify: `packages/folio/README.md` (the field `ai` config, the button, whole-type runs, the unchecked marker, `publishing.checked_translations`, permissions).
- Modify: `ROADMAP.md` Next Up gets item 8: "AI connection and auto-translate (0.47)".
- Test: `vendor/bin/phpunit` (full) and `composer test:mysql`.

- [ ] **Step 1:** Write the docs.
- [ ] **Step 2:** Run the full suite and MySQL; both are green.
- [ ] **Step 3:** Commit: `docs: AI connection and auto-translate`.

The release (tag v0.47.0, create the `getpreflow/ai` split repository and the Packagist package, upgrade and deploy the three sites with AI left off) follows the usual release routine and needs the owner's go-ahead. It is not part of this plan's execution.

### Task 17 (BG Genius repo `/Users/smyr/Sites/gbits/bggenius-preflow`, after v0.47.0 is on Packagist or via a local path repository for testing)

**Files:**
- Modify:
  - `app/Folio/Fields/FlowFieldType.php`: implement `AcceptsTranslatedTexts`, and report `kind` on each `TranslatableText`;
  - `app/Flow/FlowTexts.php`: a pure `kind(string $path): string`;
  - `app/Folio/BgGenius/Screens/FlowEditor.php` (`translations()` accepts `unchecked: list<path>` and sets machine marks through `TranslationStatus::markMachine` with paths prefixed `flow.`);
  - `app/Folio/BgGenius/templates/flow-translate.twig` and `assets/flow-translate.js`: the Translate button, the dialog and the review. Call Folio's `POST /admin/game/{id}/_ai/suggest` with `prefix=flow`, strip `flow.` from the paths, and fill `.bgg-tr-row[data-path]`.
- Create: `config/ai.php` (enabled false until the key is set). Tuning:
  - project: "BG Genius teaches board games to players. Keep game names untranslated.";
  - `languages.de`: the two German rules verbatim from `crelish.spielegesellschaft/commands/TranslateController.php` `GAME_LANGUAGE_RULES['de']` (du; mechanic terms stay English);
  - in `config/models/game.json`, the `flow` field gets `"ai": {"kinds": {"quiz-option": "Keep answer options short; same length class as the source."}}`;
  - the glossary seeded with Worker Placement, Deckbuilding, Area Control, Engine Building and Push your Luck as `de: null` (keep).
- Modify: `composer.json` (`preflow/*` to `^0.47`), the constraint pin test (`tests/` ComposerConstraints-style test if present), and `.env` docs in `README.md`/`CLAUDE.md` (`ANTHROPIC_API_KEY`, `GEMINI_API_KEY`).
- Test: `tests/Flow/FlowTextsKindTest.php`, `tests/Folio/FlowTranslatedTextsTest.php`, and an extended node test for `flow-translate.js` (path fill and unchecked list).

The kinds are pinned by `FlowTexts::kind()`:

| Path pattern | Kind |
|---|---|
| `*.title` | `title` |
| `*.options.*.label` | `quiz-option` |
| `*.nodes.*.label`, `*.edges.*.label` | `diagram-label` |
| `glossary.*.term` | `glossary-term` |
| other `glossary.*` | `glossary-text` |
| anything else | `body` |

- [ ] **Step 1: Write failing tests.**
  - `test_kind_by_path`: one case per row of the table above.
  - `test_with_translated_texts_sets_translation_and_prunes_unknown`.
  - `test_flow_texts_report_kind`.
  - `test_translations_endpoint_marks_unchecked_paths`.
  - JS: `fills_rows_by_path`, `unchecked_only_unaccepted`.
- [ ] **Step 2:** Run `vendor/bin/phpunit` and `node --test` and confirm the new tests fail.
- [ ] **Step 3:** Implement everything above.
- [ ] **Step 4:** All tests pass. By hand: with a key in the local `.env` and `enabled` true, translate one game's flow into German, review, save, and see the unchecked count on the game list.
- [ ] **Step 5:** Commit: `feat: AI translation for flows`. Deploying needs the owner's go-ahead. On live, AI stays off until the owner adds the key to the shared `.env`; `storage/` is already a shared dir.
