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
$publisher->live('post', $id);                   // what the site shows: the snapshot
$publisher->query('post');                       // a QueryBuilder over the snapshots
$publisher->unpublish('post', $id);              // the snapshot goes; the page answers 404
$publisher->discard('post', $id);                // the working copy goes back to what is published
$publisher->revisions('post', $id);              // newest first: list<Revision>
$publisher->restore('post', $id, $revisionId);   // into the working copy; publish it again to go live
```

`enabled($type)` says whether a type publishes. A type that does not publish is its own live copy:
`live()` and `query()` read the record itself.

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
```

A save runs `SaveHooks::before()` first (a `ValidationException` there stores nothing). A type that
publishes then saves its working copy (`saveDraft()`) and, with `publish: true`, publishes it;
`SaveHooks::after()` runs once the working copy is stored, also when the publish fails. Any other
type is checked (`RecordChecks`), stored (or handed to `SaveHooks::save()` when the type saves
itself), indexed and synced with its addresses; `SaveOutcome::$addressesLeft` says whether it left
the site.

`SaveOptions`: `by` (who), `mayPublish` (false: the save cancels a pending schedule, so changes of
someone who may not publish never go live unreviewed), `publish`, `loadedVersion` (else
`VersionConflict`), `publishAt` (null keeps the schedule, `''` clears it, `Y-m-d\TH:i` sets it; a
past time clears it).

`RecordDeleter` deletes through the delete plan of the type's relations (cascade, detach,
restrict): `plan($type, $ids)` says what would happen, `delete($type, $ids, $target)` does it, all
or nothing, and throws `DeleteRefused` (with the plan) when records still point here. Deleted
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
| `RecordColumns` | `columns($definition)`: columns your app writes besides the fields, added to the type's tables | none |
| `PublishSkips` | `reason($record)`: why `publish <type>` leaves a never-published record alone without `--all` | none |
| `SaveHooks` | `before()`, `after()`, `beforeDelete()`, `afterDelete()` around each write; `replacesSave($type)` and `save($record, $previous)` for a type that stores its records itself | none |
| `RecordTypes` | `orderable($type)`: records kept in a manual order (`_order`) | none |

`complete_languages` only takes effect with a `LanguageChecks` that finds something.

**Restore and discard** replace the working copy's content: its fields, and the `stateKeys()`, take
the revision's or snapshot's values (a field the revision does not have becomes empty). The
schedule, list position and share token stay, and so do columns of your own that are no fields.

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

Until 0.72 the names of before 0.71 still work: `Preflow\Folio\Publishing\*`,
`Preflow\Folio\Content\LiveContent` and `Preflow\Folio\Content\RecordDeleter`, the commands `folio:publish` and `folio:publish-scheduled`,
and `config/folio.php` `'publishing'` while `config/publishing.php` does not exist (with a
deprecation note).
