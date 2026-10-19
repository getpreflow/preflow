# Preflow Notify Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A new `preflow/notify` package that sends typed messages (`Email`, `Sms`, extensible) through transports configured per channel, with symfony/mailer and symfony/notifier bridges, auto-booted by core, and testable from the CLI with `notify:test`.

**Architecture:**
- `Notifier::send(Message)` looks up the channel's configured transport name, then asks a
  lazy `TransportFactory` for that transport. The factory maps names to driver factories.
- Built-in transports have no dependencies.
- The bridges register only when their Symfony library is installed.
- Core's `Application::bootNotify()` wires everything when the package and
  `config/notify.php` both exist.

**Tech Stack:**
- PHP ≥ 8.4 (runs on 8.5), PHPUnit 11
- symfony/mailer and symfony/notifier `^7.3 || ^8.0` (suggested, dev only)

**Spec:** `docs/superpowers/specs/2026-10-19-preflow-notify-design.md`

**Branch:** create `feat/notify` from `docs/smeyer-folio-specs`, so the spec and plan travel with the code.

## Global Constraints

- Every PHP file: `declare(strict_types=1);`. Classes are `final` and value objects
  `final readonly`. Use constructor promotion with `private readonly`.
- Test methods are named `test_snake_case`. Test namespace `Preflow\Notify\Tests\`.
- Package namespace `Preflow\Notify\`, directory `packages/notify/`, composer name
  `preflow/notify`.
- Sending is synchronous: no queue, no async, no retries over time.
- Secrets only come from `.env` via `getenv()`. No exception message may contain DSN
  credentials.
- The bridges are `suggest`, never `require`, and guarded with `class_exists`.
- The config file is `config/notify.php`, the CLI command `notify:test`, and the core boot
  method `bootNotify()`.
- Run the full suite with `vendor/bin/phpunit`, and this package with
  `vendor/bin/phpunit --testsuite Notify`.

## Review Focus

1. **A DSN with a password inside a provider error message.** The thrown
   `TransportException` message must show `smtp://***@host`, never the password (Task 2:
   `test_exception_message_masks_dsn_credentials`).
2. **An `Email` without `from`, and no channel default.** The result should be a clear
   `TransportException` naming `channels.email.from`, not a Symfony stack trace (Task 4:
   `test_missing_from_is_a_clear_error`).
3. **A failover containing a transport that can't send this message type** (e.g. `smtp`
   for an `Sms`). It must be skipped, not counted as a failure (Task 2:
   `test_failover_skips_transports_that_do_not_support_the_message`).
4. **`MAILER_DSN` empty on the server.** Boot must still succeed. The first send, and
   `notify:test`, report "dsn is empty" (Task 4: `test_empty_dsn_fails_on_send_not_on_create`;
   Task 6: `test_reports_transport_error_with_exit_code_1`).
5. **CR/LF in subject or addresses** (the contact form passes visitor input as `replyTo`).
   This must be rejected at construction (Task 1:
   `test_rejects_header_injection_in_subject_and_addresses`).

---

### Task 1: Package scaffold and message value objects

**Files:**
- Create: `packages/notify/composer.json`
- Create: `packages/notify/src/Message.php`, `Email.php`, `Sms.php`, `Attachment.php`
- Modify: root `composer.json` (path repo `packages/notify`, require-dev `preflow/notify: @dev`, `symfony/mailer` and `symfony/notifier` `^7.3 || ^8.0`, autoload-dev `Preflow\Notify\Tests\` → `packages/notify/tests/`)
- Modify: `phpunit.xml` (testsuite `Notify` → `packages/notify/tests`)
- Test: `packages/notify/tests/MessageTest.php`

**Interfaces:**
- Produces:
  - `interface Message { public function channel(): string; }`
  - `final readonly class Email implements Message`, constructor
    `(string|array $to, string $subject, string $text, ?string $html = null, ?string $from = null, string|array|null $replyTo = null, string|array $cc = [], string|array $bcc = [], array $attachments = [])`.
    - Public props: `list<string> $to, $cc, $bcc, $replyTo`, `string $subject, $text`,
      `?string $html, $from`, `list<Attachment> $attachments`.
    - `withFrom(string $from): self`.
    - `channel()` returns `'email'`.
  - `final readonly class Sms implements Message`: `(string $to, string $text, ?string $from = null)`,
    `withFrom(string $from): self`, `channel()` returns `'sms'`.
  - `final readonly class Attachment`: `static fromPath(string $path, ?string $name = null, ?string $mime = null)`,
    `static fromContents(string $contents, string $name, ?string $mime = null)`.
    - Public props: `?string $path`, `?string $contents`, `string $name`, `?string $mime`.
- Addresses are plain strings, `a@b.test` or `Name <a@b.test>`. This is the spec's "open
  for the builder" choice, made here.

- [ ] **Step 1: Write the failing tests** in `MessageTest`:
  - `test_email_normalizes_string_recipients_to_lists`: `(new Email('a@b.test', 'S', 'T'))->to === ['a@b.test']`, `cc === []`, `replyTo === []`.
  - `test_email_requires_a_recipient`: `new Email([], 'S', 'T')` throws `\InvalidArgumentException`.
  - `test_rejects_header_injection_in_subject_and_addresses`: subject `"Hi\r\nBcc: x@y.z"` throws; replyTo `"a@b.test\nBcc: x@y.z"` throws.
  - `test_sms_requires_e164`: `new Sms('0790000000', 'T')` throws; `new Sms('+41790000000', 'T')` passes.
  - `test_with_from_returns_copy`: `$e->withFrom('me@x.test')->from === 'me@x.test'` and the original `from === null`.
  - `test_channels`: `'email'` / `'sms'`.
  - `test_attachment_factories`: `fromPath` takes the name from `basename` when null; `fromContents` keeps contents.
- [ ] **Step 2: Run** `vendor/bin/phpunit --testsuite Notify`. Expected: FAIL (classes not found). First run `composer update preflow/notify symfony/mailer symfony/notifier` after the composer.json edits.
- [ ] **Step 3: Implement the four classes.**
  - Validation: no `\r` or `\n` in subject, from or any address. E.164 regex `/^\+[1-9]\d{6,14}$/`.
  - `composer.json` follows `packages/i18n/composer.json`: require `php >=8.4`; suggest the
    two Symfony packages; require-dev phpunit.
- [ ] **Step 4: Run** `vendor/bin/phpunit --testsuite Notify`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(notify): package scaffold and message value objects`.

### Task 2: Transport contract and built-in transports

**Files:**
- Create: `packages/notify/src/Transport.php`, `SentMessage.php`, `TransportException.php`
- Create: `packages/notify/src/Transport/NullTransport.php`, `InMemoryTransport.php`, `LogTransport.php`, `FailoverTransport.php`
- Test: `packages/notify/tests/Transport/BuiltInTransportsTest.php`, `packages/notify/tests/TransportExceptionTest.php`

**Interfaces:**
- Consumes: `Message`, `Email`, `Sms` (Task 1).
- Produces:
  - `interface Transport { public function supports(Message $m): bool; /** @throws TransportException */ public function send(Message $m): SentMessage; }`
  - `final readonly class SentMessage(Message $message, string $transport, ?string $messageId = null)`.
  - `final class TransportException extends \RuntimeException`:
    - `static fromProvider(string $transport, \Throwable $previous): self`. The message is
      `Transport "{transport}" failed: {masked previous message}`, with the previous
      exception chained.
    - `static maskCredentials(string $text): string`, which replaces
      `#([a-z][a-z0-9+.-]*://)[^@/\s]+@#i` with `$1***@`.
  - `NullTransport(string $name = 'null')`, `InMemoryTransport(string $name = 'memory')`
    with `sent(): list<SentMessage>` and `reset(): void`.
  - `LogTransport(string $file, string $name = 'log')`.
  - `FailoverTransport(list<array{0:string,1:Transport}> $transports, string $name = 'failover')`.
    Pairs of name and transport give readable error messages.
  - Every built-in `supports()` returns true. Failover's returns true if any inner one
    does.

- [ ] **Step 1: Write the failing tests:**
  - `test_exception_message_masks_dsn_credentials`: `fromProvider('smtp', new \RuntimeException('Connection to smtp://user:s3cret@mail.test:587 failed'))->getMessage()` contains `smtp://***@mail.test` and not `s3cret`; `getPrevious()` is the original.
  - `test_memory_transport_records_and_resets`: send two messages; `sent()` has 2 and `sent()[0]->transport === 'memory'`; after `reset()` it has 0.
  - `test_log_transport_appends_summary_and_body`: temp file; an Email to `a@b.test` with subject `Hello` and text `Body` gives a file containing `email`, `to=a@b.test`, `subject=Hello` and `Body`. A second send appends. A missing directory is created.
  - `test_failover_uses_first_successful`: a throwing stub, then memory → SentMessage transport `memory`.
  - `test_failover_skips_transports_that_do_not_support_the_message`: a stub with `supports=false` that would throw if called, then memory → success, and the stub's send was never called.
  - `test_failover_exhausted_names_all_transports`: two throwing stubs `a`, `b` → `TransportException` whose message contains `a, b`.
- [ ] **Step 2: Run** `vendor/bin/phpunit --testsuite Notify`. Expected: FAIL.
- [ ] **Step 3: Implement.**
  - LogTransport line format:
    `[{ISO-8601}] {channel} to={comma-joined} subject={subject}\n{text}\n\n`.
    Subject is omitted for `Sms`. Use `FILE_APPEND | LOCK_EX`.
- [ ] **Step 4: Run.** Expected: PASS.
- [ ] **Step 5: Commit** `feat(notify): transport contract and built-in transports`.

### Task 3: TransportFactory and Notifier routing

**Files:**
- Create: `packages/notify/src/TransportFactory.php`, `packages/notify/src/Notifier.php`
- Test: `packages/notify/tests/TransportFactoryTest.php`, `packages/notify/tests/NotifierTest.php`

**Interfaces:**
- Consumes: Task 2 transports.
- Produces:
  - `final class TransportFactory`, constructor
    `(array $transports, string $basePath)`. `$transports` is the `transports` array from
    `config/notify.php`.
    - `extend(string $driver, \Closure $factory): void`, where the closure has the shape
      `fn (array $config, string $name, TransportFactory $factory): Transport`.
    - `hasDriver(string $driver): bool`.
    - `get(string $name): Transport`. Creation is lazy and memoized.
    - Built-in drivers registered in the constructor:
      - `log` (`path` config, default `{basePath}/storage/logs/messages.log`);
      - `null`;
      - `memory`;
      - `failover` (`transports` config: a list of names resolved via `get()`).
  - `final class Notifier`, constructor `(TransportFactory $transports, array $channels)`.
    `$channels` is the `channels` array from config.
    - `send(Message $message): SentMessage`.
- Errors are all `TransportException`, with these exact messages:
  - unknown name: `Unknown transport "{name}". Define it under 'transports' in config/notify.php.`
  - unknown driver: `Transport "{name}" uses driver "{driver}", which is not available.`
    For `symfony-mailer`, append ` Install symfony/mailer.`; for `symfony-notifier-sms`,
    append ` Install symfony/notifier.`
  - failover cycle: `Transport "{name}" references itself through failover.`
  - no channel: `No transport configured for channel "{channel}" (config/notify.php → channels.{channel}.transport).`
  - unsupported: `Transport "{name}" cannot send {short class name} messages.`

- [ ] **Step 1: Write the failing tests:**
  - `test_get_is_lazy_and_memoized`: a driver registered via `extend` counts its calls. Constructing the factory makes no call; `get('x')` twice makes one call.
  - `test_unknown_transport_and_driver_messages`: the exact messages above. With the Symfony packages absent from the factory's driver list (construct a factory, then assert on a name configured with driver `nope`), the unknown-driver message applies.
  - `test_failover_driver_resolves_named_transports` and `test_failover_self_reference_is_detected`.
  - `test_notifier_routes_by_channel`: channels `email → memA` and `sms → memB`; the Email lands in memA and the Sms in memB.
  - `test_channel_default_from_is_applied`: channel email `from: 'site@x.test'`; an Email with no from arrives with `from === 'site@x.test'`; an Email with its own from keeps it.
  - `test_missing_channel_and_unsupported_message`: the exact messages.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.** Cycle detection is a "currently resolving" set inside `get()`.
- [ ] **Step 4: Run.** Expected: PASS.
- [ ] **Step 5: Commit** `feat(notify): transport factory and channel routing`.

### Task 4: Symfony bridges

**Files:**
- Create: `packages/notify/src/Bridge/SymfonyMailer/SymfonyMailerTransport.php`
- Create: `packages/notify/src/Bridge/SymfonyNotifier/SymfonyNotifierSmsTransport.php`
- Modify: `packages/notify/src/TransportFactory.php` (register `symfony-mailer` when `class_exists(\Symfony\Component\Mailer\Transport::class)`, and `symfony-notifier-sms` when `class_exists(\Symfony\Component\Notifier\Transport::class)`; both read `dsn` from config)
- Test: `packages/notify/tests/Bridge/SymfonyMailerTransportTest.php`, `packages/notify/tests/Bridge/SymfonyNotifierSmsTransportTest.php`

**Interfaces:**
- Consumes: `Transport`, `SentMessage`, `TransportException`, `Email`, `Sms`.
- Produces:
  - `SymfonyMailerTransport(string $dsn, string $name)`.
    - `supports` is true for `Email` only.
    - `public toSymfonyEmail(Email $e): \Symfony\Component\Mime\Email`.
    - `send` builds the Symfony transport on first use via
      `\Symfony\Component\Mailer\Transport::fromDsn($dsn)`, sends, and returns `SentMessage`
      with the Symfony message id.
  - `SymfonyNotifierSmsTransport(string $dsn, string $name)`.
    - `supports` is true for `Sms` only.
    - Uses `\Symfony\Component\Notifier\Transport::fromDsn($dsn)` and
      `new \Symfony\Component\Notifier\Message\SmsMessage($to, $text, $from ?? '')`.
- Errors:
  - empty dsn on send: `Transport "{name}": dsn is empty (set it in .env).`
  - no from: `Transport "{name}": email has no from address; set channels.email.from in config/notify.php.`
  - any Symfony exception → `TransportException::fromProvider($name, $e)`.

- [ ] **Step 1: Write the failing tests:**
  - `test_maps_every_email_field`: `toSymfonyEmail` of an Email with to ×2, cc, bcc, replyTo, html, text, from `Site <site@x.test>`, a `fromContents` attachment and a `fromPath` attachment (temp file). Assert each getter on the Symfony Email, including `getAttachments()` count 2 and the names.
  - `test_sends_through_null_dsn`: dsn `null://null` → returns a `SentMessage` with transport `smtp` (the name passed in).
  - `test_empty_dsn_fails_on_send_not_on_create`: constructing with `''` doesn't throw; `send` throws with the exact message.
  - `test_missing_from_is_a_clear_error`: exact message.
  - `test_sms_sends_through_null_dsn` and `test_sms_bridge_does_not_support_email`.
  - `test_factory_registers_bridges_when_installed`: `hasDriver('symfony-mailer')` and `hasDriver('symfony-notifier-sms')` are true (both are dev deps).
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run.** Expected: PASS.
- [ ] **Step 5: Commit** `feat(notify): symfony mailer and notifier bridges`.

### Task 5: Core boot, skeleton config, README

**Files:**
- Modify: `packages/core/src/Application.php`. Add `private function bootNotify(): void` and call it right after `$this->bootI18n();` and before `bootSession()`, so service providers can `extend()` the factory in `boot()`.
- Create: `packages/skeleton/config/notify.php`, with transports `log => ['driver' => 'log']` and channels `email => ['transport' => 'log', 'from' => getenv('MAIL_FROM') ?: null]`, `sms => ['transport' => 'log']`.
- Modify: `packages/skeleton/.env.example`. Add `MAILER_DSN=` and `MAIL_FROM=`.
- Create: `packages/notify/README.md`. Cover:
  - usage;
  - the config file;
  - the driver table from the spec;
  - writing an SDK transport via `extend()`;
  - "not a message bus";
  - the bridges needing `composer require symfony/mailer` or `symfony/notifier` plus a
    provider bridge.
- Test: `packages/notify/tests/Integration/BootTest.php`

**Interfaces:**
- Consumes: `TransportFactory`, `Notifier` (Task 3).
- Produces:
  - container bindings `Preflow\Notify\TransportFactory` and `Preflow\Notify\Notifier`
    (instances), available to providers, components and commands.
  - `bootNotify` returns early unless `class_exists(\Preflow\Notify\Notifier::class)` and
    `config/notify.php` exists.
  - The config shape is `['transports' => array, 'channels' => array]`, with missing keys
    defaulting to `[]`.

- [ ] **Step 1: Write the failing tests.** Use the temp-app pattern of `packages/core/tests/ConfigProviderBootTest.php`, with `config/app.php` and `debug 0`:
  - `test_boot_binds_notifier_when_config_exists`: config/notify.php has `memory` transport and channel email → memory. `$c->get(Notifier::class)->send(new Email('a@b.test','S','T'))` returns a SentMessage; `$c->get(TransportFactory::class)->get('memory')->sent()` has 1.
  - `test_boot_skips_without_config`: `$c->has(Notifier::class) === false`.
  - `test_provider_can_extend_factory_in_boot`: a test provider listed in `config/providers.php` calls `extend('custom', …)` in `boot()`; channel email → transport using driver `custom` sends successfully.
- [ ] **Step 2: Run** `vendor/bin/phpunit --testsuite Notify`. Expected: FAIL.
- [ ] **Step 3: Implement `bootNotify()`, the skeleton files and the README.**
- [ ] **Step 4: Run `vendor/bin/phpunit`** (full suite). Expected: PASS, no new failures in Core or Skeleton.
- [ ] **Step 5: Commit** `feat(core): boot preflow/notify from config/notify.php`.

### Task 6: `notify:test` command

**Files:**
- Create: `packages/devtools/src/Command/NotifyTestCommand.php`
- Modify: `packages/devtools/src/Console.php` (register it)
- Test: `packages/devtools/tests/Command/NotifyTestCommandTest.php`

**Interfaces:**
- Consumes: `Application` (core); the container bindings from Task 5.
- Produces:
  - `final class NotifyTestCommand implements CommandInterface`.
    - Name `notify:test`, description `Send a test message through a notify channel`.
    - Constructor `(?\Closure $appFactory = null)`, where the closure takes no arguments
      and returns a booted `Application`. The default is
      `Application::create(getcwd())` then `->boot()`.
    - `execute(array $args): int`.
  - Args: `--channel=email|sms` (default `email`) and `--to=…` (required).
  - Messages:
    - email: subject `Preflow notify test`, text
      `This is a test message sent by "php preflow notify:test" at {ISO-8601}.`
    - sms: text `Preflow notify test`.
  - Output and exit codes:
    - success: echo `Sent via {transport}` plus ` (id {messageId})` when present; exit 0.
    - missing `--to`: STDERR usage `Usage: preflow notify:test --to=<address> [--channel=email|sms]`;
      exit 1.
    - package or config absent (`!$container->has(Notifier::class)`): STDERR
      `preflow/notify is not installed or config/notify.php is missing.`; exit 1.
    - `TransportException`: STDERR `Error: {message}`; exit 1.
  - Devtools must not hard-depend on notify: reference the classes by FQCN string or behind
    `class_exists`.

- [ ] **Step 1: Write the failing tests**, with an `$appFactory` that boots a temp app (as in Task 5) using a `memory` transport and keeps the container for assertions:
  - `test_sends_email_and_prints_transport`: `execute(['--to=a@b.test'])` returns 0; the output (via `ob_start`) contains `Sent via memory`; the memory transport has 1 Email with subject `Preflow notify test`.
  - `test_sms_channel`: `['--channel=sms', '--to=+41790000000']` → 1 Sms.
  - `test_missing_to_returns_1`.
  - `test_reports_transport_error_with_exit_code_1`: channel email → a `symfony-mailer` transport with empty dsn → returns 1.
  - `test_missing_notify_config_returns_1`: a temp app without config/notify.php.
- [ ] **Step 2: Run** `vendor/bin/phpunit --testsuite DevTools`. Expected: FAIL.
- [ ] **Step 3: Implement and register in `Console::__construct`.**
- [ ] **Step 4: Run `vendor/bin/phpunit`.** Expected: PASS.
- [ ] **Step 5: Commit** `feat(devtools): notify:test command`.

---

## After the plan

- Update `docs/superpowers/plans/README.md`'s table with this plan's row.
- Out of scope, but note in the final report:
  - publishing `preflow/notify` as a split repo / Packagist package before the skeleton can
    `require` it;
  - the skeleton only ships the config for now.
