<?php

declare(strict_types=1);

/**
 * Times warm renders of one page of an app against the packages of this checkout.
 *
 *     php -d opcache.enable_cli=1 tools/bench-render.php <app-base-path> <path> [options]
 *
 *     --runs=50             timed renders (the result is their median)
 *     --warmup=5            renders before timing starts (compiled templates, data caches, opcache)
 *     --in-process          all renders in this process, after one boot (see below)
 *     --header='Name: v'    a request header (repeatable)
 *     --cookie=name=value   a request cookie (repeatable)
 *     --sign-in=/path       first GET this form, then POST it back with its hidden fields, the
 *                           --field values and the cookies it set; the cookies it ends with go
 *                           with every timed request
 *     --field=name=value    a field of the sign-in form (repeatable)
 *     --json                one JSON line instead of the table
 *     --body                print the first response's body to stderr (to see why a page fails)
 *
 * By default each render is its own PHP process, as under PHP-FPM: the process boots the app
 * (Application::create() + boot()) and handles the page once through Application::handle().
 * The processes share an opcache file cache, so after the warm-up they start from compiled code
 * as a warm FPM worker does. The result gives the median of the render (handle()) and of boot plus
 * render. --in-process boots once and renders --warmup + --runs times in one process: cheaper and
 * steadier, but a release before 0.81 could carry state from one render to the next in one
 * process (the page head's assets, for example), so compare across that line per process only.
 * Every response must have the status of the first, or the run stops.
 *
 * Classes: Preflow\* and the libraries this checkout installs come from this checkout's vendor/;
 * the app's own namespaces (its composer.json autoload) and the libraries only the app installs
 * come from the app. Point the app at a copy of its data (database, storage) when the page might
 * write: the bench renders as a visitor would.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$positional = [];
$options = [
    'runs' => '50', 'warmup' => '5', 'in-process' => false, 'header' => [], 'cookie' => [], 'field' => [],
    'sign-in' => null, 'json' => false, 'body' => false, 'child' => false,
];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $arg, $m) === 1) {
        $value = $m[2] ?? true;
        if (is_array($options[$m[1]] ?? null)) {
            $options[$m[1]][] = (string) $value;
        } elseif (array_key_exists($m[1], $options)) {
            $options[$m[1]] = $value;
        } else {
            fwrite(STDERR, "Unknown option --{$m[1]}\n");
            exit(2);
        }
    } else {
        $positional[] = $arg;
    }
}
if (count($positional) !== 2) {
    fwrite(STDERR, "Usage: php -d opcache.enable_cli=1 tools/bench-render.php <app-base-path> <path> [--runs=50] [--warmup=5] [--in-process] [--header='Name: value'] [--cookie=name=value] [--sign-in=/path --field=name=value] [--json] [--body]\n");
    exit(2);
}
[$basePath, $path] = $positional;
$basePath = rtrim((string) realpath($basePath) ?: $basePath, '/');
if (!is_file($basePath . '/composer.json')) {
    fwrite(STDERR, "No composer.json in {$basePath}\n");
    exit(2);
}

require dirname(__DIR__) . '/vendor/autoload.php';
benchAutoloadApp($basePath);

$cookies = [];
foreach ($options['cookie'] as $pair) {
    [$name, $value] = explode('=', $pair, 2) + [1 => ''];
    $cookies[$name] = $value;
}
$headers = [];
foreach ($options['header'] as $line) {
    [$name, $value] = array_map('trim', explode(':', $line, 2)) + [1 => ''];
    $headers[$name] = $value;
}

$bootStart = hrtime(true);
$app = Preflow\Core\Application::create($basePath);
$app->boot();
$bootMs = (hrtime(true) - $bootStart) / 1e6;

$factory = new Nyholm\Psr7\Factory\Psr17Factory();
$origin = rtrim((string) ($app->config()->get('app.url') ?? 'http://localhost'), '/');
$request = static function (string $method, string $target, array $body = []) use ($factory, $origin, &$cookies, $headers): Psr\Http\Message\ServerRequestInterface {
    $uri = $origin . $target;
    $request = $factory->createServerRequest($method, $uri, ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $target, 'HTTP_HOST' => parse_url($uri, PHP_URL_HOST)]);
    foreach ($headers as $name => $value) {
        $request = $request->withHeader($name, $value);
    }
    // A native session (session_start()) reads its id from $_COOKIE, as under PHP-FPM.
    $_COOKIE = $cookies;
    if ($cookies !== []) {
        $request = $request->withCookieParams($cookies)
            ->withHeader('Cookie', implode('; ', array_map(static fn ($k, $v) => $k . '=' . $v, array_keys($cookies), $cookies)));
    }
    if ($method === 'POST') {
        $request = $request->withParsedBody($body)
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withHeader('Origin', $origin)
            ->withBody($factory->createStream(http_build_query($body)));
    }

    return $request;
};
$keep = static function (Psr\Http\Message\ResponseInterface $response) use (&$cookies): void {
    foreach ($response->getHeader('Set-Cookie') as $line) {
        [$pair] = explode(';', $line, 2);
        [$name, $value] = explode('=', $pair, 2) + [1 => ''];
        if ($value === '' || $value === 'deleted' || preg_match('/;\s*max-age=0\b/i', $line) === 1) {
            unset($cookies[trim($name)]);
        } else {
            $cookies[trim($name)] = urldecode($value);
        }
    }
};
$render = static function () use ($app, $request, $keep, $path): array {
    $next = $request('GET', $path);
    $start = hrtime(true);
    $response = $app->handle($next);
    $body = (string) $response->getBody();
    $elapsed = (hrtime(true) - $start) / 1e6;
    $keep($response);

    return [$response->getStatusCode(), $body, $elapsed];
};

if ($options['child'] === true) {
    [$status, $body, $ms] = $render();
    echo json_encode(['status' => $status, 'bytes' => strlen($body), 'render_ms' => $ms, 'boot_ms' => $bootMs, 'peak_mb' => memory_get_peak_usage(true) / 1048576, 'body' => $options['body'] === true ? $body : null]), "\n";
    exit(0);
}

if (is_string($options['sign-in'])) {
    $form = $app->handle($request('GET', $options['sign-in']));
    $keep($form);
    $fields = [];
    preg_match_all('/<input\b[^>]*\btype="hidden"[^>]*>/i', (string) $form->getBody(), $inputs);
    foreach ($inputs[0] as $input) {
        if (preg_match('/\bname="([^"]+)"/', $input, $n) === 1 && preg_match('/\bvalue="([^"]*)"/', $input, $v) === 1) {
            $fields[$n[1]] = html_entity_decode($v[1], ENT_QUOTES | ENT_HTML5);
        }
    }
    foreach ($options['field'] as $field) {
        [$name, $value] = explode('=', $field, 2) + [1 => ''];
        $fields[$name] = $value;
    }
    $signedIn = $app->handle($request('POST', $options['sign-in'], $fields));
    $keep($signedIn);
    // A native session's cookie is sent by PHP, not put on the response: keep its id too.
    if (session_status() === PHP_SESSION_ACTIVE) {
        $cookies[session_name()] = session_id();
        session_write_close();
    }
    if ($signedIn->getStatusCode() >= 400) {
        fwrite(STDERR, "Sign-in answered {$signedIn->getStatusCode()}\n");
        exit(1);
    }
}

$status = null;
$renders = [];
$totals = [];
$boots = [];
$bytes = 0;
$peak = 0.0;
$total = (int) $options['warmup'] + (int) $options['runs'];
$opcacheDir = sys_get_temp_dir() . '/preflow-bench-opcache';
if (!$options['in-process'] && !is_dir($opcacheDir)) {
    @mkdir($opcacheDir, 0777, true);
}
for ($i = 0; $i < $total; $i++) {
    if ($options['in-process']) {
        gc_collect_cycles();
        [$code, $body, $ms] = $render();
        $boot = null;
        $mb = memory_get_peak_usage(true) / 1048576;
    } else {
        $command = [PHP_BINARY, '-d', 'opcache.enable_cli=1', '-d', 'opcache.file_cache=' . $opcacheDir, '-d', 'session.save_path=' . ini_get('session.save_path'), __FILE__, $basePath, $path, '--child'];
        foreach ($cookies as $name => $value) {
            $command[] = '--cookie=' . $name . '=' . $value;
        }
        foreach ($options['header'] as $line) {
            $command[] = '--header=' . $line;
        }
        if ($status === null && $options['body'] === true) {
            $command[] = '--body';
        }
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        proc_close($process);
        $child = json_decode(trim((string) strrchr("\n" . trim((string) $out), "\n")), true);
        if (!is_array($child)) {
            fwrite(STDERR, "Render {$i} failed:\n{$out}{$err}\n");
            exit(1);
        }
        [$code, $ms, $boot, $mb] = [$child['status'], $child['render_ms'], $child['boot_ms'], $child['peak_mb']];
        $body = $child['body'] ?? str_repeat(' ', $child['bytes']);
    }
    if ($status === null && $options['body'] === true) {
        fwrite(STDERR, $body . "\n");
    }
    $status ??= $code;
    if ($code !== $status) {
        fwrite(STDERR, "Render {$i} answered {$code}, the first {$status}\n");
        exit(1);
    }
    if ($i >= (int) $options['warmup']) {
        $renders[] = $ms;
        if ($boot !== null) {
            $boots[] = $boot;
            $totals[] = $boot + $ms;
        }
        $bytes = strlen($body);
        $peak = max($peak, $mb);
    }
}

$median = static function (array $values): ?float {
    if ($values === []) {
        return null;
    }
    sort($values);
    $n = count($values);

    return round($n % 2 === 1 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2, 3);
};
sort($renders);
$result = [
    'path' => $path,
    'mode' => $options['in-process'] ? 'in-process' : 'per-process',
    'status' => $status,
    'bytes' => $bytes,
    'runs' => count($renders),
    'median_ms' => $median($renders),
    'min_ms' => round($renders[0], 3),
    'p90_ms' => round($renders[(int) floor(0.9 * (count($renders) - 1))], 3),
    'boot_median_ms' => $median($boots),
    'boot_render_median_ms' => $median($totals),
    'peak_mb' => round($peak, 1),
    'php' => PHP_VERSION,
];
if ($options['json'] === true) {
    echo json_encode($result), "\n";
} else {
    foreach ($result as $key => $value) {
        printf("%-22s %s\n", $key, $value === null ? '-' : (string) $value);
    }
}

/**
 * The app's own namespaces (composer.json autoload psr-4) before everything, then a fallback to what the app's
 * vendor/ installs, for the libraries this checkout does not have (never Preflow\*: those are
 * this checkout's).
 */
function benchAutoloadApp(string $basePath): void
{
    $composer = json_decode((string) file_get_contents($basePath . '/composer.json'), true) ?: [];
    $own = [];
    foreach ((array) ($composer['autoload']['psr-4'] ?? []) as $prefix => $dirs) {
        foreach ((array) $dirs as $dir) {
            $own[] = [$prefix, $basePath . '/' . rtrim($dir, '/') . '/'];
        }
    }
    $vendor = $basePath . '/vendor/composer';
    $psr4 = is_file($vendor . '/autoload_psr4.php') ? require $vendor . '/autoload_psr4.php' : [];
    $classmap = is_file($vendor . '/autoload_classmap.php') ? require $vendor . '/autoload_classmap.php' : [];
    // The app's own namespaces win, also over a namespace this checkout maps (the skeleton's App\).
    spl_autoload_register(static function (string $class) use ($own): void {
        foreach ($own as [$prefix, $dir]) {
            if (str_starts_with($class, $prefix)) {
                $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($file)) {
                    require $file;

                    return;
                }
            }
        }
    }, prepend: true);
    spl_autoload_register(static function (string $class) use ($psr4, $classmap): void {
        if (str_starts_with($class, 'Preflow\\')) {
            return;
        }
        if (isset($classmap[$class]) && is_file($classmap[$class])) {
            require $classmap[$class];

            return;
        }
        foreach ($psr4 as $prefix => $dirs) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }
            foreach ((array) $dirs as $dir) {
                $file = $dir . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($file)) {
                    require $file;

                    return;
                }
            }
        }
    });
    $files = is_file($vendor . '/autoload_files.php') ? require $vendor . '/autoload_files.php' : [];
    foreach ($files as $id => $file) {
        if (!isset($GLOBALS['__composer_autoload_files'][$id]) && !str_contains($file, '/preflow/') && is_file($file)) {
            $GLOBALS['__composer_autoload_files'][$id] = true;
            require $file;
        }
    }
}
