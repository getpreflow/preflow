<?php

declare(strict_types=1);

namespace Preflow\Tools\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Architecture guards across the whole monorepo. Each test is one guard; its name is the id that
 * principle records cite as their enforcement.
 *
 * architecture.no-folio-in-core: the packages run without Folio. No package's source names the
 * Preflow\Folio namespace, except preflow/folio itself and the packages whose composer.json
 * *requires* preflow/folio (today: starter-folio). A package that only suggests Folio (members,
 * rbac) is core and stays Folio-free; it offers Folio integration through its own public
 * interfaces that Folio binds. The source of a package is every directory its composer.json maps
 * under autoload.psr-4 (src/ for libraries, app/ for the starters and the skeleton), all files.
 * The packages' own NoFolioDependency tests stay: they ship with each split repository and also
 * pin that package's composer shape; this guard is the single complete one across all packages.
 *
 * mail.no-folio-name: no mail says "Folio" (a recipient never sees the admin's product name). The
 * mail words live in two places, and both are checked in every package and locale:
 *  - mail Twig templates: every *.twig below a templates/mail/ directory of a package (today
 *    notify's templates/mail/layout.twig, the HTML part of every composed mail); Twig comments
 *    are removed first, since they never reach a mail;
 *  - mail texts in the lang files: the whole of every packages/{pkg}/lang/{locale}/*mail*.php
 *    file (rbac's rbac-mail.php) and the 'mail' key of every other lang file (folio.php's
 *    folio.mail.*, members.php's members.mail.*).
 * Mail-sending classes (MemberMailer, StaffMail, Folio's StaffMailer, notify's BasicMailLayout)
 * take every word from these keys and add markup only, so their PHP is not scanned.
 *
 * storage.no-driver-class-checks: outside packages/data/src/Driver/ no source asks a storage driver
 * for its class: no `instanceof` and no parameter type naming PdoDriver, MysqlDriver, SqliteDriver
 * or JsonFileDriver (short, `Driver\…` or fully qualified). Code that needs an ability asks for its
 * capability interface (RunsSql, PdoConnection, Transactions, AtomicCounters, UniqueIndexes,
 * RankedSearch, DescribesColumns), so a new driver gets the same treatment by implementing it.
 * Building a driver (`new …`) is allowed (core's factory, preflow/testing). Scanned: every
 * package's psr-4 source roots and tools/Convert.
 *
 * packages.requires-what-it-names: a package whose source names another package's class (or
 * interface, trait, enum) that says `@since X.Y` in its docblock keeps Composer from pairing it with
 * a release before X.Y: its `conflict` for that package is `<X.Y` or later, and where it requires
 * the package, the require's lowest version is X.Y or later. A constraint can't be tightened for a
 * version after it is published, so this runs before every release. Methods are not covered (their
 * @since sits on the method); the per-package ComposerConstraintsTest names those.
 *
 * preview.admit-only: outside packages/core/src, a preview token is checked with
 * PreviewToken::admit() only, never verify() (which skips the PreviewGate, so a token would outlive
 * a revoked permission or role). In every package's psr-4 source roots except core's, a file that
 * names PreviewToken may not call ->verify( on any line.
 *
 * write.accounting-allowlisted: an 'accounting' write runs under every write policy (Allow, Skip
 * and Refuse: a preview or an audit counts it too), so each one is listed in ACCOUNTING_WRITES with
 * its reason. In every package's psr-4 source roots, a code line that passes `accounting:` (other
 * than `false`) or names the operation 'accounting', and a ->hit( or ->incrementIn( call whose
 * accounting argument (the 4th / 7th) is a literal `true` (read with the tokenizer, also over
 * several lines), must be listed; a listed line that no longer exists fails too. A write that
 * accounts for spending outside DataManager (the AI usage log) names itself with
 * Writes::allowed(…, 'accounting'), so it is listed too.
 *
 * architecture.no-ambient-state: package code reads who is looking, the locale, the read mode and
 * the time from the render context (Contexts::current()), not from state that belongs to the
 * process or the request. Every package's psr-4 source roots are scanned (every *.php file, the
 * skeleton and the starters too, since new sites copy them) for the rules in AMBIENT_RULES:
 *  - superglobal: $_SERVER, $_COOKIE, $_SESSION, $_GET, $_POST, $_REQUEST, $_FILES (also through
 *    $GLOBALS), and fromGlobals(), which turns them into a request;
 *  - clock: time(), microtime(), hrtime(), date()/gmdate() with only a format, strtotime() of a
 *    string, new DateTime(Immutable) with no argument (with or without parentheses), 'now' or a
 *    relative string; a line that ends with `// clock: stamp` (a file name, a write stamp, a log
 *    line) is no hit. Durations and deadlines (hrtime/microtime differences) are allowlisted: a
 *    frozen context clock must not apply to them;
 *  - setter: the ambient mutators the release deprecates: setLocale, setUser, addUser,
 *    showWorkingCopies, useOverrides, useTranslator, setCurrent, discardingQueued,
 *    ContentLocale::set and a content or page locale's ->set(), FolioText::use,
 *    InternalRender::mark, the engine global 'interface_locale', RenderScopes::add, and isolate()
 *    or isolated() on LiveContent, the PageCache and the CookieQueue. Those last are recognised by
 *    their receiver (the class, or a variable or property named after it: $live, $pageCache,
 *    ->cache, $cookieQueue), since the memos' isolate() stays. Also Application::holdMailForFlush(),
 *    a test seam that holds a request's mail for the next flush: never called from package code;
 *  - static-state: a static property or static variable (state shared by every render);
 *  - context-property: a property or promoted parameter typed RenderContext (services read
 *    Contexts::current(), they don't keep a copy);
 *  - peek: Contexts::peek(), only for code with a documented default outside a render;
 *  - context-dump: var_export(), get_object_vars() or an (array) cast of a context (they walk its
 *    properties and reach the request; serialize, json_encode and var_dump give the safe form);
 *  - frame-context-write: setting a frame's context from outside it (only Contexts narrows);
 *  - request-binding: binding request state (RequestContext, CookieQueue) into the container with
 *    instance(), singleton() or bind(), where every later render would find it.
 * The rules see one line at a time, after comment lines and trailing comments are removed (strings
 * stay). Continuation lines are not joined: keep a call and its first argument on one line (a
 * multi-line `new \DateTimeImmutable(` … `)` or `->instance(` … `RequestContext::class` is not
 * seen). context-dump and frame-context-write recognise a context by its name: a variable or
 * property holding a RenderContext is named …context or …ctx ($context, $ctx, $this->context),
 * and only a frame's context is written as `->context =` from outside the object.
 * Two files decide what may stay. tools/tests/ambient-state-allow.php is permanent: per file (a
 * glob is allowed) and rule, a reason, or per line text a reason; each entry must still match a
 * hit. tools/tests/ambient-state-baseline.php is the ratchet: today's hits that the render-context
 * release moves onto the context, one entry per hit, as the trimmed line text (line numbers move).
 * A hit in neither file fails; a baseline entry that no longer hits fails too and must be removed,
 * so the baseline only shrinks. A baselined line whose text changed fails both ways; update its
 * text in the baseline, don't allowlist it. All failures come in one message.
 */
final class ArchitectureGuardsTest extends TestCase
{
    private const FOLIO = 'preflow/folio';

    private const DRIVERS = '(?:Preflow\\\\Data\\\\Driver\\\\|Driver\\\\)?(PdoDriver|MysqlDriver|SqliteDriver|JsonFileDriver)';

    /**
     * A driver class check that must stay for a release names its file and line here; none since
     * 0.78. Each line must be found exactly once; remove the entry when its line goes.
     *
     * @var array<string, list<string>>
     */
    private const PENDING_DRIVER_CHECKS = [];

    /**
     * The writes that count under every write policy (write.accounting-allowlisted): file (relative
     * to packages/) => trimmed line => reason. Each entry must still match a line.
     *
     * @var array<string, array<string, string>>
     */
    private const ACCOUNTING_WRITES = [
        'core/src/Context/Writes.php' => [
            "if (\$policy === WritePolicy::Allow || \$operation === 'accounting') {" => 'the policy itself: an accounting write is allowed and never reported',
        ],
        'data/src/DataManager.php' => [
            "if (!Writes::allowed(\$def->storage, \$def->table, (string) \$id, \$accounting ? 'accounting' : 'increment')) {" => 'incrementIn() passes its caller\'s flag on (Throttle::hit() passes its own); the callers are the other entries',
        ],
        'ai/src/Usage/FileUsageLog.php' => [
            "if (!Writes::allowed('ai', 'usage', null, 'accounting')) {" => 'the AI usage log: every call and its cost really happened, in a preview or an audit too, so its line is written under every policy',
        ],
        'ai/src/Limits/AiLimits.php' => [
            "\$hits = \$this->throttle->hit(self::purpose(\$action), \$client, \$limit['per'], accounting: true);" => 'the per-person AI limit: the AI call it lets through really happens in a preview or an audit too, so it must be counted there',
        ],
    ];

    /** A context in an expression: Contexts::current()/peek(), or a variable or property named …context or …ctx. */
    private const CONTEXT_EXPRESSION = '(?:\\\\?(?:\w+\\\\)*Contexts::(?:current|peek)\(\)|\$[\w>-]*?(?:[cC]ontext|ctx)\b(?!\s*\[))';

    /**
     * The rules of architecture.no-ambient-state: rule => pattern for one source line (see the class
     * docblock). Patterns run on the line's code: comment lines and trailing comments are removed
     * first (strings stay). A clock line ending in `// clock: stamp` is exempt.
     */
    private const AMBIENT_RULES = [
        'superglobal' => '/\$_(?:SERVER|COOKIE|SESSION|GET|POST|REQUEST|FILES)\b'
            . '|\$GLOBALS\[\s*[\'"]_(?:SERVER|COOKIE|SESSION|GET|POST|REQUEST|FILES)[\'"]|\bfromGlobals\(/',
        'clock' => '/(?<![\w$>:])(?<!function )\\\\?(?:time\(\)|(?:microtime|hrtime)\('
            . '|(?:gm)?date\(\s*(?:\'[^\']*\'|"[^"]*"|\\\\?DATE_\w+|\\\\?DateTime(?:Interface)?::\w+)\s*\)'
            . '|strtotime\(\s*(?:\'[^\']*\'|"[^"]*")\s*\))'
            . '|\bnew\s+\\\\?DateTime(?:Immutable)?\b(?!\s*\()'
            . '|\bnew\s+\\\\?DateTime(?:Immutable)?\(\s*(?:\)|[\'"]now[\'"]|\'[-+]\d|\'-\'\s*\.|\'[^\']*\bago\''
            . '|\'(?:today|tomorrow|yesterday|midnight|noon|first |last |next |this ))/i',
        'setter' => '/->(?:setLocale|setUser|addUser|showWorkingCopies|useOverrides|useTranslator|setCurrent|discardingQueued|holdMailForFlush)\('
            . '|\bContentLocale::set\b|\b(?:FolioText::use|InternalRender::mark)\('
            . '|[cC]ontentLocale\??->set\(|->set\(\$?locale\s*\)'
            . '|->addGlobal\(\s*[\'"]interface_locale[\'"]'
            . '|(?:CookieQueue::class\)|\$\w*[cC]ookieQueue|->cookieQueue)\??->isolated\('
            . '|(?:LiveContent::class\)|\$live(?:Content)?|->live(?:Content)?)\??->isolate\('
            . '|(?:PageCache::class\)|\$\w*[pP]ageCache|->(?:pageCache|cache))\??->isolated?\('
            . '|RenderScopes::class\)->add\(/',
        'static-state' => '/^(?!.*\breadonly\b).*(?<![\w$>:\\\\])static\s+(?!function\b|fn\b)(?:\??[A-Za-z_\\\\][\w\\\\|&]*\s+)?\$\w+/',
        'context-property' => '/\b(?:public|protected|private|readonly|var)(?:\(set\))?\s+'
            . '(?:(?:public|protected|private|readonly|static|final)(?:\(set\))?\s+)*'
            . '(?:\??[\w\\\\]+\s*[|&]\s*)*\??\\\\?(?:\w+\\\\)*RenderContext\s*(?:[|&]\s*\??[\w\\\\]+\s*)*\$\w+/',
        'peek' => '/\bContexts::peek\(/',
        'context-dump' => '/\b(?:var_export|get_object_vars)\(\s*' . self::CONTEXT_EXPRESSION . '|\(array\)\s*' . self::CONTEXT_EXPRESSION . '/',
        'frame-context-write' => '/(?<!\$this)->context\s*(?:\?\?)?=(?![=>])/',
        'request-binding' => '/->(?:instance|singleton|bind)\(\s*\\\\?(?:\w+\\\\)*(?:RequestContext|CookieQueue)::class/',
    ];

    private static function packages(): string
    {
        return dirname(__DIR__, 2) . '/packages';
    }

    public function test_architecture_no_folio_in_core(): void
    {
        $hits = [];
        $scanned = [];
        $exempt = [];
        foreach (glob(self::packages() . '/*/composer.json') ?: [] as $composerFile) {
            $dir = dirname($composerFile);
            $composer = json_decode((string) file_get_contents($composerFile), true, flags: JSON_THROW_ON_ERROR);
            if (($composer['name'] ?? null) === self::FOLIO || isset($composer['require'][self::FOLIO])) {
                $exempt[] = basename($dir);
                continue;
            }
            $roots = array_values((array) ($composer['autoload']['psr-4'] ?? []));
            $roots = $roots === [] ? ['src/'] : $roots;
            foreach ($roots as $root) {
                foreach ((array) $root as $path) {
                    $sourceDir = $dir . '/' . rtrim($path, '/');
                    if (!is_dir($sourceDir)) {
                        continue;
                    }
                    $scanned[basename($dir)] = true;
                    foreach ($this->files($sourceDir) as $file) {
                        foreach (file($file) ?: [] as $n => $line) {
                            if (preg_match('/Preflow\\\\{1,2}Folio\b/', $line) === 1) {
                                $hits[] = substr($file, strlen(self::packages()) + 1) . ':' . ($n + 1) . ': ' . trim($line);
                            }
                        }
                    }
                }
            }
        }

        sort($exempt);
        $this->assertSame(['folio', 'starter-folio'], $exempt, 'the packages allowed to name Folio');
        $this->assertArrayHasKey('core', $scanned);
        $this->assertArrayHasKey('members', $scanned, 'suggesting Folio does not exempt a package');
        $this->assertArrayHasKey('starter', $scanned, 'the starters are scanned by their app/ source');
        $this->assertSame([], $hits, 'core packages must not name Preflow\\Folio');
    }

    public function test_preview_admit_only(): void
    {
        $hits = [];
        $scanned = [];
        foreach (glob(self::packages() . '/*/composer.json') ?: [] as $composerFile) {
            $dir = dirname($composerFile);
            if (basename($dir) === 'core') {
                continue;
            }
            $composer = json_decode((string) file_get_contents($composerFile), true, flags: JSON_THROW_ON_ERROR);
            $roots = array_values((array) ($composer['autoload']['psr-4'] ?? []));
            foreach ($roots === [] ? ['src/'] : $roots as $root) {
                foreach ((array) $root as $path) {
                    $sourceDir = $dir . '/' . rtrim($path, '/');
                    if (!is_dir($sourceDir)) {
                        continue;
                    }
                    $scanned[basename($dir)] = true;
                    foreach ($this->files($sourceDir) as $file) {
                        if (str_ends_with($file, '.php')) {
                            foreach (self::previewVerifyHits((string) file_get_contents($file)) as $n => $line) {
                                $hits[] = $this->relative($file) . ':' . $n . ': ' . $line;
                            }
                        }
                    }
                }
            }
        }

        $this->assertArrayHasKey('auth', $scanned);
        $this->assertArrayHasKey('folio', $scanned);
        $this->assertSame([], $hits, 'outside core, a preview token is checked with PreviewToken::admit(), never verify() (it skips the gate)');
    }

    public function test_preview_admit_only_catches_verify(): void
    {
        $this->assertSame([3 => '$claims = $this->tokens->verify($token, $sid, $viewer, $now);'], self::previewVerifyHits("<?php\nuse Preflow\\Core\\Context\\Preview\\PreviewToken;\n\$claims = \$this->tokens->verify(\$token, \$sid, \$viewer, \$now);\n"));
        $this->assertSame([], self::previewVerifyHits("<?php\nuse Preflow\\Core\\Context\\Preview\\PreviewToken;\n\$claims = \$this->tokens->admit(\$token, \$sid, \$ctx, \$gate);\n"));
        $this->assertSame([], self::previewVerifyHits("<?php\n\$ok = \$csrf->verify(\$token);\n"), 'a file that does not name PreviewToken');
        $this->assertSame([], self::previewVerifyHits("<?php\nuse Preflow\\Core\\Context\\Preview\\PreviewToken;\n// \$tokens->verify() skips the gate\n"), 'a comment');
    }

    public function test_write_accounting_allowlisted(): void
    {
        $found = [];
        $unlisted = [];
        foreach (glob(self::packages() . '/*/composer.json') ?: [] as $composerFile) {
            $dir = dirname($composerFile);
            $composer = json_decode((string) file_get_contents($composerFile), true, flags: JSON_THROW_ON_ERROR);
            $roots = array_values((array) ($composer['autoload']['psr-4'] ?? []));
            foreach ($roots === [] ? ['src/'] : $roots as $root) {
                foreach ((array) $root as $path) {
                    $sourceDir = $dir . '/' . rtrim($path, '/');
                    if (!is_dir($sourceDir)) {
                        continue;
                    }
                    foreach ($this->files($sourceDir) as $file) {
                        if (!str_ends_with($file, '.php')) {
                            continue;
                        }
                        $relative = $this->relative($file);
                        foreach (self::accountingHits((string) file_get_contents($file)) as $n => $line) {
                            if (isset(self::ACCOUNTING_WRITES[$relative][$line])) {
                                $found[$relative][$line] = true;
                            } else {
                                $unlisted[] = $relative . ':' . $n . ': ' . $line;
                            }
                        }
                    }
                }
            }
        }

        $stale = [];
        foreach (self::ACCOUNTING_WRITES as $file => $lines) {
            foreach (array_keys($lines) as $line) {
                if (!isset($found[$file][$line])) {
                    $stale[] = $file . ': ' . $line;
                }
            }
        }
        $this->assertSame([], $unlisted, 'an accounting write runs in previews and audits too: list it in ACCOUNTING_WRITES with its reason');
        $this->assertSame([], $stale, 'these ACCOUNTING_WRITES entries match no line: remove them');
    }

    public function test_write_accounting_allowlisted_catches_each_spelling(): void
    {
        $caught = [
            '$this->throttle->hit($purpose, $client, 60, accounting: true);',
            '$dm->incrementIn($def, $id, \'n\', 1, [], accounting: $count);',
            '$dm->incrementIn($def, $id, \'n\', 1, [], null, true);',
            '$this->throttle->hit($purpose, $client, 60, true);',
            'Writes::allowed(\'ai\', \'usage\', null, \'accounting\');',
            'Writes::allowed("ai", "usage", null, "accounting");',
            '$this->throttle->hit($purpose, $client, 60, true,);',
            '$this->throttle?->hit($purpose, $client, 60, TRUE);',
        ];
        foreach ($caught as $line) {
            $this->assertSame([2 => $line], self::accountingHits("<?php\n" . $line . "\n"), "caught: {$line}");
        }
        $this->assertSame(
            [2 => '$hits = $this->dm->incrementIn('],
            self::accountingHits("<?php\n\$hits = \$this->dm->incrementIn(\n    \$def,\n    \$id,\n    'hits',\n    1,\n    ['a' => [1, 2]],\n    new CounterReset('w', 1, []),\n    true,\n);\n"),
            'a call over several lines, its true on a line of its own',
        );
        $harmless = [
            '$this->throttle->hit($purpose, $client, 60);',
            '$this->throttle->hit($purpose, $client, 60, accounting: false);',
            '$dm->incrementIn($def, $id, \'n\', 1, [], null, $accounting);',
            ' * \'accounting\' writes run under every policy',
            '$x = 1; // accounting: true',
            '$ok = $this->check(true);',
            '$this->throttle->hit($purpose, $client, true);',
            '$this->throttle->hit($purpose, $client, 60, $this->hits(true));',
            '$dm->incrementIn($def, $id, \'n\', 1, [], true, $accounting);',
        ];
        foreach ($harmless as $line) {
            $this->assertSame([], self::accountingHits("<?php\n" . $line . "\n"), "harmless: {$line}");
        }
    }

    /**
     * Each accounting write in $source (write.accounting-allowlisted): a line that passes
     * `accounting:` with anything but `false` or names the operation 'accounting', and every
     * ->hit( / ->incrementIn( call whose accounting argument (4th / 7th) is a literal `true`, read
     * with the tokenizer, so a call spread over lines or with a trailing comma counts too (it is
     * reported at the line of the method name).
     *
     * @return array<int, string> line number => trimmed line
     */
    private static function accountingHits(string $source): array
    {
        $lines = explode("\n", $source);
        $hits = [];
        foreach ($lines as $n => $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '//')) {
                continue;
            }
            if (preg_match('/\baccounting:\s*(?!false\b)\S|[\'"]accounting[\'"]/', self::withoutComments($line)) === 1) {
                $hits[$n + 1] = trim($line);
            }
        }

        $position = ['hit' => 3, 'incrementIn' => 6];
        $tokens = array_values(array_filter(
            \PhpToken::tokenize($source),
            static fn (\PhpToken $t): bool => !$t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
        ));
        foreach ($tokens as $i => $token) {
            if (!$token->is(T_STRING) || !isset($position[$token->text])
                || !($tokens[$i - 1] ?? null)?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])
                || ($tokens[$i + 1] ?? null)?->text !== '(') {
                continue;
            }
            $args = [[]];
            $depth = 0;
            for ($j = $i + 2; $j < count($tokens); $j++) {
                $text = $tokens[$j]->text;
                if (in_array($text, ['(', '[', '{'], true) || $tokens[$j]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                    $depth++;
                } elseif (in_array($text, [')', ']', '}'], true)) {
                    if ($depth === 0) {
                        break;
                    }
                    $depth--;
                } elseif ($text === ',' && $depth === 0) {
                    $args[] = [];
                    continue;
                }
                $args[array_key_last($args)][] = $tokens[$j];
            }
            $argument = $args[$position[$token->text]] ?? [];
            if (count($argument) === 1 && strtolower($argument[0]->text) === 'true') {
                $hits[$token->line] = trim($lines[$token->line - 1]);
            }
        }
        ksort($hits);

        return $hits;
    }

    /** @return array<int, string> line number => trimmed line of each ->verify( call in a file that names PreviewToken */
    private static function previewVerifyHits(string $source): array
    {
        if (!str_contains($source, 'PreviewToken')) {
            return [];
        }
        $hits = [];
        foreach (explode("\n", $source) as $n => $line) {
            $code = self::withoutComments($line);
            if (preg_match('/->verify\(/', $code) === 1) {
                $hits[$n + 1] = trim($line);
            }
        }

        return $hits;
    }

    public function test_mail_no_folio_name(): void
    {
        $hits = [];
        $sources = [];

        foreach (glob(self::packages() . '/*/templates/mail') ?: [] as $mailDir) {
            foreach ($this->files($mailDir) as $file) {
                if (!str_ends_with($file, '.twig')) {
                    continue;
                }
                $sources[] = $this->relative($file);
                $text = (string) preg_replace('/\{#.*?#\}/s', '', (string) file_get_contents($file));
                if (preg_match('/\bfolio\b/i', $text) === 1) {
                    $hits[] = $this->relative($file);
                }
            }
        }

        foreach (glob(self::packages() . '/*/lang/*/*.php') ?: [] as $file) {
            $texts = require $file;
            if (!is_array($texts)) {
                continue;
            }
            $prefix = basename($file, '.php');
            if (!str_contains($prefix, 'mail')) {
                if (!isset($texts['mail'])) {
                    continue;
                }
                $texts = ['mail' => $texts['mail']];
            }
            $sources[] = $this->relative($file);
            array_walk_recursive($texts, function (mixed $value, int|string $key) use (&$hits, $file): void {
                if (is_string($value) && preg_match('/\bfolio\b/i', $value) === 1) {
                    $hits[] = $this->relative($file) . ' [' . $key . ']: ' . $value;
                }
            });
        }

        foreach (['notify/templates/mail/layout.twig', 'rbac/lang/en/rbac-mail.php', 'folio/lang/en/folio.php', 'members/lang/en/members.php'] as $known) {
            $this->assertContains($known, $sources, 'a known mail source is checked');
        }
        $this->assertSame([], $hits, 'mails must not say Folio');
    }

    public function test_storage_no_driver_class_checks(): void
    {
        $driverDir = self::packages() . '/data/src/Driver/';
        $sources = [];
        foreach (glob(self::packages() . '/*/composer.json') ?: [] as $composerFile) {
            $dir = dirname($composerFile);
            $composer = json_decode((string) file_get_contents($composerFile), true, flags: JSON_THROW_ON_ERROR);
            $roots = array_values((array) ($composer['autoload']['psr-4'] ?? []));
            $roots = $roots === [] ? ['src/'] : $roots;
            foreach ($roots as $root) {
                foreach ((array) $root as $path) {
                    $sourceDir = $dir . '/' . rtrim($path, '/');
                    if (is_dir($sourceDir)) {
                        $sources[basename($dir)][] = $sourceDir;
                    }
                }
            }
        }
        $sources['tools/Convert'] = [dirname(__DIR__) . '/Convert'];

        $hits = [];
        foreach ($sources as $sourceDirs) {
            foreach ($sourceDirs as $sourceDir) {
                foreach ($this->files($sourceDir) as $file) {
                    if (str_starts_with($file, $driverDir) || !str_ends_with($file, '.php')) {
                        continue;
                    }
                    foreach (file($file) ?: [] as $n => $line) {
                        if (self::checksDriverClass($line)) {
                            $hits[] = [$this->relativeToRepo($file), $n + 1, trim($line)];
                        }
                    }
                }
            }
        }

        $unexpected = [];
        $pendingSeen = [];
        foreach ($hits as [$file, $lineNo, $text]) {
            $pendingFile = str_starts_with($file, 'packages/') ? substr($file, strlen('packages/')) : $file;
            if (in_array($text, self::PENDING_DRIVER_CHECKS[$pendingFile] ?? [], true)) {
                $pendingSeen[$pendingFile . ': ' . $text] = ($pendingSeen[$pendingFile . ': ' . $text] ?? 0) + 1;
                continue;
            }
            $unexpected[] = $file . ':' . $lineNo . ': ' . $text;
        }

        foreach (['auth', 'core', 'folio', 'data', 'tools/Convert'] as $package) {
            $this->assertArrayHasKey($package, $sources, 'a scanned source');
        }
        $this->assertSame([], $unexpected, 'ask for a capability interface, not a driver class');
        foreach (self::PENDING_DRIVER_CHECKS as $file => $lines) {
            foreach ($lines as $text) {
                $this->assertSame(
                    1,
                    $pendingSeen[$file . ': ' . $text] ?? 0,
                    "pending driver check {$file}: {$text} must be found exactly once (remove a stale entry)",
                );
            }
        }
    }

    /**
     * Every package must require each package whose namespace it names, at a version that carries
     * the classes it uses (@since).
     */
    public function test_packages_requires_what_it_names(): void
    {
        $namespaces = [];
        $packages = [];
        foreach (glob(self::packages() . '/*/composer.json') ?: [] as $composerFile) {
            $dir = dirname($composerFile);
            $composer = json_decode((string) file_get_contents($composerFile), true, flags: JSON_THROW_ON_ERROR);
            $packages[$dir] = $composer;
            foreach ((array) ($composer['autoload']['psr-4'] ?? []) as $prefix => $path) {
                $namespaces[$prefix] = [(string) $composer['name'], $dir . '/' . rtrim((string) $path, '/')];
            }
        }
        uksort($namespaces, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $missing = [];
        $checked = 0;
        foreach ($packages as $dir => $composer) {
            if (self::isStarter($composer)) {
                continue;
            }
            $name = (string) $composer['name'];
            foreach ((array) ($composer['autoload']['psr-4'] ?? []) as $path) {
                $sourceDir = $dir . '/' . rtrim((string) $path, '/');
                if (!is_dir($sourceDir)) {
                    continue;
                }
                foreach ($this->files($sourceDir) as $file) {
                    if (!str_ends_with($file, '.php')) {
                        continue;
                    }
                    preg_match_all('/(?<![A-Za-z0-9_])(Preflow(?:\\\\[A-Za-z0-9_]+)+)/', (string) file_get_contents($file), $m);
                    foreach (array_unique($m[1]) as $class) {
                        foreach ($namespaces as $prefix => [$owner, $ownerDir]) {
                            if (!str_starts_with($class . '\\', $prefix)) {
                                continue;
                            }
                            if ($owner === $name) {
                                break;
                            }
                            $since = self::classSince($ownerDir . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php');
                            if ($since === null) {
                                break;
                            }
                            $checked++;
                            $require = $composer['require'][$owner] ?? null;
                            $conflict = $composer['conflict'][$owner] ?? null;
                            $requireOk = $require === null
                                || (preg_match('/\^(\d+\.\d+(?:\.\d+)?)/', (string) $require, $r) === 1 && version_compare($r[1], $since, '>='));
                            $conflictOk = $conflict !== null && version_compare(ltrim((string) $conflict, '<'), $since, '>=');
                            if (!$requireOk || !$conflictOk) {
                                $missing[] = sprintf(
                                    '%s names %s (@since %s): require %s, conflict %s',
                                    $name,
                                    $class,
                                    $since,
                                    $require ?? '-',
                                    $conflict ?? '-',
                                );
                            }
                            break;
                        }
                    }
                }
            }
        }

        $this->assertGreaterThan(50, $checked, 'the scan found @since classes named across packages');
        $this->assertSame([], array_values(array_unique($missing)), 'raise the require and conflict to the version the named class arrived in');
    }

    /** The @since version in a class-like declaration's docblock, or null. */
    private static function classSince(string $file): ?string
    {
        if (!is_file($file)) {
            return null;
        }
        $pattern = '/\/\*\*((?:(?!\*\/).)*)\*\/\s*(?:#\[[^\]]*\]\s*)*(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s/s';
        if (preg_match($pattern, (string) file_get_contents($file), $doc) !== 1) {
            return null;
        }

        return preg_match('/@since\s+(\d+\.\d+(?:\.\d+)?)/', $doc[1], $since) === 1 ? $since[1] : null;
    }

    /** Starters and the skeleton are apps (they require released versions; the release checklist raises them). */
    private static function isStarter(array $composer): bool
    {
        return ($composer['type'] ?? 'library') === 'project';
    }

    /**
     * Each spelling the guard must catch, and lines it must leave alone (building a driver,
     * naming one in a comment, a capability check).
     */
    public function test_storage_driver_class_check_patterns(): void
    {
        $caught = [
            'instanceof' => 'if ($driver instanceof PdoDriver) {',
            'instanceof, fully qualified' => 'return $d instanceof \Preflow\Data\Driver\MysqlDriver;',
            'instanceof, Driver\\' => '$ok = $d instanceof Driver\SqliteDriver;',
            'parameter' => 'public function run(PdoDriver $driver): void',
            'nullable parameter' => 'function f(int $a, ?JsonFileDriver $d = null) {}',
            'parameter on its own line' => '        PdoDriver $driver,',
            'nullable parameter on its own line' => '        ?\Preflow\Data\Driver\MysqlDriver $driver = null,',
            'promoted parameter' => '        private readonly PdoDriver $driver,',
            'public promoted parameter' => 'public function __construct(public PdoDriver $d) {}',
            'promoted nullable parameter' => '        protected ?SqliteDriver $driver = null,',
            'union type' => 'function f(int|PdoDriver $d) {}',
            'union type, driver first' => 'function f(PdoDriver|string $d) {}',
            'intersection type' => 'function f(RunsSql&PdoDriver $d) {}',
            'by reference' => 'function f(PdoDriver &$d) {}',
            'variadic' => 'function f(PdoDriver ...$d) {}',
            'is_a' => 'if (is_a($driver, PdoDriver::class)) {',
            'is_a, fully qualified' => 'if (is_a($driver, \Preflow\Data\Driver\SqliteDriver::class, true)) {',
            'is_subclass_of' => 'if (is_subclass_of($driver, Driver\PdoDriver::class)) {',
            '::class ===' => 'if ($driver::class === MysqlDriver::class) {',
            '=== ::class' => 'if (MysqlDriver::class === $driver::class) {',
            '!== ::class' => 'if ($driver::class !== JsonFileDriver::class) {',
            'get_class ===' => 'if (get_class($driver) === SqliteDriver::class) {',
            'get_class ==' => 'if (get_class($driver) == SqliteDriver::class) {',
        ];
        foreach ($caught as $spelling => $line) {
            $this->assertTrue(self::checksDriverClass($line), "caught: {$spelling}: {$line}");
        }

        $allowed = [
            'building a driver' => '$x = new PdoDriver($pdo, $dialect);',
            'building one, fully qualified' => 'return new \Preflow\Data\Driver\MysqlDriver($pdo);',
            'a driver class in config' => "'driver' => \\Preflow\\Data\\Driver\\JsonFileDriver::class,",
            'a use statement' => 'use Preflow\Data\Driver\PdoDriver;',
            'a docblock' => '     * @param PdoDriver $driver the driver',
            'a nullable docblock type' => '     * @param ?PdoDriver $driver',
            'a line comment' => '        // $driver instanceof PdoDriver was the old check',
            'a hash comment' => '        # is_a($d, PdoDriver::class)',
            'a block comment line' => '/* function f(PdoDriver $d) */',
            'a capability check' => 'if ($driver instanceof RunsSql) {',
            'a longer class name' => 'function f(PdoDriverFactory $f) {}',
            'a capability parameter' => 'function f(PdoConnection $driver) {}',
            // preflow/testing builds its drivers and keeps them in typed properties
            'a property holding a built driver' => '    private ?SqliteDriver $sqliteDriver = null;',
            'a property without a default' => '    private MysqlDriver $driver;',
        ];
        foreach ($allowed as $spelling => $line) {
            $this->assertFalse(self::checksDriverClass($line), "not caught: {$spelling}: {$line}");
        }
    }

    /**
     * Whether a source line asks a built-in storage driver for its class: `instanceof`, a parameter
     * type (on any line, promoted or not, nullable, union or intersection, by reference, variadic),
     * `is_a()`, `is_subclass_of()`, or a `::class` / `get_class()` comparison. Comment lines,
     * `new …` and a property that holds a driver the class built (preflow/testing) are not checks.
     */
    private static function checksDriverClass(string $line): bool
    {
        $code = ltrim($line);
        if ($code === '' || str_starts_with($code, '*') || str_starts_with($code, '/*') || str_starts_with($code, '//')
            || (str_starts_with($code, '#') && !str_starts_with($code, '#['))) {
            return false;
        }
        $driver = '\\\\?' . self::DRIVERS;
        $patterns = [
            // instanceof
            '/\binstanceof\s+' . $driver . '\b/',
            // a parameter type: after "(", ",", "|", "&", "?", a modifier (promoted) or the start of the
            // line, before "$", "&$" or "...$"; not a property ("$name;" or "$name = …;")
            '/(?:^|[(,|&?]|\b(?:public|protected|private|readonly|var|static))\s*\??' . $driver
                . '\s*(?:[|&]\s*\??\\\\?[\w\\\\]+\s*)*(?:&\s*)?(?:\.\.\.\s*)?\$\w++(?!\s*(?:=[^,)]*)?;)/',
            // is_a() / is_subclass_of() against a driver class
            '/\b(?:is_a|is_subclass_of)\s*\(.*' . $driver . '::class\b/',
            // a ::class or get_class() comparison
            '/' . $driver . '::class\s*[!=]==?|[!=]==?\s*' . $driver . '::class\b/',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line) === 1) {
                return true;
            }
        }

        return false;
    }

    public function test_architecture_no_ambient_state(): void
    {
        /** @var array<string, array<string, string|array<string, string>>> $allow */
        $allow = require __DIR__ . '/ambient-state-allow.php';
        /** @var array<string, list<string>> $baseline */
        $baseline = require __DIR__ . '/ambient-state-baseline.php';

        $hits = [];
        $scanned = [];
        foreach (glob(self::packages() . '/*/composer.json') ?: [] as $composerFile) {
            $dir = dirname($composerFile);
            $composer = json_decode((string) file_get_contents($composerFile), true, flags: JSON_THROW_ON_ERROR);
            $roots = array_values((array) ($composer['autoload']['psr-4'] ?? []));
            $roots = $roots === [] ? ['src/'] : $roots;
            foreach ($roots as $root) {
                foreach ((array) $root as $path) {
                    $sourceDir = $dir . '/' . rtrim($path, '/');
                    if (!is_dir($sourceDir)) {
                        continue;
                    }
                    $scanned[basename($dir)] = true;
                    foreach ($this->files($sourceDir) as $file) {
                        if (!str_ends_with($file, '.php')) {
                            continue;
                        }
                        foreach (self::ambientStateHits($file) as [$lineNo, $text, $rules]) {
                            $hits[] = [$this->relative($file), $lineNo, $text, $rules];
                        }
                    }
                }
            }
        }

        foreach (['core', 'auth', 'folio', 'notify'] as $package) {
            $this->assertArrayHasKey($package, $scanned, 'a scanned package');
        }
        $verdict = self::ambientStateVerdict($hits, $allow, $baseline);
        if ($verdict !== '') {
            $this->fail($verdict);
        }
        $this->addToAssertionCount(1);
    }

    /**
     * The guard's ratchet on synthetic hits: one message for every kind of failure, and the hint
     * for a baselined line whose text changed (it must be updated in the baseline, not allowlisted).
     */
    public function test_architecture_no_ambient_state_ratchet_messages(): void
    {
        $allow = ['core/src/Clock.php' => ['clock' => 'the clock'], 'core/src/Gone.php' => ['clock' => 'gone']];
        $baseline = [
            'auth/src/A.php' => ['$auth->setUser($user, $name);', '$auth->setUser($user, $name);'],
            'auth/src/B.php' => ['$at = time();'],
        ];
        $hits = [
            ['core/src/Clock.php', 3, 'return time();', ['clock']],
            ['auth/src/A.php', 10, '$auth->setUser($user, $name);', ['setter']],
            ['auth/src/A.php', 20, '$auth->setUser($user, guard: $name);', ['setter']],
            ['folio/src/C.php', 5, '$_SESSION = [];', ['superglobal']],
        ];

        $verdict = self::ambientStateVerdict($hits, $allow, $baseline);

        $this->assertStringContainsString('folio/src/C.php:5 [superglobal] $_SESSION = [];', $verdict, 'an unexpected hit names file:line');
        $this->assertStringContainsString('auth/src/A.php:20 [setter] $auth->setUser($user, guard: $name);', $verdict);
        $this->assertStringContainsString('auth/src/A.php: $auth->setUser($user, $name); (listed 2, hits 1)', $verdict, 'a stale entry with its counts');
        $this->assertStringContainsString('auth/src/B.php: $at = time(); (listed 1, hits 0)', $verdict);
        $this->assertStringContainsString('core/src/Gone.php [clock]', $verdict, 'an allowlist entry that matches nothing');
        $this->assertStringContainsString(
            "auth/src/A.php: a baselined line changed? update its text in ambient-state-baseline.php (don't allowlist it)",
            $verdict,
            'the hint for a file with both an unexpected hit and a stale baseline entry',
        );
        $this->assertStringNotContainsString('folio/src/C.php: a baselined line changed?', $verdict, 'no hint without a stale entry in the same file');
        $this->assertStringNotContainsString('auth/src/B.php: a baselined line changed?', $verdict, 'no hint without an unexpected hit in the same file');

        $this->assertSame('', self::ambientStateVerdict(
            [['core/src/Clock.php', 3, 'return time();', ['clock']], ['auth/src/B.php', 1, '$at = time();', ['clock']]],
            ['core/src/Clock.php' => ['clock' => 'the clock']],
            ['auth/src/B.php' => ['$at = time();']],
        ), 'allowed and baselined hits pass');
    }

    /**
     * Compares the hits with the allowlist and the baseline; '' when they agree, else one message
     * that lists every unexpected hit, stale baseline entry and unused allowlist entry.
     *
     * @param list<array{string, int, string, list<string>}> $hits file, line number, trimmed text, rules
     * @param array<string, array<string, string|array<string, string>>> $allow
     * @param array<string, list<string>> $baseline
     */
    private static function ambientStateVerdict(array $hits, array $allow, array $baseline): string
    {
        $allowUsed = [];
        $baselineUsed = [];
        $unexpected = [];
        $unexpectedFiles = [];
        foreach ($hits as [$file, $lineNo, $text, $rules]) {
            $left = [];
            foreach ($rules as $rule) {
                $entry = self::allowedBy($allow, $file, $rule, $text);
                if ($entry === null) {
                    $left[] = $rule;
                } else {
                    $allowUsed[$entry] = true;
                }
            }
            if ($left === []) {
                continue;
            }
            $key = $file . ': ' . $text;
            $listed = count(array_keys($baseline[$file] ?? [], $text, true));
            if (($baselineUsed[$key] ?? 0) < $listed) {
                $baselineUsed[$key] = ($baselineUsed[$key] ?? 0) + 1;
                continue;
            }
            $unexpected[] = sprintf('%s:%d [%s] %s', $file, $lineNo, implode(', ', $left), $text);
            $unexpectedFiles[$file] = true;
        }

        $stale = [];
        $staleFiles = [];
        foreach ($baseline as $file => $lines) {
            foreach (array_count_values($lines) as $text => $listed) {
                $found = $baselineUsed[$file . ': ' . $text] ?? 0;
                if ($found < $listed) {
                    $stale[] = sprintf('%s: %s (listed %d, hits %d)', $file, $text, $listed, $found);
                    $staleFiles[$file] = true;
                }
            }
        }

        $unusedAllow = [];
        foreach ($allow as $pattern => $rules) {
            foreach ($rules as $rule => $reason) {
                foreach (is_array($reason) ? array_keys($reason) : [null] as $text) {
                    $entry = self::allowEntryKey($pattern, $rule, $text);
                    if (!isset($allowUsed[$entry])) {
                        $unusedAllow[] = $entry;
                    }
                }
            }
        }

        $parts = [];
        if ($unexpected !== []) {
            $parts[] = "Ambient state: read it from Contexts::current() (see the class docblock). A permanent exception goes to\n"
                . "ambient-state-allow.php with its reason; a baselined line that only changed its text is updated in\n"
                . "ambient-state-baseline.php.\n  " . implode("\n  ", $unexpected);
        }
        if ($stale !== []) {
            $parts[] = "These baseline entries no longer hit: remove them from tools/tests/ambient-state-baseline.php.\n  "
                . implode("\n  ", $stale);
        }
        $changed = array_keys(array_intersect_key($unexpectedFiles, $staleFiles));
        if ($changed !== []) {
            $parts[] = implode("\n", array_map(
                static fn (string $file): string => $file . ": a baselined line changed? update its text in ambient-state-baseline.php (don't allowlist it)",
                $changed,
            ));
        }
        if ($unusedAllow !== []) {
            $parts[] = "These allowlist entries match nothing: remove them from tools/tests/ambient-state-allow.php.\n  "
                . implode("\n  ", $unusedAllow);
        }

        return implode("\n\n", $parts);
    }

    /**
     * One fixture file per rule, each with exactly one hit, and a clock stamp that is no hit; then
     * spellings each rule must catch and harmless lines it must leave alone.
     */
    public function test_architecture_no_ambient_state_catches_each_rule(): void
    {
        $hits = [];
        foreach ($this->files(__DIR__ . '/Fixtures/AmbientState') as $file) {
            foreach (self::ambientStateHits($file) as [, , $rules]) {
                foreach ($rules as $rule) {
                    $hits[] = basename($file) . ': ' . $rule;
                }
            }
        }
        sort($hits);
        $this->assertSame([
            'Clock.php: clock',
            'ContextDump.php: context-dump',
            'ContextProperty.php: context-property',
            'FrameContextWrite.php: frame-context-write',
            'Peek.php: peek',
            'RequestBinding.php: request-binding',
            'Setter.php: setter',
            'StaticState.php: static-state',
            'Superglobal.php: superglobal',
        ], $hits, 'each rule hits its fixture once; the stamp (ClockStamp.php) is no hit');
        $this->assertSame(array_keys(self::AMBIENT_RULES), [
            'superglobal', 'clock', 'setter', 'static-state', 'context-property', 'peek', 'context-dump', 'frame-context-write',
            'request-binding',
        ], 'every rule has a fixture');

        $caught = [
            'superglobal' => [
                '$token = $_COOKIE[\'t\'] ?? null;',
                '$request = $creator->fromGlobals();',
                'unset($_SESSION);',
                '$uri = $GLOBALS[\'_SERVER\'][\'REQUEST_URI\'];',
                '$GLOBALS["_COOKIE"] = [];',
            ],
            'clock' => [
                '$at = time() + 60;',
                '$at = \time();',
                '$start = microtime(true);',
                '$start = hrtime(true);',
                '$day = gmdate(\'Y-m-d\');',
                '$stamp = date(DATE_ATOM);',
                '$stamp = date(\DateTimeInterface::ATOM);',
                '$now = new \DateTimeImmutable();',
                '$now = new DateTime(\'now\');',
                '$old = new \DateTimeImmutable(\'-60 seconds\');',
                '$old = new \DateTimeImmutable(\'-\' . self::STALE_JOB_SECONDS . \' seconds\');',
                '$month = new \DateTimeImmutable(\'first day of this month 00:00:00\');',
                '$at = strtotime(\'+1 day\');',
                '$stamp = (new \\DateTimeImmutable)->format(\'c\');',
                '$now = new DateTime;',
                '$old = new \\DateTimeImmutable(\'1 hour ago\');',
            ],
            'setter' => [
                '$this->translator->setLocale($locale);',
                '$auth->setUser($user);',
                '$auth->addUser($guard, $user);',
                '$this->live->showWorkingCopies();',
                'ContentLocale::set($locale);',
                '$this->contentLocale->set($locale);',
                '$this->contentLocale?->set($code);',
                '$this->pageLocale?->set($locale);',
                '$translator->useOverrides(false);',
                '$previous = \\Preflow\\Folio\\FolioText::use($this->translator);',
                '$previous = $this->validators?->useTranslator($this->translator);',
                '$this->locales->setCurrent($current);',
                '$this->engine?->addGlobal(\'interface_locale\', $this->locales->current());',
                '$render = static fn () => $notifier->discardingQueued($mail);',
                'return $container->get(\\Preflow\\Core\\Http\\CookieQueue::class)->isolated($render);',
                'return $this->cookieQueue->isolated($render);',
                'return InternalRender::mark($request);',
                '$restores = [$container->get(\\Preflow\\Publishing\\LiveContent::class)->isolate()];',
                '$restore = $this->live->isolate();',
                '$pageCache->isolate($published);',
                '$response = $this->cache->isolated(fn () => $handler->handle($request));',
                '$container->get(\\Preflow\\View\\Geo\\RenderScopes::class)->add(',
                '$this->app->holdMailForFlush();',
            ],
            'static-state' => [
                'private static array $cache = [];',
                'public static ?Translator $translator = null;',
                'protected static $loaded;',
                'static $memo = null;',
            ],
            'context-property' => [
                'private RenderContext $context;',
                'public ?RenderContext $context = null;',
                '        private readonly RenderContext $ctx,',
                'public function __construct(private \Preflow\Core\Context\RenderContext $context) {}',
                'protected RenderContext|null $context;',
            ],
            'peek' => ['$ctx = Contexts::peek();'],
            'context-dump' => [
                'error_log(var_export($ctx, true));',
                '$fields = (array) $this->context;',
                '$fields = get_object_vars(Contexts::current());',
            ],
            'frame-context-write' => [
                '$frame->context = $narrowed;',
                'Contexts::frame()->context = $ctx;',
            ],
            'request-binding' => [
                '$container->instance(RequestContext::class, $context);',
                '$this->container->instance(\\Preflow\\Core\\Http\\CookieQueue::class, $cookieQueue);',
                '$container->singleton(RequestContext::class, fn () => $context);',
                '$container->bind(\\Preflow\\Core\\Http\\RequestContext::class, $factory);',
            ],
        ];
        foreach ($caught as $rule => $lines) {
            foreach ($lines as $line) {
                $this->assertContains($rule, self::ambientRulesOf($line), "caught by {$rule}: {$line}");
            }
        }

        $harmless = [
            '$at = $this->clock->time();',
            '$at = Clock::time();',
            '$changed = filemtime($file);',
            '$day = date(\'Y-m-d\', $timestamp);',
            '$at = new \DateTimeImmutable(\'@\' . $timestamp);',
            '$at = new \DateTimeImmutable($value);',
            '$at = new \DateTimeImmutable(\'2026-01-01\');',
            '$name = \'export-\' . date(\'Ymd\') . \'.zip\'; // clock: stamp',
            'public function time(): int',
            'public static function create(): static',
            'return new static($value);',
            'static::$cache[$key] = $value;',
            '$sort = static fn (array $a): int => 0;',
            'usort($list, static function ($a, $b) {',
            '     * @var static $instance',
            '        // time() is read once, at the edge',
            'public function render(RenderContext $ctx): string',
            'function f(?RenderContext $ctx = null) {}',
            '$this->context = $context;',
            '$same = $frame->context === $ctx;',
            '$code = var_export($value, true);',
            '$options = (array) $options;',
            '$path = $_ENV[\'DATA_PATH\'] ?? null;',
            '$locale = $this->translator->getLocale();',
            '$ctx = Contexts::current();',
            '$preferences->setLocaleFor($user, $locale);',
            '$this->overrides->set($locale, $key, $text, $by);',
            '$context = $container->get(RequestContext::class);',
            '$response = $this->assets->isolate(fn () => $this->scoped($sub, $handler));',
            '$restore = $this->collections->isolate();',
            '$this->overrides->setCurrentLocale($locale);',
            'self::useOverrides($translator, $c);',
            '$engine->addGlobal(\'site_name\', $name);',
            '$internal = InternalRender::is($request);',
            // trailing comments are not code
            '$x = 1; // $_SESSION is not read here',
            '$x = 1; # time() is not read here',
            '$x = 1; /* new \\DateTimeImmutable() */',
            // strings stay: a comment marker inside a string does not hide what follows
            '$url = \'https://example.com/\'; $y = 2;',
        ];
        $this->assertContains('superglobal', self::ambientRulesOf('$url = \'http://x\' . $_SERVER[\'HTTP_HOST\'];'), 'a "//" inside a string is not a comment');
        $this->assertContains('clock', self::ambientRulesOf('$s = "# not a comment" . time();'), 'a "#" inside a string is not a comment');
        $this->assertContains('setter', self::ambientRulesOf('$t->setLocale(\'de\'); // switch to German'), 'the code before a trailing comment is still checked');
        foreach ($harmless as $line) {
            $this->assertSame([], self::ambientRulesOf($line), "harmless: {$line}");
        }
    }

    /** @return list<array{int, string, list<string>}> each hit in $file: line number, trimmed text, rules */
    private static function ambientStateHits(string $file): array
    {
        $hits = [];
        foreach (file($file) ?: [] as $n => $line) {
            $rules = self::ambientRulesOf($line);
            if ($rules !== []) {
                $hits[] = [$n + 1, trim($line), $rules];
            }
        }

        return $hits;
    }

    /** @return list<string> the AMBIENT_RULES that $line hits */
    private static function ambientRulesOf(string $line): array
    {
        $code = ltrim($line);
        if ($code === '' || str_starts_with($code, '*') || str_starts_with($code, '/*') || str_starts_with($code, '//')
            || (str_starts_with($code, '#') && !str_starts_with($code, '#['))) {
            return [];
        }
        $code = self::withoutComments($line);
        $rules = [];
        foreach (self::AMBIENT_RULES as $rule => $pattern) {
            if (preg_match($pattern, $code) !== 1) {
                continue;
            }
            if ($rule === 'clock' && preg_match('~//\s*clock:\s*stamp\s*$~', $line) === 1) {
                continue;
            }
            $rules[] = $rule;
        }

        return $rules;
    }

    /**
     * $line without its comments: a trailing `//` or `#` comment (not an attribute `#[`) and inline
     * `/* … *\/` blocks are removed; quoted strings are kept as they are, so a comment marker inside
     * a string hides nothing. One line at a time: a string or comment that spans lines is not seen.
     */
    private static function withoutComments(string $line): string
    {
        $code = '';
        $quote = null;
        $length = strlen($line);
        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];
            if ($quote !== null) {
                $code .= $char;
                if ($char === '\\' && $i + 1 < $length) {
                    $code .= $line[++$i];
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '\'' || $char === '"') {
                $quote = $char;
            } elseif (($char === '/' && ($line[$i + 1] ?? '') === '/') || ($char === '#' && ($line[$i + 1] ?? '') !== '[')) {
                break;
            } elseif ($char === '/' && ($line[$i + 1] ?? '') === '*') {
                $end = strpos($line, '*/', $i + 2);
                if ($end === false) {
                    break;
                }
                $i = $end + 1;
                $code .= ' ';
                continue;
            }
            $code .= $char;
        }

        return $code;
    }

    /**
     * The allowlist entry that permits $rule on this line of $file, or null.
     *
     * @param array<string, array<string, string|array<string, string>>> $allow
     */
    private static function allowedBy(array $allow, string $file, string $rule, string $text): ?string
    {
        foreach ($allow as $pattern => $rules) {
            if (!isset($rules[$rule]) || ($pattern !== $file && !fnmatch($pattern, $file, FNM_PATHNAME))) {
                continue;
            }
            if (is_string($rules[$rule])) {
                return self::allowEntryKey($pattern, $rule, null);
            }
            if (isset($rules[$rule][$text])) {
                return self::allowEntryKey($pattern, $rule, $text);
            }
        }

        return null;
    }

    private static function allowEntryKey(string $pattern, string $rule, ?string $text): string
    {
        return $pattern . ' [' . $rule . ']' . ($text === null ? '' : ': ' . $text);
    }

    /** @return list<string> every file below $dir */
    private function files(string $dir): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    private function relative(string $file): string
    {
        return substr($file, strlen(self::packages()) + 1);
    }

    private function relativeToRepo(string $file): string
    {
        return substr($file, strlen(dirname(__DIR__, 2)) + 1);
    }
}
