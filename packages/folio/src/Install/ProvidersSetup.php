<?php

declare(strict_types=1);

namespace Preflow\Folio\Install;

/**
 * Spec step 2, config/providers.php: Folio's and rbac's providers before the app's own, Folio
 * first (rbac replaces the permission resolver in boot(), and the app's wrappers must come after
 * it). Only insertions at the start of the list (or the end, when Folio is the last entry and
 * only rbac is missing); a list where they cannot go in the right place is refused with the
 * snippet, never reordered.
 *
 * @since 0.85.0
 */
final class ProvidersSetup
{
    private const string FOLIO = 'Preflow\Folio\FolioServiceProvider';
    private const string RBAC = 'Preflow\Rbac\RbacServiceProvider';

    public function __construct(
        private readonly Setup $setup,
    ) {}

    public function run(): Outcome
    {
        $file = 'providers.php';
        $subject = 'config/providers.php';
        $folio = '\\' . self::FOLIO . '::class';
        $rbac = '\\' . self::RBAC . '::class';
        $done = 'Folio and rbac listed before the app\'s own providers';
        if (!$this->setup->exists($file)) {
            $contents = "<?php\n\nreturn [\n    {$folio},\n    {$rbac},\n];\n";

            return $this->setup->dryRun
                ? new Outcome(Outcome::PLANNED, $subject, 'write it with Folio and rbac', $contents)
                : Outcome::fromEdit($this->setup->editor->create($file, $contents), $file, 'Folio and rbac');
        }
        $list = $this->setup->editor->read($file);
        if ($list === null) {
            return new Outcome(Outcome::REFUSED, $subject, 'config/providers.php does not evaluate to an array. Add these at the start of its list by hand.', "{$folio},\n{$rbac},");
        }
        $names = array_map(static fn (mixed $c): string => is_string($c) ? ltrim($c, '\\') : '', array_values($list));
        $at = array_search(self::FOLIO, $names, true);
        $rbacAt = array_search(self::RBAC, $names, true);

        if ($at !== false && $rbacAt !== false) {
            return $at < $rbacAt
                ? new Outcome(Outcome::ALREADY, $subject, 'Folio and rbac are listed')
                : new Outcome(Outcome::REFUSED, $subject, 'rbac\'s provider is listed before Folio\'s; folio:install does not reorder the list. Move Folio\'s up by hand, so the start of the list reads:', "{$folio},\n{$rbac},");
        }
        if ($at !== false) {
            if ($at !== count($names) - 1) {
                return new Outcome(Outcome::REFUSED, $subject, 'Folio\'s provider is listed and rbac\'s is not; it belongs right after Folio\'s, which folio:install cannot insert. Add it by hand after this line:', "{$folio},\n{$rbac},");
            }
            $items = [$rbac];
            $where = 'end';
        } else {
            $items = $rbacAt === false ? [$folio, $rbac] : [$folio];
            $where = 'start';
        }
        if ($this->setup->dryRun) {
            return new Outcome(Outcome::PLANNED, $subject, "add at the {$where} of the list", implode(",\n", $items) . ',');
        }

        return Outcome::fromEdit($this->setup->editor->addToList($file, $items, $where), $file, $done);
    }
}
