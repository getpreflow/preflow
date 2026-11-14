# preflow/publishing

Drafts and published copies of records. A type that publishes has a **working copy** (what editors
change, the type's own table) and a **published snapshot** (what the public site reads,
`{table}__published`, same ids). Every publish keeps a revision (`{table}__revisions`), a record can
be scheduled to go live at a time, and a signed link shows a draft to someone without an account.
Folio adds the screens (Publish, Discard, Revisions, Preview links) and its own rules; publishing
itself needs no Folio.

## Install

```
composer require preflow/publishing
```

Add `Preflow\Publishing\PublishingServiceProvider` to `config/providers.php`. With Folio, skip
this: Folio registers it itself.

## Publish a type without Folio

Set `"publishing": true` in the type's model file. For a model class (`#[Entity(type: 'job')]`),
the flag goes into its model file `config/models/job.json`, next to nothing else it needs:

```json
{ "key": "post", "table": "post", "storage": "json", "publishing": true, "fields": { "title": { "type": "string" } } }
```

```json
{ "key": "job", "publishing": true }
```

Then save drafts and publish through the `Publisher` (from the container):

```php
use Preflow\Publishing\Publisher;
use Preflow\Publishing\PublishState;
use Preflow\Publishing\VersionConflict;

$publisher = $container->get(Publisher::class);

$publisher->saveDraft($record, $loadedVersion);  // no rules; VersionConflict when someone saved in between
$publisher->publish($record, $userId);           // checks, then copies the working copy to the snapshot
$publisher->state($record);                      // PublishState::Draft, Published or Changed
$publisher->changes($record);                    // (since 0.75) what a publish would change: ['title', '_locales']
$publisher->live('post', $id);                   // what the site shows: the snapshot
$publisher->query('post');                       // a QueryBuilder over the snapshots
$publisher->unpublish('post', $id);              // the snapshot goes; the page answers 404
$publisher->discard('post', $id);                // the working copy goes back to what is published (RecordWriter::discard() applies the schedule rule)
$publisher->revisions('post', $id);              // newest first: list<Revision>
$publisher->restore('post', $id, $revisionId);   // into the working copy; publish it again to go live
```

`enabled($type)` says whether a type publishes. A type that does not publish is its own live copy:
`live()` and `query()` read the record itself.

**What changed** (since 0.75): `changes($working)` lists the top-level keys whose working value
differs from the published one: the type's fields first, in field order, then the other content
keys a record carries (its languages `_locales`, its SEO `_seo`, translation marks), sorted. The id
and the publishing keys never count, nor does an empty value against a missing one; both sides are
compared as stored, so the record may be the stored one or one built in PHP. A record never
published has nothing to compare with and gives `[]`. It reads the snapshot once. An admin shows it as "2 changes: Title, Languages".

**Versions:** each save writes `_version` + 1. Pass the `_version` your form loaded as
`$loadedVersion`: a save that lands in between makes yours throw `VersionConflict` (`->current` is
the stored version) instead of overwriting it. `null` skips the check (importers, the scheduler).
`publish()`, `discard()` and `restore()` take it as their last argument too.

**Storage:** on JSON storage nothing needs creating. On SQL storage `php preflow migrate` (its step
`publishing.tables`) creates the tables of every type that publishes: the working copies (the
type's table), `{table}__published` and `{table}__revisions`, with the publishing keys (`_version`,
`_publish_at`, `_share_token`, `_order`, `_locales`, …) besides the fields. Missing tables and
columns are added, nothing is dropped or changed, so running it again changes nothing. Run it after
turning publishing on for a type or adding a field.

## Saving and deleting records

`RecordWriter` (from the container) is the one write path for records, of every type: an admin, a
translation run and your own code save through it and get the same steps.

```php
use Preflow\Publishing\RecordWriter;
use Preflow\Publishing\SaveOptions;

$writer = $container->get(RecordWriter::class);

$writer->create($record);                                  // a new id when it has none; last in an orderable list
$writer->save($record, new SaveOptions(by: $userId, publish: true, loadedVersion: $version));
$writer->setField($record, 'status', 'archived');          // one field, then save()
$writer->reorder('faq', $id, 'up');                        // false when it is first already
$writer->restore('post', $id, $revisionId, new SaveOptions(mayPublish: false)); // a revision into the working copy
$writer->discard('post', $id, new SaveOptions(mayPublish: false));              // back to what is published
$writer->duplicate('post', $id, new SaveOptions(by: $userId), ['title' => ['en' => 'About (copy)']]); // (since 0.75) a draft copy
```

A save runs `SaveHooks::before()` first (a `ValidationException` there stores nothing). A type that
publishes then saves its working copy (`saveDraft()`) and, with `publish: true`, publishes it;
`SaveHooks::after()` runs once the working copy is stored, also when the publish fails: then
`PublishFailed` is thrown, with the stored draft (`$outcome`) and the cause (`getPrevious()`: the
checks' `ValidationException` or a `VersionConflict`). A `ValidationException` or `VersionConflict`
thrown by `save()` itself means nothing was stored (unless `SaveHooks::after()` threw it). Any other
type is checked (`RecordChecks`), stored (or handed to `SaveHooks::save()` when the type saves
itself), indexed and synced with its addresses; `SaveOutcome::$addressesLeft` says whether it left
the site.

`SaveOptions`: `by` (who), `mayPublish` (false: the save cancels a pending schedule, so changes of
someone who may not publish never go live unreviewed), `publish`, `loadedVersion` (else
`VersionConflict`), `publishAt` (null keeps the schedule, `''` clears it, `Y-m-d\TH:i` sets it; a
past time clears it), `kind`: the kind of write (`WriteKind`), handed to `SaveHooks::before()` and
`after()` so a hook can leave some alone (one that reworks images has no business with an autosave):
`Save` (the default), `Autosave`, `Bulk`, `Translate`; `reorder()` passes `Reorder`, the deleter's
detaches `Detach`.

When a publish fails and `SaveHooks::after()` throws too, `PublishFailed` is what the caller gets;
the hook's exception goes to the `Psr\Log\LoggerInterface` bound in the container as a warning
(psr/log, suggested).

`restore()` copies a revision into the working copy (`Publisher::restore()`) under the same schedule
rule, in one write; it runs no save hooks (it is not an edit) and never publishes. `discard()` does
the same with the published content (`Publisher::discard()`).

`RecordDeleter` deletes through the delete plan of the type's relations (cascade, detach,
restrict): `plan($type, $ids)` says what would happen, `delete($type, $ids, $target)` does it
(every hook that may refuse runs before anything is written; on SQL storage the writes run in one transaction,
JSON-file writes cannot be rolled back), and throws `DeleteRefused` (with the plan) when records still point here. Deleted
records lose their snapshot, revisions, index entry and addresses (`$target`: where their old links
lead).

## Reading what is live

- **`LiveContent`** reads records for rendering: the snapshots of a publishing type on public pages,
  so drafts never reach the site. `showWorkingCopies()` switches one request to the working copies
  (an admin, its preview, a share preview). `find($type, $id)`, `query($type)`.
- **`PublishedModels`** does the same for a model class, as read-only objects of the class:
  `find(Job::class, $id)`, `query(Job::class)`. Saving one throws.

## Scheduled publishing

`_publish_at` on the working copy (`Y-m-d\TH:i`, in `app.timezone`) marks it for publishing. Publish
what is due from cron:

```
*/5 * * * * cd /path/to/app && php preflow publish:scheduled
```

The command holds a lock (`storage/locks/publish-scheduled.lock`), so overlapping runs do nothing.
Publishing clears the schedule; a record that fails its checks stays scheduled and is reported on
every run.

`php preflow publish <type> [--id=<id>] [--all]` publishes the drafts of a type at once, e.g. after
turning publishing on for a type with content. Without `--all` it skips records that are already
published, and those `PublishSkips` gives a reason for.

Both commands need `preflow/devtools`; the package lists them in its `composer.json`
(`extra.preflow.commands`), so installing it is enough.

## Share previews

`PreviewLinks` signs a token for one record: `create($type, $id, $nonce)` and `verify($token)` (a
`PreviewTarget`, or null when it is forged, malformed or expired). The nonce is the record's
`_share_token`; changing it revokes every link. Links are signed with `app.key` and are off
(`enabled()` false) while the key is empty or still the default. A page that verifies a token turns
on `LiveContent::showWorkingCopies()` for that request.

## Configuration

`config/publishing.php` (a missing file means the defaults):

```php
return [
    'revisions' => 20,              // kept per record; 0 or null keeps every one
    'complete_languages' => false,  // refuse to publish while a visible language lacks texts
    'preview_ttl' => 604800,        // how long a share-preview link works, in seconds
];
```

`revisions` must be a whole number of 0 or more, or `null`; anything else stops the boot with an
`InvalidArgumentException`.

## Your own rules

The `Publisher`, `RecordWriter` and `RecordDeleter` ask these interfaces. Bind your own in a
service provider's `register()`; the package binds a plain default for each one you leave open:

| Interface | Asks | Default |
|---|---|---|
| `PublishingTypes` | `publishes($type)`, `publishing()`: which types publish | `SchemaPublishingTypes`: the `"publishing"` flag |
| `RecordChecks` | `validate($record)`: the rules before a publish (throws `ValidationException`) | none |
| `LanguageChecks` | `clearMachineMarks($record)`; `incomplete($record)`: errors keyed `_translations.<locale>`; `stateKeys()`: translation keys a restore or discard clears when the content lacks them | none |
| `RecordIndex` | `refresh($record)` after each save, `forget($type, $id)` | none |
| `RecordAddresses` | `sync()` after a publish or a save, `removed()` after an unpublish or a delete (to a `RemovalTarget`), `changed($before, $after)` (`$before` a closure that reads the old snapshot when called), `finalTarget($target, $deleting)`: where a delete's old links lead | none |
| `RecordColumns` | `columns($definition)`: columns your app writes besides the fields, added to the type's tables (a restore or discard clears those the content lacks) | none |
| `PublishSkips` | `reason($record)`: why `publish <type>` leaves a never-published record alone without `--all` | none |
| `SaveHooks` | `before($record, $kind)`, `after($record, $kind)` (a `WriteKind`), `beforeDelete()`, `afterDelete()` around each write; `replacesSave($type)` and `save($record, $previous)` for a type that stores its records itself | none |
| `RecordTypes` | `orderable($type)`: records kept in a manual order (`_order`) | none |

`complete_languages` only takes effect with a `LanguageChecks` that finds something.

**Copies and addresses.** `RecordWriter::duplicate()` gives a copy free values in the fields that make
up the record's public address (its route parameters), so the copy never takes over the original's
URL. It learns those fields from `Preflow\Publishing\RecordAddressFields`
(`addressFields($type)`: field name => whether it is a catch-all path), which the bound `RecordTypes`
may implement as well. Folio's binding does. **A site that binds its own `RecordTypes` should
implement `RecordAddressFields` on it too:** without it, `duplicate()` frees only a field named
`slug` or one with the `slug` rule, and copies every other address field unchanged, so the copy
would claim the same address. Its `$overrides` (field => value, or => `[locale => value]` for a translatable field)
set values of the copy before the address is freed, e.g. a title marked as a copy; an overridden
address field still takes a free value. An override is refused (`InvalidArgumentException`, nothing
written) when it names no field of the type, a key the copy's system sets (the id, `_version`,
`_publish_at`, `_share_token`, `_published_at`, `_published_by`, `_order`, or the created and
updated values of a `#[Timestamps]` model), a language the type does not have, or gives a
translatable field anything but a locale map (or `null`, which empties it).

`duplicate()` (since 0.75) copies every language, its own blocks (new block ids) and the same
references; the copy gets a new id, the free address above, the `-2`, `-3`… suffix in its other
unique text fields (other unique fields are left empty), the system keys of a new record, a place
right after the record in an orderable list, and, for a type that publishes, a draft that was never
live (`publish: true` is refused).

**Restore and discard** replace the working copy's content: its fields, the `stateKeys()` and the
`RecordColumns`, take the revision's or snapshot's values (a field the revision does not have
becomes empty). The schedule, list position and share token stay, and so do other columns of your
own that are no fields.

## With Folio

Folio registers this package and binds its rules: a type with a `frontend` route and every
singleton publish unless they say otherwise, a publish runs Folio's field and type rules, languages
follow Folio's translation status, the review index and redirects follow each publish, and the page
cache drops the pages at the addresses a record leaves and takes. The writer and the deleter run its type
customizations' hooks (`#[BeforeSave]`, `#[AfterSave]`, `#[BeforeDelete]`, `#[AfterDelete]`,
`#[SaveRecord]`) and keep its orderable lists in order. Its own columns (translation
marks, SEO) go into the tables of publishing types, and `publish` skips the records a legacy
frontend `published` field marks as drafts. Folio's README ("Drafts and
publishing") has the screens.

The names of before 0.71 were removed in 0.72: `Preflow\Folio\Publishing\*`,
`Preflow\Folio\Content\LiveContent` and `Preflow\Folio\Content\RecordDeleter`, the commands
`folio:publish` and `folio:publish-scheduled`, and `config/folio.php` `'publishing'`.
