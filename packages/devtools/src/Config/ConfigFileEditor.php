<?php

declare(strict_types=1);

namespace Preflow\DevTools\Config;

/**
 * Edits an app's config files (PHP files that `return [ … ];`) without ever destroying what a
 * developer wrote.
 *
 * Every edit is one insertion at a spot the tokenizer recognises: right after the opening
 * `return [` of the file, or inside the `'key' => [` array a path names. Lines that are not
 * touched keep their bytes, comments included; inserted lines take the indentation of the lines
 * around them. After writing, the file is evaluated again in a separate PHP process (the app's
 * vendor/autoload.php loaded first, so helpers and constants resolve) and compared with what it
 * returned before: every previous value must be unchanged and exactly the intended addition new.
 *
 * Anything else is refused: a shape the editor does not recognise (`array( … )`, a computed
 * return, a `match`, a file that does not parse or evaluate), a missing path, or a failed check.
 * A refused file keeps its original bytes, and the result carries the exact snippet to add by
 * hand. A value that is already there (compared by evaluated value) is never changed or added
 * twice.
 *
 * A config file that is a symbolic link (also a dangling one) or has more than one hard link is
 * never written: replacing it would turn the link into a plain file, so such edits are refused
 * with the snippet. The check process gets a deadline; one that runs past it counts as a failed
 * check.
 *
 * @since 0.85.0
 */
final class ConfigFileEditor
{
    /**
     * Runs in the child process: `php -r CHILD -- <autoload|''> <file|expr> <path>`; in `expr`
     * mode the expression comes on stdin. Prints the serialized array, or exits non-zero with the
     * reason on stderr.
     */
    private const string CHILD = <<<'PHP'
        [, $autoload, $mode, $target] = $argv;
        ob_start();
        try {
            if ($autoload !== '') {
                require $autoload;
            }
            $value = $mode === 'file'
                ? (static function (string $file): mixed { return require $file; })($target)
                : eval('return ' . stream_get_contents(STDIN) . ';');
            if (!is_array($value)) {
                throw new \UnexpectedValueException('it does not return an array');
            }
            $out = serialize($value);
        } catch (\Throwable $e) {
            ob_end_clean();
            fwrite(STDERR, $e->getMessage());
            exit(3);
        }
        ob_end_clean();
        fwrite(STDOUT, $out);
        exit(0);
        PHP;

    private const array TRIVIA = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

    private readonly string $php;

    /** The child process's error for the last failed evaluation. */
    private string $lastError = '';

    /**
     * @param string $configDir the app's config folder; its parent's vendor/autoload.php is loaded
     *                          by the check process when present
     * @param string|null $php PHP binary for the check process (default: the running one)
     * @param float $timeout seconds one check process may run before it is stopped and the check fails
     */
    public function __construct(
        private readonly string $configDir,
        ?string $php = null,
        private readonly float $timeout = 10.0,
    ) {
        $this->php = $php ?? PHP_BINARY;
    }

    /** Writes $file when missing. Existing file → 'already' (never overwritten). */
    public function create(string $file, string $contents): ConfigEdit
    {
        $path = $this->path($file);
        // An existing file is never written, also when it is a link (followed) or has more hard
        // links: 'already'. Only a dangling symbolic link is refused: creating would write its target.
        if (file_exists($path)) {
            return new ConfigEdit(ConfigEdit::ALREADY, $path, "{$file} exists already; left as it is.");
        }
        if (is_link($path)) {
            return new ConfigEdit(ConfigEdit::REFUSED, $path, "{$file} is a link to a missing file; this tool does not write through links. Create its target by hand with this content.", $contents);
        }
        $created = $this->createExclusively($path, $contents);
        if ($created === null) {
            return new ConfigEdit(ConfigEdit::ALREADY, $path, "{$file} exists already; left as it is.");
        }
        if ($created === false) {
            return new ConfigEdit(ConfigEdit::REFUSED, $path, "{$file} could not be written. Create it by hand with this content.", $contents);
        }
        if ($this->read($file) === null) {
            @unlink($path);

            return new ConfigEdit(ConfigEdit::REFUSED, $path, "{$file} was not created: its content does not evaluate to an array ({$this->lastError}).", $contents);
        }

        return new ConfigEdit(ConfigEdit::WRITTEN, $path, "{$file} created.");
    }

    /**
     * Adds list items (PHP expressions, e.g. '\Foo\Bar::class') to the top-level list a file
     * returns, at the start or end; items already present (by evaluated value) are skipped.
     *
     * @param array<string> $items PHP expressions, in the order they are to appear
     */
    public function addToList(string $file, array $items, string $where = 'start'): ConfigEdit
    {
        if ($where !== 'start' && $where !== 'end') {
            throw new \InvalidArgumentException("addToList(): \$where is 'start' or 'end', not '{$where}'.");
        }

        return $this->addEntries($file, [], array_values($items), $where, "at the {$where} of the list {$file} returns");
    }

    /**
     * Adds $key => $expression inside the top-level array (or the nested array at $path, e.g.
     * ['guards']) when the key is missing; an existing key is never changed.
     *
     * A multi-line $expression is written as if at column 0 (nested lines indented by four
     * spaces); the editor indents it to fit.
     *
     * @param list<string> $path
     */
    public function addKey(string $file, array $path, string $key, string $expression): ConfigEdit
    {
        $place = $path === [] ? "in the array {$file} returns" : "inside '" . implode("' > '", $path) . "' in {$file}";
        $text = var_export($key, true) . ' => ' . $expression;
        $snippet = $text . ',';
        $fullPath = $this->path($file);
        if (self::isLinked($fullPath)) {
            return $this->refusedLink($fullPath, $file, $place, $snippet);
        }

        $before = $this->read($file);
        if ($before === null) {
            return $this->refused($fullPath, "{$file} cannot be evaluated ({$this->lastError})", $place, $snippet);
        }
        $target = self::dig($before, $path);
        if ($target === null) {
            return $this->refused($fullPath, "{$file} has no array at '" . implode("' > '", $path) . "'", $place, $snippet);
        }
        if (array_key_exists($key, $target)) {
            return new ConfigEdit(ConfigEdit::ALREADY, $fullPath, "'{$key}' is set already {$place}; left as it is.");
        }
        $values = $this->evaluateExpressions([$expression]);
        if ($values === null) {
            return $this->refused($fullPath, "the value to add cannot be evaluated ({$this->lastError})", $place, $snippet);
        }
        $target[$key] = $values[0];

        return $this->write($file, $path, 'end', [$text], self::setIn($before, $path, $target), $place, $snippet);
    }

    /**
     * Adds a value to the list at $path (e.g. ['session','paths']) when missing.
     *
     * @param list<string> $path
     */
    public function addToNestedList(string $file, array $path, string $expression): ConfigEdit
    {
        return $this->addEntries($file, $path, [$expression], 'end', "to '" . implode("' > '", $path) . "' in {$file}");
    }

    /**
     * Evaluates the file in a separate PHP process and returns its array (for checks like the
     * staff guard shape). Null when the file is missing, does not evaluate, returns no array, or
     * runs past the deadline.
     *
     * The check process loads the app's vendor/autoload.php only: the app's .env is not read, so a
     * value taken from the environment shows what the environment of this process holds (usually
     * the default the config file gives).
     *
     * @return array<mixed>|null
     */
    public function read(string $file): ?array
    {
        $path = $this->path($file);
        if (!is_file($path)) {
            $this->lastError = 'the file does not exist';

            return null;
        }

        return $this->evaluate('file', $path, '');
    }

    /**
     * @param list<string> $path
     * @param list<string> $items
     */
    private function addEntries(string $file, array $path, array $items, string $where, string $place): ConfigEdit
    {
        $fullPath = $this->path($file);
        $snippet = self::listSnippet($items);
        if (self::isLinked($fullPath)) {
            return $this->refusedLink($fullPath, $file, $place, $snippet);
        }

        $before = $this->read($file);
        if ($before === null) {
            return $this->refused($fullPath, "{$file} cannot be evaluated ({$this->lastError})", $place, $snippet);
        }
        $target = self::dig($before, $path);
        if ($target === null || !array_is_list($target)) {
            $what = $path === [] ? "{$file} does not return a list" : "'" . implode("' > '", $path) . "' in {$file} is not a list";

            return $this->refused($fullPath, $what, $place, $snippet);
        }
        $values = $this->evaluateExpressions($items);
        if ($values === null) {
            return $this->refused($fullPath, "the values to add cannot be evaluated ({$this->lastError})", $place, $snippet);
        }

        $texts = [];
        $added = [];
        foreach ($items as $i => $item) {
            if (in_array($values[$i], $target, true) || in_array($values[$i], $added, true)) {
                continue;
            }
            $texts[] = $item;
            $added[] = $values[$i];
        }
        if ($texts === []) {
            return new ConfigEdit(ConfigEdit::ALREADY, $fullPath, "{$file}: every value is there already; left as it is.");
        }
        $expected = $where === 'start' ? [...$added, ...$target] : [...$target, ...$added];

        return $this->write($file, $path, $where, $texts, self::setIn($before, $path, $expected), $place, self::listSnippet($texts));
    }

    /**
     * Inserts $texts into the array at $path, writes the file and checks it evaluates to
     * $expected; anything else restores the original bytes and refuses.
     *
     * @param list<string> $path
     * @param list<string> $texts
     * @param array<mixed> $expected
     */
    private function write(string $file, array $path, string $where, array $texts, array $expected, string $place, string $snippet): ConfigEdit
    {
        $fullPath = $this->path($file);
        if (self::isLinked($fullPath)) {
            return $this->refusedLink($fullPath, $file, $place, $snippet);
        }
        $original = @file_get_contents($fullPath);
        if ($original === false) {
            return $this->refused($fullPath, "{$file} cannot be read", $place, $snippet);
        }

        try {
            $edited = $this->insert($original, $path, $where, $texts);
        } catch (\UnexpectedValueException $e) {
            return $this->refused($fullPath, "{$file} has a shape this tool does not edit: " . $e->getMessage(), $place, $snippet);
        }

        if (!$this->writeAtomically($fullPath, $edited)) {
            return $this->refused($fullPath, "{$file} cannot be written", $place, $snippet);
        }

        $after = $this->read($file);
        if ($after !== null && serialize($after) === serialize($expected)) {
            return new ConfigEdit(ConfigEdit::CHANGED, $fullPath, "Added {$place}.");
        }

        $reason = $after === null
            ? "{$file} did not evaluate after the edit ({$this->lastError})"
            : "{$file} did not return what was expected after the edit";
        if (!$this->writeAtomically($fullPath, $original) && @file_put_contents($fullPath, $original) !== strlen($original)) {
            return $this->refused($fullPath, $reason . ', and restoring its original content FAILED: check the file', $place, $snippet);
        }

        return $this->refused($fullPath, $reason . '; the original was restored', $place, $snippet);
    }

    /**
     * @param list<string> $path
     * @param list<string> $texts
     * @throws \UnexpectedValueException naming what is not recognised
     */
    private function insert(string $source, array $path, string $where, array $texts): string
    {
        try {
            $tokens = array_values(\PhpToken::tokenize($source, TOKEN_PARSE));
        } catch (\ParseError $e) {
            throw new \UnexpectedValueException('it does not parse (' . $e->getMessage() . ')', 0, $e);
        }
        $match = self::matchBrackets($tokens);
        [$open, $close] = self::returnedArray($tokens, $match);
        foreach ($path as $segment) {
            [$open, $close] = self::nestedArray($tokens, $match, $open, $close, $segment);
        }

        return $where === 'start'
            ? self::insertAtStart($source, $tokens, $match, $open, $close, $texts)
            : self::insertAtEnd($source, $tokens, $open, $close, $texts);
    }

    /**
     * Pairs every opening bracket with its closing one.
     *
     * @param list<\PhpToken> $tokens
     * @return array<int, int> opening index => closing index
     */
    private static function matchBrackets(array $tokens): array
    {
        $openers = [ord('('), ord('['), ord('{'), T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE];
        $closers = [ord(')'), ord(']'), ord('}')];
        $stack = [];
        $match = [];
        foreach ($tokens as $i => $token) {
            if (in_array($token->id, $openers, true)) {
                $stack[] = $i;
            } elseif (in_array($token->id, $closers, true)) {
                $open = array_pop($stack);
                if ($open === null) {
                    throw new \UnexpectedValueException('its brackets do not balance');
                }
                $match[$open] = $i;
            }
        }
        if ($stack !== []) {
            throw new \UnexpectedValueException('its brackets do not balance');
        }

        return $match;
    }

    /**
     * The `[ … ]` of the file's one top-level `return [ … ];`, which must end the file.
     *
     * @param list<\PhpToken> $tokens
     * @param array<int, int> $match
     * @return array{int, int}
     */
    private static function returnedArray(array $tokens, array $match): array
    {
        $returns = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]->id === T_RETURN) {
                $returns[] = $i;
            }
            if (isset($match[$i])) {
                $i = $match[$i];
            }
        }
        if (count($returns) !== 1) {
            throw new \UnexpectedValueException('it needs exactly one top-level `return [`, found ' . count($returns));
        }

        $open = self::nextSignificant($tokens, $returns[0]);
        if ($open === null || $tokens[$open]->id !== ord('[')) {
            throw new \UnexpectedValueException('it does not `return [ … ];` (found `return ' . ($open === null ? '' : $tokens[$open]->text) . '`)');
        }
        $close = $match[$open];
        $semicolon = self::nextSignificant($tokens, $close);
        if ($semicolon === null || $tokens[$semicolon]->id !== ord(';')) {
            throw new \UnexpectedValueException('its returned array is not followed by `;`');
        }
        for ($i = $semicolon + 1; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!self::isTrivia($token) && $token->id !== T_CLOSE_TAG && !($token->id === T_INLINE_HTML && trim($token->text) === '')) {
                throw new \UnexpectedValueException('code follows its `return [ … ];`');
            }
        }

        return [$open, $close];
    }

    /**
     * The `[ … ]` written as the value of '$key' inside the array between $open and $close.
     *
     * @param list<\PhpToken> $tokens
     * @param array<int, int> $match
     * @return array{int, int}
     */
    private static function nestedArray(array $tokens, array $match, int $open, int $close, string $key): array
    {
        $found = [];
        foreach (self::elements($tokens, $match, $open, $close) as $element) {
            if ($element['arrow'] === null) {
                continue;
            }
            $keyToken = $element['first'];
            if (self::nextSignificant($tokens, $keyToken) !== $element['arrow']
                || $tokens[$keyToken]->id !== T_CONSTANT_ENCAPSED_STRING
                || self::stringValue($tokens[$keyToken]->text) !== $key) {
                continue;
            }
            $found[] = $element;
        }
        if (count($found) !== 1) {
            throw new \UnexpectedValueException(count($found) === 0
                ? "'{$key}' is not written as a plain 'key' => [ … ] entry"
                : "'{$key}' is written more than once");
        }

        $valueOpen = self::nextSignificant($tokens, $found[0]['arrow']);
        if ($valueOpen === null || $tokens[$valueOpen]->id !== ord('[') || $match[$valueOpen] !== $found[0]['last']) {
            throw new \UnexpectedValueException("the value of '{$key}' is not a [ … ] array");
        }

        return [$valueOpen, $match[$valueOpen]];
    }

    /**
     * The comma-separated entries of the array between $open and $close.
     *
     * @param list<\PhpToken> $tokens
     * @param array<int, int> $match
     * @return list<array{first: int, last: int, arrow: int|null}>
     */
    private static function elements(array $tokens, array $match, int $open, int $close): array
    {
        $elements = [];
        $first = $last = $arrow = null;
        for ($i = $open + 1; $i < $close; $i++) {
            $token = $tokens[$i];
            if (self::isTrivia($token)) {
                continue;
            }
            if ($token->id === ord(',')) {
                if ($first === null || $last === null) {
                    throw new \UnexpectedValueException('an array has an empty entry');
                }
                $elements[] = ['first' => $first, 'last' => $last, 'arrow' => $arrow];
                $first = $last = $arrow = null;
                continue;
            }
            $first ??= $i;
            if ($token->id === T_DOUBLE_ARROW && $arrow === null) {
                $arrow = $i;
            }
            if (isset($match[$i])) {
                $i = $match[$i];
            }
            $last = $i;
        }
        if ($first !== null && $last !== null) {
            $elements[] = ['first' => $first, 'last' => $last, 'arrow' => $arrow];
        }

        return $elements;
    }

    /**
     * Inserts each text as an entry of its own before the first entry: on the line after the
     * opening bracket (a comment on the bracket's line stays there), or inline in a one-line array.
     *
     * @param list<\PhpToken> $tokens
     * @param array<int, int> $match
     * @param list<string> $texts
     */
    private static function insertAtStart(string $source, array $tokens, array $match, int $open, int $close, array $texts): string
    {
        if (self::elements($tokens, $match, $open, $close) === []) {
            return self::insertAtEnd($source, $tokens, $open, $close, $texts);
        }
        $afterOpen = $tokens[$open]->pos + 1;
        if (!self::isMultiLine($source, $tokens, $open, $close)) {
            return substr_replace($source, implode(', ', $texts) . ', ', $afterOpen, 0);
        }

        $offset = null;
        for ($i = $open + 1; $i < $close; $i++) {
            $token = $tokens[$i];
            $newline = strpos($token->text, "\n");
            if ($token->id === T_WHITESPACE && $newline !== false) {
                $offset = $token->pos + $newline + 1;
                break;
            }
            if ($token->id === T_WHITESPACE || ($token->id === T_COMMENT && $newline === false)) {
                continue;
            }
            throw new \UnexpectedValueException('its first entry starts on the line of the opening bracket');
        }
        if ($offset === null) {
            throw new \UnexpectedValueException('its first entry starts on the line of the opening bracket');
        }

        $indent = self::innerIndent($source, $tokens, $open, $close);

        return substr_replace($source, self::lines($texts, $indent), $offset, 0);
    }

    /**
     * Inserts each text as an entry of its own after the last entry: on lines of their own before
     * the closing bracket (adding the comma the last entry may lack), or inline in a one-line array.
     *
     * @param list<\PhpToken> $tokens
     * @param list<string> $texts
     */
    private static function insertAtEnd(string $source, array $tokens, int $open, int $close, array $texts): string
    {
        $last = self::previousSignificant($tokens, $close);
        if ($last === null || $last < $open) {
            throw new \UnexpectedValueException('its array cannot be located');
        }
        $empty = $last === $open;
        $afterLast = $tokens[$last]->pos + strlen($tokens[$last]->text);

        if (!self::isMultiLine($source, $tokens, $open, $close)) {
            $text = match (true) {
                $empty => implode(', ', $texts),
                $tokens[$last]->id === ord(',') => ' ' . implode(', ', $texts),
                default => ', ' . implode(', ', $texts),
            };

            return substr_replace($source, $text, $afterLast, 0);
        }

        $closePos = $tokens[$close]->pos;
        $lineStart = (int) strrpos(substr($source, 0, $closePos), "\n") + 1;
        if (trim(substr($source, $lineStart, $closePos - $lineStart), " \t") !== '') {
            throw new \UnexpectedValueException('its closing bracket is not on a line of its own');
        }

        $indent = self::innerIndent($source, $tokens, $open, $close);
        $edited = substr_replace($source, self::lines($texts, $indent), $lineStart, 0);
        if (!$empty && $tokens[$last]->id !== ord(',')) {
            $edited = substr_replace($edited, ',', $afterLast, 0);
        }

        return $edited;
    }

    /**
     * The indentation of the array's entries: that of its first non-blank line after the opening
     * bracket's line, or (empty array) the closing bracket's plus one level.
     *
     * @param list<\PhpToken> $tokens
     */
    private static function innerIndent(string $source, array $tokens, int $open, int $close): string
    {
        $openPos = $tokens[$open]->pos;
        $closePos = $tokens[$close]->pos;
        $inner = substr($source, $openPos + 1, $closePos - $openPos - 1);
        $lines = explode("\n", $inner);
        array_shift($lines);
        array_pop($lines);
        foreach ($lines as $line) {
            if (trim($line) !== '') {
                return substr($line, 0, strlen($line) - strlen(ltrim($line, " \t")));
            }
        }

        $lineStart = (int) strrpos(substr($source, 0, $closePos), "\n") + 1;
        $closingIndent = substr($source, $lineStart, $closePos - $lineStart);

        return $closingIndent . (str_contains($closingIndent, "\t") ? "\t" : '    ');
    }

    /** @param list<string> $texts */
    private static function lines(array $texts, string $indent): string
    {
        $out = '';
        foreach ($texts as $text) {
            $out .= $indent . str_replace("\n", "\n" . $indent, $text) . ",\n";
        }

        return $out;
    }

    /** @param list<\PhpToken> $tokens */
    private static function isMultiLine(string $source, array $tokens, int $open, int $close): bool
    {
        $openPos = $tokens[$open]->pos;

        return str_contains(substr($source, $openPos, $tokens[$close]->pos - $openPos), "\n");
    }

    /** @param list<\PhpToken> $tokens */
    private static function nextSignificant(array $tokens, int $from): ?int
    {
        $count = count($tokens);
        for ($i = $from + 1; $i < $count; $i++) {
            if (!self::isTrivia($tokens[$i])) {
                return $i;
            }
        }

        return null;
    }

    /** @param list<\PhpToken> $tokens */
    private static function previousSignificant(array $tokens, int $from): ?int
    {
        for ($i = $from - 1; $i >= 0; $i--) {
            if (!self::isTrivia($tokens[$i])) {
                return $i;
            }
        }

        return null;
    }

    private static function isTrivia(\PhpToken $token): bool
    {
        return in_array($token->id, self::TRIVIA, true);
    }

    /** The value of a plain string literal key; null for one with escapes this tool does not read. */
    private static function stringValue(string $literal): ?string
    {
        $inner = substr($literal, 1, -1);

        return match ($literal[0]) {
            "'" => strtr($inner, ['\\\\' => '\\', "\\'" => "'"]),
            '"' => str_contains($inner, '\\') || str_contains($inner, '$') ? null : $inner,
            default => null,
        };
    }

    /**
     * @param array<mixed> $array
     * @param list<string> $path
     * @return array<mixed>|null
     */
    private static function dig(array $array, array $path): ?array
    {
        $current = $array;
        foreach ($path as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return is_array($current) ? $current : null;
    }

    /**
     * @param array<mixed> $array
     * @param list<string> $path
     * @return array<mixed>
     */
    private static function setIn(array $array, array $path, mixed $value): array
    {
        if ($path === []) {
            return is_array($value) ? $value : [$value];
        }
        $segment = array_shift($path);
        $child = $array[$segment] ?? [];
        $array[$segment] = $path === [] ? $value : self::setIn(is_array($child) ? $child : [], $path, $value);

        return $array;
    }

    /** @param list<string> $items */
    private static function listSnippet(array $items): string
    {
        return implode("\n", array_map(static fn (string $item): string => $item . ',', $items));
    }

    private function refused(string $fullPath, string $reason, string $place, string $snippet): ConfigEdit
    {
        return new ConfigEdit(ConfigEdit::REFUSED, $fullPath, "{$reason}. Add this {$place} by hand.", $snippet);
    }

    /**
     * Evaluates PHP expressions in the check process.
     *
     * @param list<string> $expressions
     * @return list<mixed>|null
     */
    private function evaluateExpressions(array $expressions): ?array
    {
        $values = $this->evaluate('expr', '', '[' . implode(",\n", $expressions) . "\n]");

        return $values === null || count($values) !== count($expressions) ? null : array_values($values);
    }

    /** @return array<mixed>|null */
    private function evaluate(string $mode, string $target, string $code): ?array
    {
        $autoload = dirname($this->configDir) . '/vendor/autoload.php';
        $command = [
            $this->php, '-d', 'display_errors=0', '-d', 'log_errors=0',
            '-r', self::CHILD, '--', is_file($autoload) ? $autoload : '', $mode, $target,
        ];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname($this->configDir));
        if (!is_resource($process)) {
            $this->lastError = 'the PHP check process did not start';

            return null;
        }
        fwrite($pipes[0], $code);
        fclose($pipes[0]);

        // Both pipes are drained together, so a child that writes much to stderr cannot block on a
        // full pipe while stdout is read; the deadline stops a child that never ends.
        $output = [1 => '', 2 => ''];
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        foreach ($open as $pipe) {
            stream_set_blocking($pipe, false);
        }
        $deadline = hrtime(true) + (int) ($this->timeout * 1e9);
        while ($open !== []) {
            $left = ($deadline - hrtime(true)) / 1e9;
            $read = array_values($open);
            $write = $except = null;
            $failure = match (true) {
                $left <= 0 => 'the PHP check process did not finish within ' . $this->timeout . ' s',
                @stream_select($read, $write, $except, (int) $left, (int) (fmod($left, 1.0) * 1_000_000)) === false => 'the PHP check process\'s output could not be read',
                default => null,
            };
            if ($failure !== null) {
                foreach ($open as $pipe) {
                    fclose($pipe);
                }
                proc_terminate($process, 9);
                proc_close($process);
                $this->lastError = $failure;

                return null;
            }
            foreach ($open as $n => $pipe) {
                $chunk = fread($pipe, 65536);
                if ($chunk !== false) {
                    $output[$n] .= $chunk;
                }
                if (feof($pipe)) {
                    fclose($pipe);
                    unset($open[$n]);
                }
            }
        }
        $out = $output[1];
        $error = trim(substr($output[2], 0, 500));
        $exit = proc_close($process);

        if ($exit !== 0) {
            $this->lastError = $error !== '' ? $error : "the PHP check process exited with {$exit}";

            return null;
        }
        $value = @unserialize($out, ['allowed_classes' => false]);
        if (!is_array($value)) {
            $this->lastError = 'the PHP check process returned no array';

            return null;
        }
        $this->lastError = '';

        return $value;
    }

    /**
     * Something (a file, a folder, a link, dangling too) is at $path; the stat cache is cleared
     * first. Asked twice in create(): another process may create the file in between.
     *
     * @phpstan-impure
     */
    private static function taken(string $path): bool
    {
        clearstatcache(true, $path);

        return file_exists($path) || is_link($path);
    }

    /** A symbolic link (dangling ones too) or a file with more than one hard link. */
    private static function isLinked(string $path): bool
    {
        if (is_link($path)) {
            return true;
        }
        $stat = @stat($path);

        return $stat !== false && $stat['nlink'] > 1;
    }

    private function refusedLink(string $fullPath, string $file, string $place, string $snippet): ConfigEdit
    {
        return $this->refused($fullPath, "{$file} is a link (symbolic or hard); this tool does not write through links, the file was left as it is", $place, $snippet);
    }

    /**
     * Creates $path with $contents, never replacing a file: the content goes to a temporary file
     * that is then hard-linked to $path (which fails when $path exists); where hard links are not
     * available, the file is opened with exclusive creation instead.
     *
     * @return bool|null true created, false failed, null $path exists already
     */
    private function createExclusively(string $path, string $contents): ?bool
    {
        $dir = dirname($path);
        $temp = @tempnam($dir, '.' . basename($path) . '.');
        if ($temp !== false && realpath(dirname($temp)) === realpath($dir)
            && @file_put_contents($temp, $contents) === strlen($contents)
            && @chmod($temp, 0666 & ~umask())) {
            $linked = @link($temp, $path);
            @unlink($temp);
            if ($linked) {
                return true;
            }
        } elseif ($temp !== false) {
            @unlink($temp);
        }
        if (self::taken($path)) {
            return null;
        }

        $handle = @fopen($path, 'x');
        if ($handle === false) {
            return self::taken($path) ? null : false;
        }
        $written = fwrite($handle, $contents) === strlen($contents);
        fclose($handle);
        if (!$written) {
            @unlink($path);
        }

        return $written;
    }

    /** Writes through a temporary file in the same folder and a rename, keeping the file's mode. */
    private function writeAtomically(string $path, string $contents): bool
    {
        $dir = dirname($path);
        $temp = @tempnam($dir, '.' . basename($path) . '.');
        if ($temp === false) {
            return false;
        }
        if (realpath(dirname($temp)) !== realpath($dir)
            || @file_put_contents($temp, $contents) !== strlen($contents)
            || !@chmod($temp, is_file($path) ? fileperms($path) & 0777 : 0666 & ~umask())
            || !@rename($temp, $path)) {
            @unlink($temp);

            return false;
        }

        return true;
    }

    private function path(string $file): string
    {
        if ($file === '' || str_starts_with($file, '/') || preg_match('#(^|[/\\\\])\.\.([/\\\\]|$)#', $file) === 1) {
            throw new \InvalidArgumentException("Config file '{$file}' must be a path inside the config folder.");
        }

        return rtrim($this->configDir, '/') . '/' . $file;
    }
}
