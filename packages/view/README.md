# preflow/view

Engine-agnostic template interfaces and asset pipeline for Preflow. Defines the contracts that engine adapters (`preflow/twig`, `preflow/blade`) implement. Ships a CSP-nonce-aware asset collector with deduplication.

## Installation

```bash
composer require preflow/view
```

Requires PHP 8.4+. Install an engine adapter to render templates:

```bash
composer require preflow/twig   # Twig 3
composer require preflow/blade  # Laravel Blade
```

## What's included

| Component | Description |
|---|---|
| `TemplateEngineInterface` | Engine contract: render, exists, addFunction, addGlobal, getTemplateExtension |
| `TemplateFunctionDefinition` | Value object describing a template function (name, callable, isSafe) |
| `TemplateExtensionProvider` | Interface for packages that supply template functions and globals |
| `AssetCollector` | Collects CSS/JS across components, deduplicates by xxh3 hash |
| `JsPosition` | Enum: `Head`, `Body`, `Inline` |
| `NonceGenerator` | Generates one random nonce per request for CSP |

## TemplateEngineInterface

The central contract. Engine adapters implement this to plug into Preflow.

```php
use Preflow\View\TemplateEngineInterface;

$html = $engine->render('blog/post', ['post' => $post]);
$engine->exists('partials/nav');                          // bool
$engine->getTemplateExtension();                          // 'twig' or 'blade.php'
$engine->addGlobal('siteName', 'My App');                 // available in all templates
$engine->addFunction(new TemplateFunctionDefinition(
    name: 'greet',
    callable: fn (string $name) => "Hello, {$name}!",
    isSafe: true,                                         // skip output escaping
));
```

## TemplateExtensionProvider

Feature packages implement this interface to register template functions without depending on a specific engine.

```php
use Preflow\View\TemplateExtensionProvider;
use Preflow\View\TemplateFunctionDefinition;

final class MyExtensionProvider implements TemplateExtensionProvider
{
    public function getTemplateFunctions(): array
    {
        return [
            new TemplateFunctionDefinition(
                name: 'myHelper',
                callable: fn (string $arg) => strtoupper($arg),
                isSafe: true,
            ),
        ];
    }

    public function getTemplateGlobals(): array
    {
        return ['appVersion' => '1.0'];
    }
}
```

Built-in providers: `ComponentsExtensionProvider`, `HtmxExtensionProvider`, `TranslationExtensionProvider`.

## AssetCollector API

```php
// Add CSS (key is optional; defaults to xxh3 hash of content)
$assets->addCss('.btn { ... }');
$assets->addCss('.btn { ... }', key: 'btn-styles'); // explicit dedup key

// Add JS
$assets->addJs('console.log("body")', JsPosition::Body);
$assets->addJs('console.log("head")', JsPosition::Head);
$assets->addJs('console.log("inline")', JsPosition::Inline);

// Render (called by engine extensions; also usable directly)
$assets->renderHead();    // <script nonce="...">head JS</script>
$assets->renderAssets();  // <style nonce="...">CSS</style><script nonce="...">body JS</script>
$assets->renderCss();
$assets->renderJsHead();
$assets->renderJsBody();
$assets->renderJsInline();

// CSP nonce for the current request
$assets->getNonce(); // base64 random, stable within one request
```

Every `<style>` and `<script>` tag rendered by `AssetCollector` carries the same `nonce` attribute. Preflow sends a matching `Content-Security-Policy` header itself (strict by default; override per directive in `config/security.php`, sent as report-only in debug mode). For a `<script>` or `<style>` you write by hand, add `nonce="{{ csp_nonce() }}"`; without it the browser blocks the tag.

Identical blocks (same xxh3 hash) are deduplicated automatically — safe to include the same component multiple times.

### Forked collectors and inspection

`fork()` creates an isolated child `AssetCollector` that shares the same nonce but collects assets independently. Use it when rendering a sub-component that should not pollute the parent collector (e.g. fragment responses).

```php
$child = $assets->fork();
$html  = $componentRenderer->renderFragment($component, $child);
// $assets is untouched; $child holds only the fragment's CSS/JS
```

`hasCss()` and `hasJs()` let you check before rendering — useful to decide whether to append inline styles to a fragment response.

```php
if ($child->hasCss() || $child->hasJs()) {
    $html .= $child->renderHead() . $child->renderAssets();
}
```

## QR codes

`qr_svg($data, $options)` returns an inline SVG (safe HTML, no escaping). In Twig it is a function, in Blade the
directive `@qr_svg(...)`.

```twig
{{ qr_svg('https://example.org/ticket/42', { size: 160, ecc: 'Q' }) }}
```

```blade
@qr_svg('https://example.org/ticket/42', ['size' => 160])
```

Options: `size` (pixels, default 200, at least 21), `margin` (modules, default 2), `ecc` (`L`, `M`, `Q`, `H`;
default `M`), `color` and `background` (any CSS colour for SVG). In PHP, `Preflow\View\Qr\QrCode::svg()` and
`QrCode::png()` (PNG colours must be hex; the size is rounded down to whole pixels per module). QR codes need the
suggested package `chillerlan/php-qrcode` (`^5.0`); without it you get a `RuntimeException`: "QR codes need the
chillerlan/php-qrcode library. Install it with: composer require chillerlan/php-qrcode".

## PDFs from templates

A PDF is a document class plus a template. The template is ordinary Twig or Blade of the site's engine; the
renderer turns the HTML into a PDF with mPDF.

```php
use Preflow\View\Pdf\{PageSettings, PdfDocument, PdfRenderer, PdfResponse};

final class CertificateDocument extends PdfDocument
{
    public string $name = '';          // public properties are the template variables
    public ?Game $game = null;

    public function template(): string { return __DIR__ . '/certificate.twig'; } // name or absolute path

    public function resolveState(): void { $this->game = /* load what the template needs */; }

    public function page(): PageSettings
    {
        return new PageSettings(format: 'A4', orientation: 'L', marginTop: 20, footerTemplate: __DIR__ . '/footer.twig');
    }

    public function fileName(): string { return 'certificate.pdf'; }
}

$doc = new CertificateDocument();
$doc->name = 'Ada Lovelace';

$bytes = $renderer->render($doc);                 // PDF bytes, never written to disk
$html  = $renderer->html($doc);                   // an HTML preview, needs no PDF library
return PdfResponse::download($bytes, $doc->fileName());   // or PdfResponse::inline()
```

`PdfRenderer` is bound in the container of a booted app (when `preflow/view` and a template engine are installed).
`resolveState()` runs before every render and preview. `PageSettings` takes `format`, `orientation` (`P` or `L`),
margins in mm, `headerTemplate`/`footerTemplate` (templates rendered with the same variables and repeated on every
page, also when your CSS has `@page` rules) and a custom `width`/`height` in mm (together, they win over format and
orientation). A template is tied to one engine's syntax, so use the extension of the engine you run.

`html()` is only a preview: a browser lays the page out, mPDF does not, so page numbers are empty, page breaks are a
dashed line and the layout is approximate. Check the real thing with `render()`. `PdfResponse` sends
`application/pdf` with `Cache-Control: private, no-store`.

Configure in `config/view.php`:

```php
return [
    'pdf' => [
        'temp_dir' => 'storage/cache/pdf',   // mPDF's cache and scaled image copies; relative to the project root
        'fonts' => ['Certificate Script' => 'resources/fonts/script.ttf'],   // CSS font-family name => file
        'default_font' => 'dejavusanscondensed',
    ],
];
```

`fonts` registers the regular face only; bold and italic print in it too. A name that matches a font family bundled
with mPDF (for example `DejaVu Sans` or `Times`) is refused with an `InvalidArgumentException`: register the file
under another name. `default_font` may be such a configured name. Bind your own `PdfEngine` to use another renderer.

### Building blocks

Twig functions (Blade: `@pdf_...(...)`, all return safe HTML):

| Function | Does |
|---|---|
| `pdf_page_number()`, `pdf_page_count()` | "Page X of Y" in header, footer or body (empty in the preview) |
| `pdf_page_break()` | starts a new page |
| `pdf_table($rows, $columns, $options)` | a table whose header row repeats on every page; `$columns` maps row key to header text; options `class`, `widths`; cells are escaped |
| `pdf_image($assetIdOrPath, $options)` | an image, scaled down; options `width_mm`, `max_px` (1200), `allow_private`, `alt` |
| `pdf_qr($data, $options)` | an inline SVG QR code; `size_mm` (30) plus the `qr_svg` options |

`pdf_image` takes an absolute file path or a media asset id (needs `preflow/media`) and nothing else: no URLs, no
relative paths. It reads the file from the server, so it must never get user input. A private media asset needs
`allow_private: true`. JPEG, PNG, GIF and WebP images longer than `max_px` are scaled to a JPEG in `temp_dir` for the
render and removed afterwards; formats GD cannot read (for example AVIF) are not scaled, so keep them small. Images
over 40 megapixels are refused.

### CSS limits

mPDF supports a good part of CSS 2 but no flexbox and no grid. Lay pages out with tables, floats and fixed widths,
and check the PDF itself.

### Suggested packages and errors

Both are optional (`suggest`); without them the matching call throws a `RuntimeException`:

- `mpdf/mpdf` (`^8.2`): "PDFs need the mPDF library. Install it with: composer require mpdf/mpdf". `html()` still works.
- `chillerlan/php-qrcode` (`^5.0`): see above. `pdf_qr` needs it too.
- Scaling images needs PHP's `gd` extension.
