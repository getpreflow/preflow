<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Media;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\FolioText;
use Preflow\Folio\Media\MediaMessages;
use Preflow\I18n\Translator;

final class MediaMessagesTest extends TestCase
{
    public function test_heic_messages_are_translated(): void
    {
        $translator = new Translator(sys_get_temp_dir() . '/folio-no-site-lang', 'de', 'en');
        $translator->addDefaults('folio', dirname(__DIR__, 2) . '/lang');
        $previous = FolioText::use($translator);
        try {
            $this->assertSame(
                'IMG_0001.HEIC: HEIC/HEIF-Fotos können hier nicht gespeichert werden, weil Imagick auf dem Server kein HEIC unterstützt. Bitte das Foto zuerst in JPEG umwandeln.',
                MediaMessages::text('IMG_0001.HEIC: HEIC/HEIF images need Imagick with HEIC support on the server, which is missing.'),
            );
            $this->assertSame(
                'IMG_0001.HEIC: Das HEIC/HEIF-Foto konnte nicht umgewandelt werden.',
                MediaMessages::text('IMG_0001.HEIC: the HEIC/HEIF image could not be converted.'),
            );
        } finally {
            FolioText::use($previous);
        }
    }
}
