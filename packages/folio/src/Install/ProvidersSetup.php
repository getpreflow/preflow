<?php

declare(strict_types=1);

namespace Preflow\Folio\Install;

/**
 * Spec step 2, config/providers.php: Folio's and rbac's providers before the app's own, Folio
 * first (rbac replaces the permission resolver in boot(), and the app's wrappers must come after
 * it). "Already set up" means Folio, then rbac, before every other provider. Only insertions at
 * the start of the list (or the end, when Folio is its only entry); a list where they cannot go
 * in the right place (an app provider before Folio or rbac, Folio listed with app providers but
 * without rbac) is refused with the whole list in the right order as the snippet, never reordered.
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
        // The app's own providers (everything else) must all come after Folio and rbac.
        $own = array_keys(array_filter($names, static fn (string $n): bool => $n !== self::FOLIO && $n !== self::RBAC));
        $firstOwn = $own === [] ? PHP_INT_MAX : $own[0];
        $order = "{$folio},\n{$rbac},";
        foreach (array_values($list) as $i => $entry) {
            if (in_array($i, $own, true)) {
                $order .= "\n" . (is_string($entry) && $entry !== '' ? '\\' . ltrim($entry, '\\') . '::class' : var_export($entry, true)) . ',';
            }
        }
        $refuse = static fn (string $why): Outcome => new Outcome(Outcome::REFUSED, $subject, $why . ' folio:install does not reorder the list; make it read like this by hand (Folio, rbac, then the app\'s own providers):', $order);

        if ($at !== false && $rbacAt !== false) {
            return $at < $rbacAt && $rbacAt < $firstOwn
                ? new Outcome(Outcome::ALREADY, $subject, 'Folio and rbac are listed before the app\'s own providers')
                : $refuse('Folio and rbac are listed, but not first and in that order.');
        }
        if ($at !== false) {
            // rbac belongs right after Folio: the editor inserts only at the start or the end, so
            // this works only when Folio is the list's only entry.
            if (count($names) !== 1) {
                return $refuse($at > $firstOwn ? 'Folio\'s provider is listed after the app\'s own.' : 'rbac\'s provider belongs right after Folio\'s, between it and the app\'s own, where folio:install cannot insert.');
            }
            $items = [$rbac];
            $where = 'end';
        } elseif ($rbacAt !== false) {
            if ($rbacAt > $firstOwn) {
                return $refuse('rbac\'s provider is listed after the app\'s own.');
            }
            $items = [$folio];
            $where = 'start';
        } else {
            $items = [$folio, $rbac];
            $where = 'start';
        }
        if ($this->setup->dryRun) {
            return new Outcome(Outcome::PLANNED, $subject, "add at the {$where} of the list", implode(",\n", $items) . ',');
        }

        return Outcome::fromEdit($this->setup->editor->addToList($file, $items, $where), $file, $done);
    }
}
