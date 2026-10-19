# Preflow Notify — Design

**Date:** 2026-10-19
**Packages:** new `preflow/notify`; small additions to `preflow/core` (boot) and `preflow/devtools` (CLI)
**Status:** Approved (2026-10-19).
**Context:** smeyer.me's contact form must email its owner. Preflow has no mail capability
(bggenius hand-rolled a `MailService` around symfony/mailer). Instead of a mail-only helper,
Preflow gets one small interface for sending *messages of several kinds* (email, SMS, later
chat/push) over *pluggable provider adapters*. symfony/mailer becomes one adapter, an SMS
provider's SDK another.

## Goal

```php
$notifier->send(new Email(to: 'me@example.com', subject: 'Hi', text: '…'));
$notifier->send(new Sms(to: '+41790000000', text: 'Your code: 4711'));
```

Application code depends only on `Preflow\Notify\Notifier` and message value objects.
Which provider delivers a channel is configuration. Swapping SMTP for an API mailer, or one
SMS vendor for another, changes `config/notify.php`, not code.

## Non-goals

- **No queue / async / retries over time.** Sending is synchronous. Shared hosting (e.g.
  cyon) runs no workers; a queue is a separate future concern. This is *not* a message
  bus like Symfony Messenger (hence the name `notify`); it only delivers outbound messages.
- No inbound messages (receiving mail/SMS, webhooks).
- No templated-email system in this pass. Callers set `text`/`html` themselves (a Twig
  render is one line in the caller). Revisit when a second consumer needs it.
- No delivery tracking, bounces, or notification preferences per user.

## Architecture

### Messages

```php
interface Message
{
    /** Channel key used for routing, e.g. 'email', 'sms'. */
    public function channel(): string;
}
```

Shipped messages, all `final readonly`:

- `Email` — `to` (string|list), `subject`, `text`, `html` (nullable), `from` (nullable →
  channel default), `replyTo`, `cc`, `bcc`, `attachments` (list of `Attachment`: path or
  contents + filename + mime). Channel `email`.
- `Sms` — `to` (E.164 string), `text`, `from` (nullable → channel default). Channel `sms`.

Any app or package can add a message type by implementing `Message` with its own channel
key (`chat`, `push`, …) and providing a transport that supports it.

### Transports

```php
interface Transport
{
    public function supports(Message $message): bool;

    /** @throws TransportException */
    public function send(Message $message): SentMessage;
}
```

`SentMessage` (final readonly): the original message, transport name, provider message id
(nullable). `TransportException` wraps provider errors without leaking credentials.

Built in, dependency-free:

| Driver | Purpose |
|---|---|
| `log` | Appends a one-line summary plus body to `storage/logs/messages.log`. Default in development. |
| `null` | Drops messages. |
| `memory` | Keeps sent messages in memory (`sent()`, `reset()`) for tests. |
| `failover` | Wraps a list of transports and tries them in order until one succeeds. |

Bridges, shipped in the package under `src/Bridge/`, each active only when its library is
installed (`suggest` in composer.json, `class_exists` guard), like core's optional boot steps:

| Driver | Library | Config |
|---|---|---|
| `symfony-mailer` | `symfony/mailer` | `dsn` (e.g. `smtp://user:pass@host:587`, or any Symfony mailer bridge DSN) |
| `symfony-notifier-sms` | `symfony/notifier` + a bridge | `dsn` (e.g. `twilio://SID:TOKEN@default?from=…`) — gives access to Symfony's SMS bridges without writing one |

A provider with its own SDK gets a ~30-line class implementing `Transport`, registered under
a driver name (see Extension). That is the "SDK adapter" path; no bridge package required.

### Notifier and routing

```php
final class Notifier
{
    public function send(Message $message): SentMessage;
}
```

Looks up the transport configured for `$message->channel()`, applies channel defaults (e.g.
default `from`), checks `supports()`, sends. No transport for a channel → `TransportException`
with a clear message naming the missing config key.

### Configuration — `config/notify.php`

```php
return [
    'transports' => [
        'smtp'  => ['driver' => 'symfony-mailer', 'dsn' => getenv('MAILER_DSN') ?: ''],
        'log'   => ['driver' => 'log'],
        'mail'  => ['driver' => 'failover', 'transports' => ['smtp', 'log']],
    ],
    'channels' => [
        'email' => ['transport' => 'mail', 'from' => getenv('MAIL_FROM') ?: null],
        'sms'   => ['transport' => 'log'],
    ],
];
```

Secrets come from `.env` only; the config file holds no credentials.

### Boot

`Application::bootNotify()` in `preflow/core`, following the existing pattern
(`bootI18n`, `bootDataLayer`): runs only when `Preflow\Notify\Notifier` exists **and**
`config/notify.php` exists. It builds a `TransportFactory`, registers the built-in and
available bridge drivers, and binds `Notifier` in the container, so components and
controllers get it by constructor injection.

### Extension

```php
// in an app ServiceProvider::boot()
$container->get(TransportFactory::class)
    ->extend('acme-sms', fn (array $config) => new AcmeSmsTransport(new AcmeClient($config['key'])));
```

Factories are resolved lazily on first send, so a broken optional provider cannot take the
site down at boot.

### CLI

`php preflow notify:test --channel=email --to=me@example.com` (in `preflow/devtools`) sends
a fixed test message through the configured transport and prints the result or the error.
This is the way to verify SMTP credentials on a live server over SSH.

## Error handling

- Provider errors → `TransportException` (message without DSN/credentials; original
  exception chained).
- Callers decide what failure means. For smeyer.me's contact form: the message record is
  stored first, then the mail is sent; a send failure is logged and the record flagged, so
  no submission is lost.

## New / affected files

- `packages/notify/` — `composer.json`, `README.md`, `src/{Message,Email,Sms,Attachment,
  Transport,SentMessage,TransportException,Notifier,TransportFactory}.php`,
  `src/Transport/{LogTransport,NullTransport,InMemoryTransport,FailoverTransport}.php`,
  `src/Bridge/SymfonyMailer/SymfonyMailerTransport.php`,
  `src/Bridge/SymfonyNotifier/SymfonyNotifierSmsTransport.php`, `tests/`
- `packages/core/src/Application.php` — `bootNotify()`
- `packages/devtools/src/Command/NotifyTestCommand.php`
- Root `composer.json` (path repo + `@dev` require), `phpunit.xml` (Notify suite)
- `packages/skeleton/config/notify.php` (log transport only) and `.env.example` keys

## Testing

- Unit: routing by channel, channel defaults applied, unsupported message → exception,
  missing channel config → exception, failover order and exhaustion, log transport output,
  memory transport.
- Bridge: `SymfonyMailerTransport` against Symfony's `null://null` DSN (no network) maps
  every `Email` field to a `Symfony\Component\Mime\Email`; same idea for the SMS bridge with
  Symfony's fake/null SMS transport.
- Boot integration: app with `config/notify.php` resolves `Notifier` from the
  container; app without it does not register anything.
- CLI: `notify:test` with the memory transport.

## Open for the builder

- Exact attachment API shape (path vs contents), as long as both are possible.
- Whether `Email::to` accepts `name <address>` strings or a small `Address` value object;
  pick one and keep it consistent.
