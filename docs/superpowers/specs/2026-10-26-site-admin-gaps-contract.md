# Step 9 — contract between framework 0.38 (Part A) and the site plan (Parts B–F)

Both plans use exactly these names. The framework plan may refine internals, not these surfaces.

A1 Editable site texts
- A site lists staff-editable translation groups in `config/folio.php`:
  `'translations' => ['editable' => ['review-mail']]`.
- The Translations screen offers those groups, in every locale of `config/i18n.php` (here de, fr, it),
  besides the admin groups; permission `translations.edit` as today.
- Overrides of an editable group apply on every request where the translator renders that group
  (public requests and mails included), plain text, placeholders validated as today. Admin groups
  keep their admin-only behaviour.

A2 Sidebar count on a screen
- `#[NavBadge]` may sit on a public method of a `#[FolioScreen]` class: `public function waiting(): ?int`.
  Null or 0 shows no badge. Same look as the type badge.

A3 Screen and section labels through keys
- `#[FolioScreen(label: …)]` and section names go through Folio's label resolution like type labels:
  a label that is an existing translation key is translated (site keys `site.screens.{name}`,
  `site.sections.{section}` in `lang/{locale}/site.php`); plain text stays as written.
- Extension attribute labels (`#[ListColumn]`, `#[FormPanel]`, `#[RecordAction]`) of a project
  extension (app/Folio/*) may be `site.*` keys too, without a composer translations group.

A4 Members (preflow/members) -- deferred to after the members split
- `Members::lock(Record $member): void` / `Members::unlock(Record $member): void` /
  `Members::isLocked(Record $member): bool` — field `locked_at` (datetime, null = not locked).
  A locked member cannot sign in (sign-in shows the same neutral refusal as a declined one, text
  key in `members`), and lock ends every session at once (bumps `auth_epoch`, revokes remember
  tokens). Lock is separate from the registration decision: member state (Waiting/Member/Declined)
  unchanged; the members list shows a "Gesperrt" badge. No mail.
- `Members::endSessions(Record $member): void` — bump `auth_epoch` + revoke remember tokens (used by lock).
- `Members::resendConfirmation(Record $member): void` — fresh confirmation link to the current
  address through `MemberMailer` (same mail as the public resend); throws `\LogicException` when
  the member is already confirmed.
- Field `last_login_at` (datetime, readonly in Folio) set on each successful sign-in.

Release: all packages 0.38.0; site moves to `^0.38`.

A5 Panel null — a `#[FormPanel]` method may return null: no heading, no box, no side column or extras section on its account; an empty string still renders.

<!-- A6 (from Task 6) is appended by that task. -->
