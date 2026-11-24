<?php

declare(strict_types=1);

namespace Preflow\Folio\Install;

/**
 * Spec step 5, config/media.php: Folio's media library keeps its records in the storage named
 * "json" unless config/media.php names another. An app whose config/data.php has no storage of
 * that name (a driver or an alias) gets config/media.php pointing at its default storage, whatever
 * driver that is; an app with one is left alone. An existing config/media.php is kept; only a
 * missing 'storage' is added.
 *
 * @since 0.85.0
 */
final class MediaSetup
{
    /** The storage the library uses without config/media.php (preflow/media's MediaConfig). */
    private const string LIBRARY_DEFAULT = 'json';

    public function __construct(
        private readonly Setup $setup,
    ) {}

    public function run(): Outcome
    {
        $subject = 'config/media.php';
        $data = $this->setup->exists('data.php') ? $this->setup->editor->read('data.php') : null;
        if ($data === null) {
            return new Outcome(Outcome::SKIPPED, $subject, 'config/data.php is missing or does not evaluate, so the app\'s storage is unknown; the library keeps its default storage "json"');
        }
        $names = array_merge(
            array_keys(is_array($data['drivers'] ?? null) ? $data['drivers'] : []),
            array_keys(is_array($data['aliases'] ?? null) ? $data['aliases'] : []),
        );
        if (in_array(self::LIBRARY_DEFAULT, $names, true)) {
            return new Outcome(Outcome::SKIPPED, $subject, 'not needed, the app has the storage "json", where the library keeps its records by default');
        }
        $storage = $data['default'] ?? null;
        if (!is_string($storage) || $storage === '') {
            return new Outcome(Outcome::SKIPPED, $subject, 'config/data.php names no default storage; set \'storage\' in config/media.php to one of its storages');
        }
        $done = "the library keeps its records in the storage \"{$storage}\"";

        if (!$this->setup->exists('media.php')) {
            $contents = Setup::template('media.php', ['STORAGE' => Setup::literal($storage)]);

            return $this->setup->dryRun
                ? new Outcome(Outcome::PLANNED, $subject, 'write it: ' . $done, $contents)
                : Outcome::fromEdit($this->setup->editor->create('media.php', $contents), 'media.php', $done);
        }
        $media = $this->setup->editor->read('media.php');
        if ($media !== null && array_key_exists('storage', $media)) {
            return new Outcome(Outcome::ALREADY, $subject, 'the library keeps its records in the storage "' . (is_string($media['storage']) ? $media['storage'] : '?') . '"');
        }
        if ($this->setup->dryRun) {
            return new Outcome(Outcome::PLANNED, $subject, "add 'storage'", "'storage' => " . Setup::literal($storage) . ',');
        }

        return Outcome::fromEdit($this->setup->editor->addKey('media.php', [], 'storage', Setup::literal($storage)), 'media.php', "'storage' added: " . $done);
    }
}
