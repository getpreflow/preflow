# AI connection and auto-translate — design

Date: 2026-10-30. Owner-approved direction from the brainstorming conversation; the calls marked
**(my call)** are defaults the owner did not decide explicitly and may change.

## Why

BG Genius has gone live on Folio in English. German is next, and translating 14 games' flows (hundreds
of step texts, quiz options, glossary entries) by hand is not realistic. Crelish had an "Auto-translate"
button (DeepL) on every edit form and auto-filled missing Yii message strings; Preflow has nothing. The
owner wants the same convenience, done better:

- one click on a translation form fills it with AI translations, the owner reviews and saves;
- whole types can be translated in one go, as drafts for review;
- the AI connection is general (translation is the first use, not the only one);
- every AI call can be tuned with instructions (tone, wording rules, answer template).

Crelish (`giantbits/yii2-crelish`: `CrelishTranslationService`, `CrelishBaseController` button,
`CrelishI18nEventHandler`) and spielegesellschaft's LLM pipeline (`services/llm/`,
`services/translation/`) are reference only. Do not port them; do better where possible.

Lessons kept from them:

- one provider switch (Gemini / Claude); a bad key or exhausted credit stops a whole run at once;
- texts go to the model as numbered JSON items, never keyed by long paths;
- a result whose HTML tags or `{placeholders}` differ from the source is thrown away;
- a cost summary after each run.

Mistakes not repeated:

- rules hard-coded in PHP;
- a second, separate pipeline per content kind;
- provider quirks leaking into callers;
- re-translating everything on every click;
- direct saving without review.

## Goals

1. A general AI connection in its own core package, usable without Folio.
2. Machine translation in core: a single text, a field, a record, a whole type. Only missing and outdated
   texts by default; "redo all" on request.
3. Tuning on every AI call, layered: project → language → type/field → one-off note.
4. A glossary as structured data, applied to every translation request and checked afterwards.
5. Folio adapter: a Translate button on the edit form's language tabs (fields, blocks, custom structured
   fields), side-by-side review, a whole-type bulk action with a cost estimate and visible progress,
   the same button on Settings → Translations for Folio labels, media alt texts.
6. "Not checked yet" marker for AI-written texts until a human accepts or edits them.
7. Opt-in at two levels: the project enables AI; a translatable field can opt out of auto-translate.
8. BG Genius: the flow translation screen gets the same button and review; whole-type runs cover flows.

## Non-goals

- Scheduled or automatic translation (cron). Projects build that on the core API.
- DeepL or other non-LLM engines in this round. The interface must allow adding one later.
- Writing translations while a page is being rendered (Crelish's missing-message handler). Missing labels
  are filled on Settings → Translations, by click.
- Migrating Crelish projects.
- Second step, designed for but not built now:
  - few-shot examples from already checked translations;
  - a second-pass quality check ("please look" marker);
  - a "new language" wizard.

## Anti-goals

- Anything reaching the public site without a human having had the chance to review it. AI output in a
  form is an unsaved change. A bulk run writes working copies of publishing types, never published
  snapshots, and never touches a type without publishing unless the owner confirms that explicitly
  in the run dialog.
- Destroying human work. An outdated text is updated from the owner's current translation, not replaced
  from scratch. A run never overwrites a text that is neither missing nor outdated, unless "redo all" is
  chosen.
- Surprise costs. No bulk run starts without showing its scope and estimated cost first.
- API keys in the database, in config files under git, or in logs.

## Architecture

The rule: everything lives in Folio-free package cores with public interfaces; Folio only adds UI (see
the media and members designs). BG Genius code stays in BG Genius.

### `preflow/ai` (new package)

General AI connection.

- `AiClient` interface: one request in, one response out.
  - The request carries:
    - system instructions (the tuning);
    - the user content;
    - an optional JSON schema for structured output;
    - an optional temperature and a max output size;
    - a cache hint marking the stable prefix (tuning, glossary) so Claude's prompt caching can be used
      across a batch (my call).
  - The response carries the text or decoded JSON, a stop reason (complete / blocked / truncated /
    refused), and token usage.
- Providers: Claude (official `anthropic-ai/sdk`) and Gemini (REST). There is no HTTP client in the
  framework yet; the builder picks one (PSR-18 via `symfony/http-client` is the obvious choice).
- Provider differences stay inside the providers: temperature support, recitation blocks, refusals, how
  JSON is enforced.
- Errors: `AiUnavailable` (bad key, no credit, provider down; a caller must stop) versus a per-request
  failure (a caller may continue). One retry with backoff for rate limits and 5xx inside the provider.
  A Gemini recitation block is reported as `blocked`, so callers can split the batch and retry.
- Config `config/ai.php`: `enabled` (default false), `provider`, `model` per provider, and the env var
  names for keys. Defaults: `claude-sonnet-5-5`, `gemini-2.5-flash`. Keys only from env.
- Usage log: every call records time, purpose (for example `translate`), provider, model, tokens and
  estimated cost. The price table lives in config and can be overridden by the project. Storage goes
  through an interface; the default store uses Preflow data storage (my call). The log must not hold
  request or response text.
- A console command for usage (`ai:usage`) and a connection test (`ai:check`) (my call).
- Usable for other things later (quiz suggestions, vibe texts); nothing in it knows about translation.

### Machine translation in `preflow/i18n`

The owner asked for this in the translation package.

- **(my call)** `preflow/i18n` requires `preflow/ai`. The package is small and AI is off by default, so
  "usable without AI" means usable with AI disabled, not without the package installed.
- `Tuning` lives in `preflow/ai`, as a general concept every AI command takes.
- The AI translator lives in i18n.

- `MachineTranslator` interface.
  - `translate(string $text, string $to, ?Tuning $tuning = null, ?string $from = null): string` for
    on-the-fly use.
  - A batch method over translation units.
  - The AI implementation sits on `AiClient`. A later DeepL implementation would implement the same
    interface and ignore what it cannot use.
- Translation unit:
  - id/path, label (for example "Step 3 › Option B"), format (plain / html / markdown);
  - the source text, the target language;
  - for outdated texts: the previous source text and the current translation. Today `_translated_from`
    holds only a fingerprint. **(my call)** From now on, the stamp also stores the source text the
    translation was made from (beside the fingerprint, additive, no migration). For records stamped
    before that, the previous text is looked up in revisions and published snapshots by fingerprint.
    If none is found, the unit goes without it, and the model only gets the new source and the current
    translation;
  - context (for example the type, the record title, a short hint from the field).
  - For an outdated unit the model is told to adapt the current translation to the change, keeping
    the owner's wording where the change does not touch it.
- Batching: units are grouped per request by size. A blocked or failed request is split and retried
  once in halves down to single units; a unit that still fails is reported as failed, never silently
  dropped. `AiUnavailable` aborts the whole job.
- Validation per unit:
  - HTML tag structure and `{placeholders}` / `:placeholders` must match the source;
  - plain-text units must not come back as HTML.
  - A violating result counts as failed.
- `Tuning`: an ordered list of instruction texts plus an optional answer template. It merges in this
  order, later levels appended (and winning where they conflict):
  1. project;
  2. target language;
  3. type;
  4. field;
  5. the one-off note.
- Tuning is a general `preflow/ai` concept (every AI command takes it). Translation contributes the
  language and type/field levels.
- Per-field tuning and the auto-translate opt-out live in the model JSON field config (versioned,
  for example `"ai": {"translate": false}` or `"ai": {"tuning": "…"}`). Stored edits from Settings can
  override the tuning, not the opt-out (my call).
- Structured fields (blocks, `flow`) may report a text kind with each text, for example `quiz-option`,
  `feedback`, `glossary-term`. Tuning can then target a kind inside a field, and the unit carries the
  kind (my call). The opt-out stays per top-level field.
- Glossary: one per project. An entry can optionally be limited to types (my call). Each entry has:
  - a source term;
  - per target language: the term to use, or "keep as is";
  - an optional note.
  - Only entries whose source term occurs in a batch are sent. After translation each unit is
    checked: a source term present but its required rendering missing marks the unit "glossary
    mismatch" (a warning shown in review, not a failure).
- Stores: tuning and glossary are read through an interface with two sources, project config
  defaults (versioned) and stored edits from the admin, stored edits winning.
- Cost estimate for a planned job: unit count, characters, estimated tokens and price, from the price
  table, before any call.

### Record level

Today `TranslationStatus` and `TranslatableText` live in `preflow/folio` (`Content/`), and so does
`ReportsTranslatableTexts`, because field types are Folio's.

- The record-level translator ("which texts of this record need translating, as units; write results
  back by path") belongs next to `TranslationStatus` in Folio's content core (`Preflow\Folio\Content`),
  not in a controller, so a project can call it from a console command or cron without Folio's UI.
- **(my call)** This does not move `TranslationStatus` out of Folio. Moving it to `preflow/data` would
  drag the field-type contracts with it, and that is a bigger refactor than this feature needs.
- Writing back by path for structured fields needs a counterpart to `ReportsTranslatableTexts`: a field
  type that reports texts must also accept translated texts by path (for blocks/matrix in Folio, for
  `flow` in BG Genius).
  - The write goes through the normal `DraftRecordFactory` / `Publisher::saveDraft` path, with the
    optimistic version check, and stamps fingerprints as a manual save does.
  - A version conflict skips that record and reports it.
- A whole-type job: list records → units per record → translate → write back. It runs record by record
  so a web UI can drive it in small requests. The job has a state (pending / done / failed / skipped per
  record) so it can be stopped and continued. Where that state is kept is the builder's call.

### "Not checked yet" marker

- When AI text is written into a record (bulk) or saved from the form without being accepted, the
  record stores per locale and path a fingerprint of the AI text, next to `_translated_from`
  (for example `_machine_translated`).
- A text counts as not checked while its current value still matches that fingerprint. Editing the
  text therefore clears it automatically. "Still correct" / accept clears it explicitly.
- `TranslationStatus` gains `unchecked()` beside `missing()` and `outdated()`.
- Shown wherever missing/outdated are shown: list badges, language tab counts, per field in the form,
  and a "not checked" filter in lists (my call).
- Publishing: warns by default; a setting (`publishing.checked_translations`, parallel to
  `complete_languages`) makes it a refusal (my call).

## Folio adapter

### Edit form

- The edit form gets a Translate button for each language tab except the default. This needs a small
  form-template hook, since there is none for that place today.
- Clicking it opens a small dialog:
  - scope: missing + outdated (default) or all;
  - an optional one-off note;
  - the estimated cost for this record.
- It then fills the form client-side and saves nothing.
- Review is side by side, per filled text:
  - the default-language text;
  - the AI text, editable in place;
  - the previous translation, with changes highlighted, when there was one;
  - glossary-mismatch and validation warnings.
- Actions per text: accept, edit, discard. "Accept all" is available.
- On save, accepted and edited texts count as checked; the rest are saved with the "not checked" marker.
  **(my call)** The form posts the list of AI-filled paths it did not accept, and the server trusts it
  (staff only, behind the permission). The suggestion endpoint stays stateless.
- The JS must work with every text-holding input Folio renders: plain inputs, textareas, the rich-text
  editor and block/matrix leaves.
- The suggestion endpoint returns `{path: text, warnings}` and never writes.
- Permission: a new rbac permission (for example `ai.translate`) controls the buttons, the bulk action
  and the endpoints.

### Whole type

- A list bulk action ("Translate…", on selected records or the whole type) opens a run screen:
  - target languages;
  - scope;
  - optional note;
  - scope summary ("14 records, 312 texts, 6 skipped as current") and estimated cost.
- After Start it shows progress driven by small htmx requests (one record, or a few, per request), so
  it never hits PHP or hosting time limits.
- Stop keeps what is done; failed records are listed with the reason.
- **(my call)** A record whose working copy already has unpublished human edits is translated like the
  others. The AI texts join that draft, and the run summary lists those records separately so they can
  be looked at before publishing.
- Results land as drafts with "not checked" markers. A run on a type without publishing requires an
  explicit confirmation that the texts go live on save (see anti-goals).

### Folio labels

Settings → Translations gets the same button per language: it fills missing (and optionally all) label
overrides as unsaved values, with the same review.

**(my call)** Labels and media alt texts get no "outdated" or "not checked" tracking in this round. Their
stores have no fingerprints, and both screens are reviewed in full anyway. On them the button fills
empty values, or everything with "redo all".

### Settings and media

- Settings screens (Folio) for:
  - the AI connection status (provider, model, key present yes/no, never the key itself; a test
    button);
  - tuning per level (project, language, type, field);
  - the glossary;
  - the usage overview (calls, tokens, cost per month and purpose).
- Media: alt texts and titles per locale on the media edit screen get the same button. Media
  libraries are part of the "new language" story later, but the single-asset button is in this round
  (my call).

## BG Genius part (project, not framework)

- `FlowFieldType` implements the new write-by-path contract using `FlowTexts` paths, so record-level
  and whole-type runs cover flows automatically.
- The flow translation screen (`FlowEditor::translations`, `flow-translate.twig`,
  `flow-translate.js`) gets the Translate button and review. It reuses the framework's suggestion
  endpoint/units and, where possible, Folio's review component. Its save keeps the `_version` check.
- Context per flow unit: game name, step title, element kind (option, feedback, glossary term).
- Tuning in config defaults:
  - German uses "du";
  - game mechanic terms stay in English;
  - game names are never translated.
- The glossary is seeded with the mechanic terms Crelish's German rules list.
- `config/ai.php` and `.env` keys locally and on live; live gets AI only after the owner adds the key.

## Constraints

- PHP 8.5, Preflow conventions: providers registered in `config/providers.php` or auto-wired like
  other packages, `extra.preflow.translations` for the new packages' own UI labels (en/de at least).
- Works on cyon shared hosting: no workers, no long requests; outbound HTTPS only.
- AI disabled unless `ai.enabled` is true and a key is present. With AI off, no button, no bulk action,
  and no error anywhere.
- Tests never call a real provider: a fake `AiClient` in `preflow/testing` (my call). One opt-in smoke
  test against real providers, skipped without keys.
- Release follows the usual routine: version bump, sites upgraded and deployed (they get the package,
  AI stays off).

## Left to the builder

- Package-internal structure, class names other than those named above, HTTP client choice.
- Batch sizes, token estimation method, the exact prompt wording (it must be inspectable in tests).
- Where job state and usage logs are stored, within Preflow's storage conventions.
- The review UI's exact look, following Folio's existing styles.
- Whether the Settings screens are one screen with tabs or several.
