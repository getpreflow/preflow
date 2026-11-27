<?php

declare(strict_types=1);

namespace Preflow\Data\Records;

use Preflow\Data\TypeDefinition;

/**
 * The packages' parts of the read output (OutputPart) and of a write's input (InputPart), in the
 * order added; each built on first use, once. A package adds its own in its service provider
 * (explicit: nothing is found by convention):
 *
 *     $container->get(RecordParts::class)->output(static fn (): OutputPart => $container->get(LayoutResource::class));
 *
 * outputKeys() and inputKeys() give a type's part keys, refusing those that cannot work.
 *
 * @since 0.93.0
 */
final class RecordParts
{
    /** The key every read and write keeps for the record's id: no part may take it. */
    public const string ID = '_id';

    /** @var list<\Closure(): OutputPart> */
    private array $outputFactories = [];

    /** @var array<int, OutputPart> */
    private array $outputs = [];

    /** @var list<\Closure(): InputPart> */
    private array $inputFactories = [];

    /** @var array<int, InputPart> */
    private array $inputs = [];

    /** @param \Closure(): OutputPart $part */
    public function output(\Closure $part): void
    {
        $this->outputFactories[] = $part;
    }

    /** @param \Closure(): InputPart $part */
    public function input(\Closure $part): void
    {
        $this->inputFactories[] = $part;
    }

    /** @return list<OutputPart> */
    public function outputs(): array
    {
        $all = [];
        foreach ($this->outputFactories as $at => $factory) {
            $all[] = $this->outputs[$at] ??= $factory();
        }

        return $all;
    }

    /** @return list<InputPart> */
    public function inputs(): array
    {
        $all = [];
        foreach ($this->inputFactories as $at => $factory) {
            $all[] = $this->inputs[$at] ??= $factory();
        }

        return $all;
    }

    /**
     * Each output part's key for this type, with the part that adds it, in the parts' order.
     *
     * @return array<string, OutputPart>
     * @throws \LogicException a key without a leading "_", "_id", a field's name, or a key two parts claim
     */
    public function outputKeys(TypeDefinition $def): array
    {
        /** @var array<string, OutputPart> */
        return self::keysOf($def, $this->outputs(), 'output');
    }

    /**
     * Each input part's key for this type, with the part that takes it, in the parts' order.
     *
     * @return array<string, InputPart>
     * @throws \LogicException as outputKeys()
     */
    public function inputKeys(TypeDefinition $def): array
    {
        /** @var array<string, InputPart> */
        return self::keysOf($def, $this->inputs(), 'input');
    }

    /**
     * @param list<OutputPart|InputPart> $parts
     * @return array<string, OutputPart|InputPart>
     */
    private static function keysOf(TypeDefinition $def, array $parts, string $kind): array
    {
        $keys = [];
        foreach ($parts as $part) {
            foreach ($part->keys($def) as $key) {
                $by = $part::class;
                if (!str_starts_with($key, '_') || $key === '_') {
                    throw new \LogicException(sprintf('The %s part %s names the key "%s" for the type "%s": a part\'s key is a system key, starting with "_" (such as "_%s"), so it never meets a field.', $kind, $by, $key, $def->key, $key === '_' ? 'extra' : $key));
                }
                if ($key === self::ID) {
                    throw new \LogicException(sprintf('The %s part %s names the key "%s" for the type "%s", which holds the record\'s id: name it otherwise.', $kind, $by, $key, $def->key));
                }
                if (isset($def->fields[$key])) {
                    throw new \LogicException(sprintf('The %s part %s names the key "%s", which is a field of the type "%s": rename the field or the key.', $kind, $by, $key, $def->key));
                }
                if (isset($keys[$key])) {
                    throw new \LogicException(sprintf('The %s parts %s and %s both name the key "%s" for the type "%s": one key, one part.', $kind, $keys[$key]::class, $by, $key, $def->key));
                }
                $keys[$key] = $part;
            }
        }

        return $keys;
    }
}
