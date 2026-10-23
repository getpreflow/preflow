# preflow/notify

Send email, SMS and other messages through pluggable transports. Application code
depends on one class, `Notifier`, and on small message value objects. Which provider
delivers a channel is configuration.

```php
use Preflow\Notify\Email;
use Preflow\Notify\Notifier;
use Preflow\Notify\Sms;

$notifier->send(new Email(to: 'me@example.com', subject: 'Hi', text: 'Hello there'));
$notifier->send(new Sms(to: '+41790000000', text: 'Your code: 4711'));
```

`Notifier` is injected by constructor like any other service. Sending is synchronous.
This is **not** a message bus or queue (unlike Symfony Messenger): it only delivers
outbound messages.

## Configuration

Core boots the package when `config/notify.php` exists:

```php
return [
    'transports' => [
        'smtp' => ['driver' => 'symfony-mailer', 'dsn' => getenv('MAILER_DSN') ?: ''],
        'log'  => ['driver' => 'log'],
        'mail' => ['driver' => 'failover', 'transports' => ['smtp', 'log']],
    ],
    'channels' => [
        'email' => ['transport' => 'mail', 'from' => getenv('MAIL_FROM') ?: null],
        'sms'   => ['transport' => 'log'],
    ],
];
```

- Credentials belong in `.env`.
- Transports are created on first send, so an empty or broken DSN never breaks boot. It
  fails, with a clear message, when something is sent.
- A channel's `from` is used when a message doesn't set its own.

## Drivers

| Driver | Needs | Purpose |
|---|---|---|
| `log` | nothing | Appends a summary and the body to `storage/logs/messages.log` (or `path`). |
| `null` | nothing | Drops messages. |
| `memory` | nothing | Keeps messages in memory (`sent()`, `reset()`) for tests. |
| `failover` | nothing | Tries `transports` in order. Skips any that can't send the message type. |
| `symfony-mailer` | `composer require symfony/mailer` | `dsn`: `smtp://user:pass@host:587` or any Symfony mailer bridge DSN. |
| `symfony-notifier-sms` | `composer require symfony/notifier` plus a bridge (e.g. `symfony/twilio-notifier`) | `dsn`: e.g. `twilio://SID:TOKEN@default?from=+41…`. |

## Messages

- `Email`:
  - `to`, `subject`, `text`, plus optional `html`, `from`, `replyTo`, `cc`, `bcc` and
    `attachments` (`Attachment::fromPath()` / `Attachment::fromContents()`).
  - Addresses are strings: `a@b.test` or `Name <a@b.test>`.
  - Line breaks in the subject or addresses are rejected, which prevents header injection.
- `Sms`: `to` in E.164 format (`+41790000000`), `text`, optional `from`.
- Your own types: implement `Preflow\Notify\Message` with a channel key (`chat`, `push`, …)
  and configure a transport that supports it.

## A provider with its own SDK

Implement `Preflow\Notify\Transport` and register it as a driver from a service provider:

```php
final class AcmeSmsTransport implements Transport
{
    public function __construct(private AcmeClient $client, private string $name) {}

    public function supports(Message $message): bool { return $message instanceof Sms; }

    public function send(Message $message): SentMessage
    {
        try {
            $id = $this->client->sendText($message->to, $message->text);
        } catch (\Throwable $e) {
            throw TransportException::fromProvider($this->name, $e);
        }

        return new SentMessage($message, $this->name, $id);
    }
}

// in a ServiceProvider::boot()
$container->get(TransportFactory::class)->extend(
    'acme-sms',
    fn (array $config, string $name) => new AcmeSmsTransport(new AcmeClient($config['key']), $name),
);
```

Then set `'texts' => ['driver' => 'acme-sms', 'key' => getenv('ACME_KEY') ?: '']` in
`transports`.

## Sending after the response

Sending takes an SMTP round trip. On account flows (forgot password, registration, resend
confirmation) that makes "an account exists" answer measurably slower than "no account",
which leaks which addresses are registered. Queue those mails instead:

```php
$notifier->sendLater(
    new Email(to: $user->email, subject: 'Reset your password', text: $text),
    onFailure: fn (\Throwable $e) => $this->audit->warn('reset mail failed'),
);
```

`sendLater()` only queues. Core sends the queue once the response has gone out:
`Application::run()` emits the response, calls `fastcgi_finish_request()` when it exists
(PHP-FPM, so the client is released), then calls `flushDeferred()`. Plain
`register_shutdown_function` was not used: it would run after the script ends, outside the
application's control, and could not be flushed deterministically in the CLI and in tests.

- Order is FIFO; every message is isolated, one failure does not stop the others.
- A deferred failure never throws into the request. It calls `$onFailure(\Throwable)` when
  given; otherwise it logs a generic line (no addresses) through a PSR `LoggerInterface`
  bound in the container, else `error_log()`.
- `flushDeferred()` returns the number sent.
- **CLI and tests:** `Application::handle()` flushes the queue itself before returning
  (only `run()` postpones it until after emitting), so in-process requests and CLI commands
  never lose mail.
- Without `config/notify.php` there is no Notifier and nothing happens.
- `'defer' => false` in `config/notify.php` (default `true`) makes `sendLater()` send at
  once, a failure going to `$onFailure`/the log. Handy to debug; tests rarely need it.

## Errors

Every failure is a `Preflow\Notify\TransportException`. Provider errors are wrapped, and
their message is masked: URL user-info (`smtp://***@host`) and secret-looking query
parameters (`api_key=***`, `password=***`, `token=***`). The original exception is chained
unmasked as `getPrevious()`, so don't log the whole chain verbatim.

When a `failover` delivers through a fallback, `SentMessage::$failures` lists each transport
it fell back from (name → masked reason). Log these. Otherwise a broken SMTP setup goes
unnoticed while mail quietly lands in the log transport.

The caller decides what a failure means; for a contact form, store the submission first,
then send.

## CLI

```bash
php preflow notify:test --to=me@example.com
php preflow notify:test --channel=sms --to=+41790000000
```

This sends a fixed test message through the configured transport and prints the result or
the error. It exits non-zero when a failover had to fall back, and prints why the preferred
transport failed. Use it to check credentials on a live server.
