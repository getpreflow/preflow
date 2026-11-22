<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

/**
 * The translator cannot be used at all (no key, no credit, the service is down): the whole run
 * stops. MachineTranslator::translate() and translateUnits() throw it; the job runner stops the job
 * with its message (items done stay done, the rest stay pending), the worker ends its run, and
 * Folio's Translate says why. AiTranslator turns preflow/ai's AiUnavailable into it (the AI
 * exception is its previous).
 *
 * @since 0.83.0
 */
final class TranslatorUnavailable extends \RuntimeException {}
