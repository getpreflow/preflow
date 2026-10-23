<?php

declare(strict_types=1);

namespace Preflow\Members;

use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\Notify\Email;
use Preflow\Notify\Notifier;
use Preflow\Notify\TransportException;

/**
 * Sends the member mails. The owner's decision mails (accepted, declined) carry the message
 * the owner wrote in the Decision panel and are sent at once: a TransportException
 * propagates, so a failed mail aborts the decision.
 */
final class MemberMailer
{
    // Neutral subjects; a site's own copy comes with the lang files.
    private const array SUBJECTS = [
        'accepted' => ['en' => 'Your account is ready', 'de' => 'Dein Konto ist bereit', 'fr' => 'Votre compte est prêt'],
        'declined' => ['en' => 'About your registration', 'de' => 'Zu deiner Registrierung', 'fr' => 'À propos de votre inscription'],
    ];

    public function __construct(
        private readonly Notifier $notifier,
        private readonly Locales $locales,
    ) {}

    /** @throws TransportException */
    public function accepted(DynamicRecord $m, string $message): void
    {
        $this->sendMessage('accepted', $m, $message);
    }

    /** @throws TransportException */
    public function declined(DynamicRecord $m, string $message): void
    {
        $this->sendMessage('declined', $m, $message);
    }

    private function sendMessage(string $mail, DynamicRecord $m, string $message): void
    {
        $lang = $this->lang($m);
        $this->notifier->send(new Email(
            to: (string) $m->get('email'),
            subject: self::SUBJECTS[$mail][$lang] ?? self::SUBJECTS[$mail]['en'],
            text: $message,
        ));
    }

    /** The member's language when the site has it, else the site's default. */
    private function lang(DynamicRecord $m): string
    {
        $lang = (string) $m->get('language');

        return $this->locales->has($lang) ? $lang : $this->locales->default;
    }
}
