<?php

declare(strict_types=1);

namespace Preflow\DevTools\Command;

/**
 * Set a config login in .env: asks for the email and the password (hidden,
 * twice) and writes <PREFIX>_EMAIL and <PREFIX>_PASSWORD_HASH. The password
 * and its hash are never printed. config/auth.php reads the two values, e.g.
 * for a ConfigUserProvider user.
 */
final class AuthUserCommand implements CommandInterface
{
    private const string DEFAULT_PREFIX = 'FOLIO_ADMIN';
    private const int MIN_LENGTH = 8;

    public function getName(): string { return 'auth:user'; }
    public function getDescription(): string { return 'Set a login (email + password hash) in .env [<PREFIX>=FOLIO_ADMIN] [--email=<email>]'; }

    public function execute(array $args): int
    {
        return $this->executeInDir((string) getcwd(), $args, STDIN, STDOUT);
    }

    /**
     * @param list<string> $args
     * @param resource     $in
     * @param resource     $out
     */
    public function executeInDir(string $dir, array $args, mixed $in, mixed $out): int
    {
        $prefix = self::DEFAULT_PREFIX;
        $email = null;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--email=')) {
                $email = substr($arg, 8);
            } elseif (!str_starts_with($arg, '--')) {
                $prefix = $arg;
            }
        }

        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $prefix) !== 1) {
            fwrite($out, "Error: \"{$prefix}\" is not an env name prefix (e.g. FOLIO_ADMIN).\n");
            return 1;
        }

        $envPath = $dir . '/.env';
        if (!is_file($envPath)) {
            fwrite($out, "Error: .env file not found. Copy .env.example first.\n");
            return 1;
        }

        $email ??= $this->ask($in, $out, 'Email: ', false);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            fwrite($out, "Error: \"{$email}\" is not an email address.\n");
            return 1;
        }

        $password = $this->ask($in, $out, 'Password: ', true);
        if (strlen($password) < self::MIN_LENGTH) {
            fwrite($out, 'Error: the password needs at least ' . self::MIN_LENGTH . " characters.\n");
            return 1;
        }
        if ($this->ask($in, $out, 'Repeat password: ', true) !== $password) {
            fwrite($out, "Error: the passwords don't match. Nothing was changed.\n");
            return 1;
        }

        $contents = (string) file_get_contents($envPath);
        $contents = $this->set($contents, "{$prefix}_EMAIL", $email);
        $contents = $this->set($contents, "{$prefix}_PASSWORD_HASH", "'" . password_hash($password, PASSWORD_DEFAULT) . "'");
        file_put_contents($envPath, $contents);

        fwrite($out, "Set {$prefix}_EMAIL={$email} and {$prefix}_PASSWORD_HASH in .env.\n");
        return 0;
    }

    /** Replace the KEY= line, or append one. */
    private function set(string $contents, string $key, string $value): string
    {
        $count = 0;
        $contents = (string) preg_replace_callback(
            '/^' . preg_quote($key, '/') . '=.*$/m',
            static fn (): string => "{$key}={$value}",
            $contents,
            1,
            $count,
        );
        if ($count === 0) {
            if ($contents !== '' && !str_ends_with($contents, "\n")) {
                $contents .= "\n";
            }
            $contents .= "{$key}={$value}\n";
        }

        return $contents;
    }

    /** @param resource $in @param resource $out */
    private function ask(mixed $in, mixed $out, string $question, bool $hidden): string
    {
        fwrite($out, $question);
        $hide = $hidden && stream_isatty($in);
        if ($hide) {
            shell_exec('stty -echo');
        }
        $answer = fgets($in);
        if ($hide) {
            shell_exec('stty echo');
            fwrite($out, "\n");
        }

        return rtrim((string) $answer, "\r\n");
    }
}
