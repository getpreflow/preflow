<?php

declare(strict_types=1);

namespace Preflow\Folio\Field;

/**
 * Capability for field types that consume uploaded files on save. The controller
 * detects this interface and hands over the request's uploaded files for the
 * field plus the existing paths to keep, instead of the parsed-body value.
 */
interface HandlesUpload
{
    /**
     * @param \Psr\Http\Message\UploadedFileInterface[] $uploaded newly uploaded files for this field
     * @param string[] $kept existing stored paths to retain
     * @param array<string, mixed> $config field config bag
     * @return mixed domain value (the caller runs it through toStorage())
     */
    public function storeUploads(array $uploaded, array $kept, array $config): mixed;
}
