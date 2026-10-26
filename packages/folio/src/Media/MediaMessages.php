<?php

declare(strict_types=1);

namespace Preflow\Folio\Media;

use Preflow\Folio\FolioText;
use Preflow\Media\MediaLibrary;

/**
 * The media core's notes and messages (AssetUploadException, \InvalidArgumentException of
 * MediaLibrary) in the admin's interface language. The core keeps its English texts for logs
 * and the CLI; Folio recognises the known ones by their pattern and shows its own key
 * (folio.assets.core.*) with the same details. A message it doesn't know is shown as it is.
 */
final class MediaMessages
{
    /** Pattern of a core message => its key; the named groups become the text's parameters. */
    private const array PATTERNS = [
        '/^(?<file>.+): the upload failed \(error (?<code>-?\d+)\)\.$/s' => 'upload_failed',
        '/^(?<file>.+): the file does not exist\.$/s' => 'file_missing',
        '/^Unknown asset (?<id>.+)\.$/s' => 'unknown_asset',
        '/^Preset "(?<preset>.+)" is not a cover box in config\/image\.php, so it takes no crop\.$/s' => 'not_cover',
        '/^Asset (?<id>.+) cannot be edited: only public JPEG, PNG, WebP and AVIF images can\.$/s' => 'cannot_edit',
        '/^Asset (?<id>.+) has no version (?<number>\d+)\.$/s' => 'no_version',
        '/^Version (?<number>\d+) of (?<label>.+): the file is missing\.$/s' => 'version_file_missing',
        '/^(?<file>.+): a (?<kind>image|video|document) can only be replaced by another (?P=kind)\.$/s' => 'same_kind',
        '/^(?<file>.+): no private directory is configured \(assets\.private_dir\)\.$/s' => 'no_private_dir',
        '/^The media directory is not writable\.$/' => 'dir_not_writable',
        '/^(?<file>.+): the file could not be written to the media directory\.$/s' => 'not_written',
        '/^(?<file>.+): the current file could not be kept as a version \(versions_dir\)\.$/s' => 'version_not_kept',
        '/^(?<file>.+): no versions directory is configured \(versions_dir\), so the current file could not be kept\.$/s' => 'no_versions_dir',
        '/^The versions directory is not writable \(versions_dir\)\.$/' => 'versions_not_writable',
        '/^The lock of asset (?<id>.+) could not be taken \(versions_dir\); nothing was changed\.$/s' => 'locked',
        '/^SVG uploads are disabled\.$/' => 'svg_disabled',
        '/^(?<file>.+): files of type (?<mime>.+) are not allowed\.$/s' => 'type_not_allowed',
        '/^(?<file>.+) is larger than the (?<limit>\d+(?:\.\d+)? (?:B|KB|MB)) limit\.$/s' => 'too_large',
    ];

    /** A core message as the admin shows it. */
    public static function text(string $message): string
    {
        foreach (self::PATTERNS as $pattern => $key) {
            if (preg_match($pattern, $message, $m) !== 1) {
                continue;
            }
            $params = array_filter($m, static fn ($k) => is_string($k), ARRAY_FILTER_USE_KEY);
            if ($key === 'same_kind') {
                $key .= '.' . $params['kind'];
                unset($params['kind']);
            }

            return FolioText::get('folio.assets.core.' . $key, $params);
        }

        return $message;
    }

    /** An asset's note (the core sets NOTE_NEEDS_IMAGICK); another note shows as it is. */
    public static function note(?string $note): ?string
    {
        return $note === MediaLibrary::NOTE_NEEDS_IMAGICK ? FolioText::get('folio.assets.core.needs_imagick') : $note;
    }
}
