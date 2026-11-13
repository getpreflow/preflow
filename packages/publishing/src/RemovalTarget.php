<?php

declare(strict_types=1);

namespace Preflow\Publishing;

/**
 * Where the addresses of an unpublished record lead (Publisher::unpublish()). A marker: the
 * RecordAddresses an app binds knows its own targets (Folio's redirect targets implement it).
 */
interface RemovalTarget {}
