<?php

declare(strict_types=1);

namespace Preflow\Media\Access;

use Preflow\Media\Asset;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Who may download a private file at {private_url}/{id}. Asked before anything is
 * served: null allows; a response (a 403, a redirect to a login page) refuses and is
 * sent as it is. Asked for every request on the route, also for an id with no private
 * file, so a refused visitor learns nothing about which ids exist: then $asset is the
 * stored asset if there is one, else a placeholder carrying only the requested id.
 *
 * Also asked for {private_url}/{id}/v/{n}, an earlier file of an asset. Those are never
 * public, even when the asset is: $asset is then the asset (private or not), so a rule
 * must not allow a request just because $asset->private is false.
 *
 * The core binds DenyAllPrivateFileAccess; Folio, or a site's own provider, binds its rules.
 */
interface PrivateFileAccess
{
    public function decide(ServerRequestInterface $request, Asset $asset): ?ResponseInterface;
}
