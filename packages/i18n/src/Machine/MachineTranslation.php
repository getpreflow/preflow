<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

/**
 * Whether the app has machine translation at all: the container's answer, so whoever binds the
 * MachineTranslator (preflow/i18n's MachineServiceProvider, a translator package, the app) also
 * says whether there is one. Folio asks it to offer Translate, its text sources and the
 * `ai.translate` permission. MachineServiceProvider binds ConfiguredMachineTranslation; a package
 * that binds a translator of its own binds this too.
 *
 * Not the same as MachineTranslator::available(): that says whether the translator can translate
 * now (AI switched on, its key present). This says whether there is a translator to switch on,
 * so roles keep offering the permission while AI is off.
 *
 * @since 0.83.0
 */
interface MachineTranslation
{
    /**
     * True when a translator that can work is bound (false: MissingAiTranslator). Cheap and never
     * throws: it is asked on every boot, without building the translator.
     *
     * @since 0.83.0
     */
    public function available(): bool;
}
