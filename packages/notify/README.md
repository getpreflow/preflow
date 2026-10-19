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

## Errors

Every failure is a `Preflow\Notify\TransportException`. Provider errors are wrapped and
masked, so a DSN password never appears in a message. The caller decides what a failure
means; for a contact form, store the submission first, then send.

## CLI

```bash
php preflow notify:test --to=me@example.com
php preflow notify:test --channel=sms --to=+41790000000
```

This sends a fixed test message through the configured transport and prints the result or
the error. Use it to check credentials on a live server.
