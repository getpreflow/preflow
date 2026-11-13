<?php

declare(strict_types=1);

namespace Preflow\Publishing;

/**
 * What kind of write runs the save hooks (SaveHooks::before(), after()), so a hook can leave
 * some alone: one that reworks images on each save has no business with an autosave.
 *
 * - Save: an editor's or an app's own save (a form, a confirmed translation, a plain save()).
 * - Autosave: the admin's background save of the open form.
 * - Bulk: one field set on many records at once.
 * - Reorder: a record moved in its list (RecordWriter::reorder()); only its position changes.
 * - Translate: a translation run writes a record's texts.
 * - Detach: a delete takes the deleted ids out of a record that points at them.
 */
enum WriteKind: string
{
    case Save = 'save';
    case Autosave = 'autosave';
    case Bulk = 'bulk';
    case Reorder = 'reorder';
    case Translate = 'translate';
    case Detach = 'detach';
}
