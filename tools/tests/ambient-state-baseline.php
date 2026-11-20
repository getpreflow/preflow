<?php

declare(strict_types=1);

/**
 * The ratchet of architecture.no-ambient-state (see ArchitectureGuardsTest): every hit that exists
 * today and that the render-context release moves onto the context. File (relative to packages/) =>
 * the trimmed text of each hitting line, once per hit (a line that occurs twice is listed twice);
 * the comment names the rules it hits. A task that fixes a line removes it here; an entry that no
 * longer hits fails the guard. Nothing is added here: a new exception goes to ambient-state-allow.php
 * with its reason. Empty when the release is done.
 *
 * The skeleton and the starters (skeleton/app, starter/app, starter-folio/app) are scanned too: new
 * sites copy them. Their clock lines are assigned to Task 16 of the render-context plan (each
 * reads the context's clock or becomes a marked stamp).
 *
 * @return array<string, list<string>>
 */
return [
    'ai/src/Console/AiUsageCommand.php' => [
        '$month = date(\'Y-m\');', // clock
    ],
    'ai/src/LoggedAiClient.php' => [
        '$this->log->record(new UsageEntry(new \\DateTimeImmutable(), $purpose, $provider, $model, $usage, $this->cost($model, $usage), $failed));', // clock
    ],
    'auth/src/Remember/RememberMe.php' => [
        '$this->clock = $clock ?? static fn (): int => time();', // clock
    ],
    'auth/src/Tokens/Tokens.php' => [
        '$this->clock = $clock ?? static fn (): int => time();', // clock
    ],
    'codes/src/Codes.php' => [
        '$this->clock = $clock ?? static fn (): \\DateTimeImmutable => new \\DateTimeImmutable();', // clock
    ],
    'components/src/ComponentRenderer.php' => [
        'private static array $freshnessOf = [];', // static-state
    ],
    'core/src/Application.php' => [
        'now: static fn (): \\DateTimeImmutable => new \\DateTimeImmutable(),', // clock
        'static fn (): \\DateTimeImmutable => new \\DateTimeImmutable(),', // clock
    ],
    'core/src/Boot/ManifestBuilder.php' => [
        '\'built\' => date(DATE_ATOM),', // clock
    ],
    'core/src/Http/Csrf/StatelessCsrfToken.php' => [
        '$this->clock = $clock ?? static fn (): int => time();', // clock
    ],
    'core/src/PageCache/ChangeLog.php' => [
        '$this->clock = $clock ?? static fn (): float => microtime(true);', // clock
    ],
    'core/src/PageCache/EnabledMarker.php' => [
        'return @file_put_contents($this->file, date(DATE_ATOM) . "\\n") !== false;', // clock
    ],
    'core/src/PageCache/PageCache.php' => [
        '$this->clock = $clock ?? static fn (): float => microtime(true);', // clock
    ],
    'core/src/PageCache/PageCacheMiddleware.php' => [
        '$this->clock = $clock ?? static fn (): float => microtime(true);', // clock
    ],
    'core/src/PageCache/PageCacheSource.php' => [
        '$index->recordInvalidation([TagIndex::EVERYTHING], ($this->clock ?? static fn (): float => microtime(true))());', // clock
    ],
    'core/src/PageCache/PageStore.php' => [
        '$this->clock = $clock ?? static fn (): float => microtime(true);', // clock
    ],
    'core/src/PageCache/PendingInvalidations.php' => [
        '$last = microtime(true);', // clock
    ],
    'core/src/PageCache/RefreshQueue.php' => [
        '$this->clock = $clock ?? static fn (): float => microtime(true);', // clock
    ],
    'core/src/PageCache/Refresher.php' => [
        '$this->clock = $clock ?? static fn (): float => microtime(true);', // clock
    ],
    'core/src/PageCache/TagIndex.php' => [
        '$this->clock = $clock ?? static fn (): float => microtime(true);', // clock
        '$aside = $this->file . \'.corrupt-\' . date(\'Ymd-His\');', // clock
    ],
    'core/src/Throttle/Throttle.php' => [
        '$this->clock = $clock ?? static fn (): int => time();', // clock
        'return hash_hmac(\'sha256\', $ip, $this->key . \'|\' . gmdate(\'Y-m-d\'));', // clock
    ],
    'data/src/Activity/StoredActivityLog.php' => [
        '$this->clock = $clock ?? static fn (): \\DateTimeImmutable => new \\DateTimeImmutable();', // clock
    ],
    'data/src/DataManager.php' => [
        '$now = new \\DateTimeImmutable();', // clock
    ],
    'data/src/Driver/JsonFileDriver.php' => [
        'if (!$settled && time() > $quick[1] && ($cached[\'quick\'] ?? null) === $quick) {', // clock
    ],
    'data/src/Migration/Migrator.php' => [
        '$stmt->execute([$name, date(\'Y-m-d H:i:s\')]);', // clock
    ],
    'devtools/src/Command/AuthTokenCommand.php' => [
        '$token->createdAt = date(\'c\');', // clock
    ],
    'devtools/src/Command/MakeMigrationCommand.php' => [
        '$timestamp = date(\'Y_m_d_His\');', // clock
    ],
    'devtools/src/Command/NotifyTestCommand.php' => [
        '\'This is a test message sent by "php preflow notify:test" at \' . date(DATE_ATOM) . \'.\',', // clock
    ],
    'devtools/src/Command/ThrottlePruneCommand.php' => [
        '$clock = $this->clock ?? static fn (): float => microtime(true);', // clock
    ],
    'folio/src/Console/ReviewIndexCommand.php' => [
        '$now = (new \\DateTimeImmutable())->format(DATE_ATOM);', // clock
    ],
    'folio/src/Extension/ExtensionState.php' => [
        '\'installed_at\' => (new \\DateTimeImmutable())->format(DATE_ATOM),', // clock
    ],
    'folio/src/Field/Types/AssetFieldType.php' => [
        '$rel = date(\'Y/m\') . \'/\' . bin2hex(random_bytes(16)) . \'.\' . $ext;', // clock
    ],
    'folio/src/Http/AdminController.php' => [
        'return new \\DateTimeImmutable($job->updatedAt) < new \\DateTimeImmutable(\'-\' . self::STALE_JOB_SECONDS . \' seconds\');', // clock
        '\'Content-Disposition\' => \'attachment; filename="\' . (preg_replace(\'/[^A-Za-z0-9_-]+/\', \'-\', $type) ?: \'export\') . \'-\' . date(\'Y-m-d\') . \'.xlsx"\',', // clock
    ],
    'folio/src/Http/AdminFormat.php' => [
        '$now = ($now ?? new \\DateTimeImmutable())->setTimezone($zone);', // clock
        'return (new \\DateTimeImmutable())->setTimestamp($value);', // clock
    ],
    'folio/src/Http/AiSettingsController.php' => [
        '$now = new \\DateTimeImmutable(\'first day of this month 00:00:00\');', // clock
    ],
    'folio/src/Http/PublishingController.php' => [
        'return $this->status(self::stateText($this->publisher->state($record), new \\DateTimeImmutable()), (int) $record->get(\'_version\'));', // clock
    ],
    'folio/src/Http/RedirectsController.php' => [
        '$this->notFound->dismiss($row[\'id\'], new \\DateTimeImmutable());', // clock
    ],
    'folio/src/Http/WorkerWatch.php' => [
        'return $last !== null && $last->getTimestamp() >= time() - self::SILENCE;', // clock
    ],
    'folio/src/Permissions/KnownPermissions.php' => [
        'return (new \\DateTimeImmutable(\'now\', new \\DateTimeZone(\'UTC\')))->format(\'Y-m-d\\TH:i:s\\Z\');', // clock
    ],
    'folio/src/Redirects/RedirectEntries.php' => [
        '\'created_at\' => self::optional($row[\'created_at\'] ?? null) ?? (new \\DateTimeImmutable())->format(DATE_ATOM),', // clock
    ],
    'folio/src/Transfer/Backups.php' => [
        '$path = $dir . \'/\' . $prefix . \'-\' . date(\'Ymd-His\') . \'.tar.gz\';', // clock
    ],
    'folio/src/Transfer/Exporter.php' => [
        '\'created_at\' => (new \\DateTimeImmutable())->format(DATE_ATOM),', // clock
    ],
    'folio/src/Transfer/TarWriter.php' => [
        '. str_pad(sprintf(\'%011o\', time()), 12, "\\0")', // clock
    ],
    'folio/src/Translations/StoredOverrides.php' => [
        '\'updated_at\' => (new \\DateTimeImmutable())->format(DATE_ATOM),', // clock
    ],
    'folio/src/Users/StaffPasswords.php' => [
        '$record->set($field, (new \\DateTimeImmutable())->format(DATE_ATOM));', // clock
    ],
    'htmx/src/ComponentToken.php' => [
        'if ($maxAge !== null && (time() - ($data[\'t\'] ?? 0)) > $maxAge) {', // clock
        'return time();', // clock
    ],
    'i18n/src/Machine/FileJobStore.php' => [
        '$job->updatedAt = (new \\DateTimeImmutable())->format(\\DATE_ATOM);', // clock
    ],
    'i18n/src/Machine/FileRecordLease.php' => [
        '$this->now = $now ?? static fn (): int => time();', // clock
    ],
    'i18n/src/Machine/FileReviewIndex.php' => [
        '$this->now = $now ?? static fn (): \\DateTimeInterface => new \\DateTimeImmutable();', // clock
    ],
    'i18n/src/Machine/JobRunner.php' => [
        '$at = ($this->now !== null ? ($this->now)() : new \\DateTimeImmutable())->format(\\DATE_ATOM);', // clock
    ],
    'i18n/src/Machine/TranslationWorker.php' => [
        'return $this->now !== null ? ($this->now)() : new \\DateTimeImmutable();', // clock
    ],
    'media/src/Cleanup/AssetCleanup.php' => [
        '$this->now = $now ?? static fn (): \\DateTimeImmutable => new \\DateTimeImmutable();', // clock
    ],
    'media/src/MediaLibrary.php' => [
        '$this->now = $now ?? static fn (): \\DateTimeImmutable => new \\DateTimeImmutable();', // clock
    ],
    'members/src/Console/PruneMembersCommand.php' => [
        'return new \\DateTimeImmutable(\'now\', new \\DateTimeZone(\'UTC\'));', // clock
    ],
    'members/src/Members.php' => [
        '$this->now = $now ?? static fn (): \\DateTimeImmutable => new \\DateTimeImmutable(\'now\', new \\DateTimeZone(\'UTC\'));', // clock
        '$server = $_SERVER;', // superglobal
    ],
    'members/src/MembersView.php' => [
        '$this->translator->setLocale($locale);', // setter
        '$this->pageLocale?->set($locale);', // setter
    ],
    'notify/src/Transport/LogTransport.php' => [
        '$summary = sprintf(\'[%s] %s\', date(DATE_ATOM), $message->channel());', // clock
    ],
    'rbac/src/Console/ImportConfigStaffCommand.php' => [
        '\'created_at\' => (new \\DateTimeImmutable())->format(DATE_ATOM),', // clock
    ],
    'rbac/src/Staff/StaffAccounts.php' => [
        '\'created_at\' => (new \\DateTimeImmutable())->format(DATE_ATOM),', // clock
        '$record->set(\'created_at\', (new \\DateTimeImmutable())->format(DATE_ATOM));', // clock
    ],
    'routing/src/RouteCompiler.php' => [
        '$content = "<?php\\n\\n// Auto-generated by Preflow RouteCompiler. Do not edit.\\n// Generated: " . date(\'Y-m-d H:i:s\') . "\\n\\nreturn {$exported};\\n";', // clock
    ],
    'skeleton/app/Controllers/AuthController.php' => [
        '$user->createdAt = (new \\DateTimeImmutable())->format(\'Y-m-d H:i:s\');', // clock
    ],
    'skeleton/app/Controllers/BlogAdminController.php' => [
        '$post->created_at = (new \\DateTimeImmutable())->format(\'Y-m-d H:i:s\');', // clock
        '$post->updated_at = (new \\DateTimeImmutable())->format(\'Y-m-d H:i:s\');', // clock
    ],
    'skeleton/app/Seeds/UserSeeder.php' => [
        '$user->createdAt = (new \\DateTimeImmutable())->format(\'Y-m-d H:i:s\');', // clock
    ],
    'starter-folio/app/Console/EmployerCreateCommand.php' => [
        '$user->createdAt = (new \\DateTimeImmutable())->format(\'Y-m-d H:i:s\');', // clock
    ],
    'starter-folio/app/Jobs/JobWriter.php' => [
        '$job->postedOn = (new \\DateTimeImmutable())->format(\'Y-m-d\');', // clock
        'return (new \\DateTimeImmutable())->format(\'Y-m-d H:i:s\');', // clock
    ],
    'starter/app/Console/EmployerCreateCommand.php' => [
        '$user->createdAt = (new \\DateTimeImmutable())->format(\'Y-m-d H:i:s\');', // clock
    ],
    'starter/app/Jobs/JobWriter.php' => [
        'return (new \\DateTimeImmutable())->format(\'Y-m-d H:i:s\');', // clock
    ],
    'view/src/Audit/Audits.php' => [
        '$this->now = $now ?? static fn (): \\DateTimeImmutable => new \\DateTimeImmutable();', // clock
    ],
];
