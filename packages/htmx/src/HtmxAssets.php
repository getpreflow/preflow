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
    }

    public function isRequired(): bool
    {
        return $this->required;
    }
}
