<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Qr;

use chillerlan\QRCode\QRCode as Library;
use chillerlan\QRCode\QROptions;
use PHPUnit\Framework\TestCase;
use Preflow\View\Qr\QrCode;
use Preflow\View\ViewExtensionProvider;

final class QrCodeTest extends TestCase
{
    protected function tearDown(): void
    {
        QrCode::overrideLibraryClass(null);
    }

    public function test_svg_decodes_back_to_the_data(): void
    {
        $url = 'https://example.test/play?game=42&ref=qr';

        $png = QrCode::png($url);
        $result = (new Library(new QROptions(['readerUseImagickIfAvailable' => false])))->readFromBlob($png);

        $this->assertSame($url, (string) $result);
    }

    public function test_qr_svg_escapes_nothing_into_markup(): void
    {
        $fn = (new ViewExtensionProvider())->getTemplateFunctions()[0];
        $this->assertSame('qr_svg', $fn->name);
        $this->assertTrue($fn->isSafe);

        $svg = ($fn->callable)('https://x.test/?a=1&b="2"');

        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML($svg));
        $this->assertSame('svg', $doc->documentElement->nodeName);
        $this->assertStringNotContainsString('"2"', $svg);
        $this->assertSame(1, substr_count($svg, '<svg'));
    }

    public function test_options_apply(): void
    {
        $svg = QrCode::svg('hello', ['size' => 320, 'color' => '#ff0000', 'background' => '#00ff00']);

        $this->assertStringContainsString('width="320"', $svg);
        $this->assertStringContainsString('height="320"', $svg);
        $this->assertStringContainsString('#ff0000', $svg);
        $this->assertStringContainsString('#00ff00', $svg);

        $default = QrCode::svg('hello');
        $this->assertStringContainsString('width="200"', $default);

        $small = getimagesizefromstring(QrCode::png('hello', ['size' => 100]));
        $big = getimagesizefromstring(QrCode::png('hello', ['size' => 400]));
        $this->assertGreaterThan($small[0], $big[0]);
        $this->assertLessThanOrEqual(400, $big[0]);

        $noMargin = getimagesizefromstring(QrCode::png('hello', ['size' => 100, 'margin' => 0, 'ecc' => 'H']));
        $this->assertNotFalse($noMargin);
    }

    public function test_rejects_unknown_ecc_level(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        QrCode::svg('x', ['ecc' => 'Z']);
    }

    public function test_missing_library_names_the_package(): void
    {
        $this->assertTrue(QrCode::available());
        QrCode::overrideLibraryClass('Nope\\Missing');

        $this->assertFalse(QrCode::available());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('composer require chillerlan/php-qrcode');
        QrCode::svg('x');
    }
}
