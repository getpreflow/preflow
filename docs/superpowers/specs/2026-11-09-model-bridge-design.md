# One model definition: typed PHP models that Folio can edit

Owner conversation 2026-11-09. Comes before the starters (`2026-11-09-starters-design.md`), which depend on it.

## Intent

Today the data layer has two ways to define a model, and they don't know about each other:

- **Typed models:** a PHP class extending `Model`, with `#[Entity]` and `#[Field]`. App code gets a `Job` object with code completion and type checks. Folio cannot edit these.
- **JSON types:** `config/models/job.json`. App code gets a generic `DynamicRecord` (`$record->get('title')`). Folio edits these; all 5 production sites use them.

The owner thought the JSON files were the bridge to real models. They aren't, and once that was clear the owner decided: **the PHP class is the source of truth.** Code completion and type checking matter more than "you don't need models, just add a JSON file".

The goal: a developer writes one PHP model class, app code gets typed objects, and Folio edits the same model without a second definition.

This is central to the website's claims. The starters show it ("the job model stays the app's own, Folio edits it"), and the Folio page tells it ("repository-first editing of your own models").

## Goals

- **One definition.** A model written as a PHP class is all a developer needs. The data layer derives from it the type Folio works with, so nothing defines the same table twice.
- **Folio edits it like a JSON type.** Lists, search, filters, forms, drafts, publishing, version-checked saves, relations, the media library, translations, SEO fields and page-cache tracking all work the same for a class-backed model as for a JSON type.
- **Typed in app code.** Reading, querying and saving give the app's class: `$dm->find(Job::class, $id)`, `$dm->query(Job::class)`, `$dm->save($job)`. Public pages read what is published, as they do for JSON types.
- **Parity with JSON types.** Everything a JSON type can express can be expressed on a class: relations (with `onDelete`), assets, blocks, matrix, select options, translatable fields, unique fields and indexes, validation, publishing. A bridge that only works for simple models would be a trap.
- **The class doesn't depend on Folio.** Schema, relations, validation and storage are data-layer attributes. Folio's admin settings (label, icon, section, list columns and filters, publishing on or off) live where Folio settings already live: a slim admin-only file, or a `#[FolioType('job')]` customization class. A site without Folio never sees them.
- **Moving a type from JSON to a class is a small, safe step.** Same table, same data, same ids. Folio's admin, URLs, published copies, translations and the page cache don't notice. A command generates the class (and the admin-only settings) from an existing JSON type, so each move is quick to make and easy to review.

## Non-goals

- **Moving the production sites' types now.** The owner: not a general rule. holz-objekte's objects and BG Genius's games are the first candidates to become classes; other types may stay JSON. Each move is its own small change later, per site and per type.
- **Removing JSON types.** They stay fully supported. They're the right tool for content with no code behind it (pages, news), and the docs will say so: content without code is a JSON file; the app's own models are PHP classes; Folio edits both.
- **Generating JSON from classes as files.** The derived type exists in memory (and in the boot manifest), not as a file that could drift.

## Anti-goals (failure even if it works)

- **Two sources of truth.** If a field has to be written in the class and again in a JSON file, the bridge has failed.
- **A Folio-shaped model.** If the app's `Job` class has to import Folio attributes, extend a Folio class or know about drafts and working copies, "Folio is an adapter" is no longer true.
- **A move that touches data.** If moving a type from JSON to a class needs a data migration, renamed tables or new ids, the owner's per-type moves become risky and won't happen.
- **Second-class class models.** If some Folio feature works for JSON types and quietly not for classes, developers will learn to avoid classes.

## Constraints

- **Framework rules (owner):** features live in the framework's packages behind public interfaces; Folio is only the UI adapter; project-specific things stay in projects. The bridge lives in `preflow/data` (deriving the type, typed reads of published records) with Folio consuming it through public interfaces.
- **Upgrading must change nothing.** Existing typed models (like the skeleton's `Post`) and all JSON types keep working unchanged. A site opts in per model.
- **Performance:** the derived types go into the boot manifest (`preflow optimize`) like the JSON types' listings, so a class-backed model costs no more per request than a JSON type.
- **The page cache's dependency tracking** must report reads and writes of class-backed models with the same tags as the equivalent JSON type, so a page's dependencies survive a JSON-to-class move.

## Decided by the owner

- The PHP class is the source of truth.
- The bridge is built now, before the starters, because it is central to the framework's claims.
- Whether a site's type moves to a class is decided per type; holz-objekte's objects and BG Genius's games are the likely first ones.

## My calls (the owner can overrule)

- **Folio's admin settings stay out of the model class**, in Folio's existing places (slim admin file or a `#[FolioType]` customization class).
- **Parity with JSON types is a requirement**, not a later goal.
- **JSON types stay** as a first-class option for content without code.
- **A generator command** (`make:model --from-type=<key>` or similar) is part of the bridge, because the owner's per-type moves depend on it.
- **Release:** the bridge ships as its own framework release before the REST pieces and the starters.

## Calls after the builder check (mine)

The rule behind all of them: **a class-backed model behaves exactly like the equivalent JSON type does today**: same tables, columns, ids, stored formats, read and write paths and page cache tags. Where this document is silent, today's JSON-type behaviour is the answer.

- **Opt-in is explicit.** A model class becomes a type only through a data-layer marker naming its type key (an `#[Entity]` option or its own attribute), and appears in Folio only when Folio settings exist for that key. Existing typed models like the skeleton's `Post` change nothing and never show up in the admin by themselves.
- **Discovery:** marked classes are found in the app's model directory (configurable) and cached in the boot manifest, so `preflow optimize` has a fixed source.
- **One definition per key.** A class and a JSON file defining the schema of the same key is a boot error with a clear message, never a silent override. Folio's admin settings set presentation only, never schema.
- **What goes where:**
  - On the class (data-layer attributes):
    - everything with data meaning: field kind, relations with `onDelete`/`onSelfDelete` and the label field, defaults, select options, validation, unique and index, translatable, the asset `accept` list, the id field;
    - also field labels and help, since forms outside Folio use them too.
  - In Folio's settings: admin-only presentation (label, icon, section and order of the type, list columns, filters and sort, read-only in the admin, publishing on or off).
- **Folio's own columns** (`_version`, `_publish_at`, `_share_token`, `_published_at`, `_published_by`, `_order`) stay invisible to the class. A typed save writes the declared fields and leaves columns it doesn't declare untouched, so app code can't null or overwrite Folio's bookkeeping. Version-checked saves go through the data layer as for JSON types.
- **Ids:** the derived type reproduces the id field exactly (name, string or int, generated or auto-increment). The data layer generates uuids on insert, as for JSON types. The generator copies the JSON type's id settings.
- **Reads and writes with publishing on:** app code reads and writes the working copy, as JSON-type code does today. A published read (the snapshot table) returns read-only objects, and saving one is an error. Publishing goes through Folio's public publishing interface, not through `save()`.
- **Blocks and matrix:** typed as arrays on the class, with the stored format unchanged; value objects can come later.
- **Translatable fields:** the stored shape stays the same as for JSON types; how the class exposes the current locale's value is the builder's call.
- **Page cache tags:** exactly the tags the JSON type reports today, published-table reads included. A parity test checks this for a type moved from JSON to a class.

## Left to the builder

- The attribute names and shapes for the field kinds typed models lack today (relations, assets, blocks, matrix, select options, labels).
- Whether the slim admin-only settings are a JSON file, a PHP customization class, or both.
- How typed reads choose between the working copy and the published copy, within "public pages read what is published".
- How Folio turns a record it edits into the typed class and back, within "Folio needs no class-specific code paths".
