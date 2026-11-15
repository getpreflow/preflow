<?php

declare(strict_types=1);

namespace App\Seeds;

/**
 * The marker the seeders write where a person would be named (an activity log row's "by", a
 * revision's author, an asset's uploader): the demo content has no author. AppServiceProvider
 * registers it with core's ActivityActors, so Folio names it "Demo content", never a person
 * (resources/lang/{locale}/demo.php; a site's lang/{locale}/demo.php may change the text).
 */
final class SeedActor
{
    public const string MARKER = 'seed';

    public const string LABEL = 'demo.activity.seed';
}
