<?php

declare(strict_types=1);

namespace Preflow\Htmx;

use Preflow\View\AssetCollector;
use Preflow\View\JsPosition;

/**
 * Inlines the vendored htmx build through the AssetCollector (same nonce and
 * dedup as component JS), so pages make no external request for it. Also hands
 * htmx the page nonce, so scripts/styles it inserts from fragment responses
 * are accepted by the page's CSP.
 *
 * Modes (config/htmx.php 'include'): always | on-demand | never.
 */
final class HtmxAssets
{
    private const MODES = ['always', 'on-demand', 'never'];

    private bool $required = false;

    public function __construct(
        private readonly AssetCollector $assets,
        private readonly string $mode,
        private readonly string $scriptPath = __DIR__ . '/../assets/htmx.min.js',
    ) {
        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid htmx include mode "%s" in config/htmx.php; use one of: %s.',
                $mode,
                implode(', ', self::MODES),
            ));
        }
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function require(): void
    {
        if ($this->required || $this->mode === 'never') {
            return;
        }
        $this->required = true;

        $nonce = $this->assets->getNonce();
        $config = json_encode(
            ['inlineScriptNonce' => $nonce, 'inlineStyleNonce' => $nonce],
            JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        // htmx reads this meta on DOM ready, so its position after the script is fine.
        $this->assets->addHeadTag('<meta name="htmx-config" content=\'' . $config . '\'>');
        $this->assets->addJs((string) file_get_contents($this->scriptPath), JsPosition::Head, 'preflow-htmx');
        $this->assets->addJs(self::NONCE_GUARD, JsPosition::Head, 'preflow-htmx-nonce-guard');
    }

    /**
     * htmx re-stamps every <script>/<style> it swaps in with the page nonce
     * (inlineScriptNonce), which would let an injected script run as trusted.
     * Before a swap, drop every script/style whose nonce is not the one the
     * server announced for that response in the HX-Nonce header.
     */
    private const NONCE_GUARD = <<<'JS'
document.addEventListener('htmx:beforeSwap', function (e) {
    var d = e.detail, html = d.serverResponse;
    if (typeof html !== 'string' || !/<(script|style)\b/i.test(html)) { return; }
    var nonce = d.xhr ? d.xhr.getResponseHeader('HX-Nonce') : null;
    var isDocument = /<html[\s>]/i.test(html);
    var root = isDocument ? new DOMParser().parseFromString(html, 'text/html') : document.createElement('template');
    if (!isDocument) { root.innerHTML = html; }
    var scope = isDocument ? root : root.content, removed = false;
    scope.querySelectorAll('script, style').forEach(function (el) {
        if (!nonce || el.getAttribute('nonce') !== nonce) { el.parentNode.removeChild(el); removed = true; }
    });
    if (removed) {
        d.serverResponse = isDocument ? '<!DOCTYPE html>' + root.documentElement.outerHTML : root.innerHTML;
    }
});
JS;

    public function isRequired(): bool
    {
        return $this->required;
    }
}
