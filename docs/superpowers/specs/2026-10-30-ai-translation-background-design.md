# AI translation in the background — design

Date: 2026-10-30. Follows `2026-10-30-ai-translation-design.md` (released as 0.47.0/0.47.1). Calls marked
**(my call)** are defaults the owner did not decide explicitly and may change.

## Why

The first real use (a BG Genius game, from the game's edit form) showed two problems with "fill the form,
review, save" for records:

- **Waste.** The form's Translate sent every text of the record, including about 400 flow texts the game
  form cannot show. They were translated, paid for, and thrown away ("not on this form").
- **Time.** A model translation of a game takes minutes (four Claude calls of about 40 s each for one
  flow). Waiting in front of a form for that is not a workflow; it also held the PHP session for minutes
  (fixed in 0.47.1).

The owner's direction: ask for the translation, it runs in the background, and the result is saved as a
new draft of that record, marked for review.

## How the owner works (owner, 2026-10-30)

- Start: on a record, click Translate, pick the language (optionally a note), confirm, leave.
- Find: the list already marks records with unchecked texts (badge and filter). The dashboard gets a
  widget listing everything waiting for review.
- Check: open the edit view, read, fix what needs fixing, publish. **Publishing is the check.** There is
  no separate per-text confirmation.

## Goals

1. A record's Translate queues a translation job instead of filling the form. The page can be closed
   right after.
2. Jobs are processed in the background in short pieces that fit cyon: a console command run by cron,
   every minute.
3. Everything the record holds is translated, including structured fields edited on their own screens
   (BG Genius flows), because the result no longer has to fit on a form.
4. Results land as a draft with "not checked" marks, exactly as whole-type runs do today. The live
   version is untouched until publish.
5. Publishing clears the marks of the published locales.
6. A dashboard widget: records waiting for review, and translation jobs running or failed.
7. Whole-type runs (list → Translate…) become the same thing: queued, processed in the background.
8. Settings → Translations says what to do when the selected language is the source language, instead
   of showing no button.

## Non-goals

- Automatic translation of new or changed content (cron deciding *what* to translate). The framework
  processes requested jobs only; a project can still create jobs itself through the core API.
- Notifications by email or similar when a job finishes.
- Per-text accept in the record form.
- Changing the fill-the-form flow for interface labels and media alt texts: those answer in seconds and
  stay as they are.

## Anti-goals

- Paying for texts that are thrown away.
- Two processes translating the same record at once (paying twice, or one overwriting the other).
- A queued translation overwriting text a person changed after the job read it (the existing version
  and text check stays).
- A job that silently never runs: when nothing processes the queue, the screen must say so.
- AI text going live without a person publishing it (types with publishing). Types without publishing
  keep the 0.47 rule: a run there needs the explicit "these go live" confirmation.

## Design

### Core (`preflow/i18n`, no Folio)

- `JobRunner` gets a worker entry point: process pending jobs, oldest first, until a time budget is
  used up (default 50 s, so a run started every minute ends before the next).
  - **(my call)** One record per step as now; the budget check happens between steps. A step that has
    started always finishes (as in 0.47).
- **Lease (my call: per record).** Before a step, the driver claims the record it is about to translate,
  keyed by collection and record id (not by job), so two jobs touching the same record (German and
  French, or `flow.` and the whole record) never run on it at once. The claim is released after the
  step and expires after 10 minutes **(my call)**, so a crashed process doesn't block the record. A
  worker that finds the record claimed skips to the next pending item or job; it doesn't wait. Any
  driver (cron, a browser page) claims first. This closes the parked "overlapping Continue pays
  twice" item.
- **States (my call).** A new state `queued` (started, waiting for a worker) beside the 0.47 states;
  `running` means a driver is working on it now. Old job files without the new fields still load.
- **Prefix (my call).** A job may carry a path prefix (for example `flow.`), stored on the job and
  passed to the text source's units, with the same meaning as the 0.47 `suggest` prefix (the path
  equals the prefix or starts with `prefix.`). The estimate uses it too. Default: the whole record.
- **Go-live confirmation (my call).** For types without publishing, the confirmation is given when the
  job is queued and stored on the job; the worker refuses to write a job that lacks it.
- A console command, **(my call)** `translate:work [--seconds=50]`, runs the worker. Output: one line per
  record and a total cost. It exits quietly when the queue is empty or AI is off.
- The worker records when it last ran (a timestamp in the job store directory), so screens can tell
  whether a worker exists.
- Jobs keep the 0.47 state model (planned/running/stopped/done/failed, per-item results and cost).
  "Queued" means planned and started, waiting for a worker.

### Folio

- **Record form.** The Translate button on a language tab opens the dialog as now (language, scope,
  note, estimated cost). Confirm queues a job for this record and that language, and the dialog says
  "Queued. The translation is saved as a draft for review; you can leave this page." The form-filling
  for records goes away, and with it the side-by-side review on the record form.
  - While a job for the record is queued or running, the form shows that ("German translation queued
    / running since …"). It doesn't block editing; the version check protects edits.
  - **(my call)** Queuing a second job for the same record and language while one is pending answers
    with the pending one instead of a duplicate.
- **Lists.** "Translate…" on selected records or the whole type queues one job, as the run screen does
  today, minus the need to keep the page open. The run screen becomes a status page (progress,
  failures, Stop). While it is open it may drive steps itself, through the same lease, so a site
  without cron still gets work done. **(my call)**
- **Dashboard widget "Waiting for review".**
  - Records with unchecked texts, grouped by type: record label, languages, number of unchecked texts,
    link to the edit view on that language tab.
  - Running and failed jobs: what, since when, cost so far, failure reason.
  - Shown to users who may translate or edit those types (`ai.translate`, plus the type's view
    permission per row). Jobs are visible to everyone with `ai.translate`, not only to whoever queued
    them **(my call)**; the job records who queued it, and the widget shows that name.
  - Finding unchecked records must not load every record of every type on each dashboard view.
    **(my call)** A small index of records with unchecked texts, kept up to date by the worker and by
    Folio's saves and publishes. It is a cache: a command rebuilds it from the records, and saves
    that bypass Folio (imports, project code) may leave it stale until the next rebuild or the next
    save of that record. The builder decides where it is stored (job store directory or a table).
- **Publishing clears the marks (my call on which locales).** Publishing a record clears its
  `_machine_translated` entries for the locales that go live with it, that is the locales ticked in
  "Visible in". An AI write never ticks that box, so German that nobody has looked at stays marked
  even when the record is published for an English fix. The clearing is part of Folio's publish path
  (form, bulk and `folio:publish`, and the scheduler), covering structured fields such as flows,
  whose marks live in the same `_machine_translated` map by path. The 0.47 publish warning about unchecked texts and the
  `publishing.checked_translations` setting are removed **(my call)**: publishing is the check (owner).
  `folio:publish` drops its warning too. A config that still sets the key is ignored, no error; the
  setting existed only since 0.47.0 and no site uses it.
- **"No worker" notice.** If the worker hasn't run in the last 5 minutes **(my call)** and jobs are
  waiting, the dialog, the status page and the widget say so, with the cron line to add.
- **Settings → Translations.** When the selected language is the one the texts are translated from,
  show a line telling the user to choose a language to translate into.

### BG Genius (project)

- The flow screen's Translate queues a job for that game with prefix `flow.` and says so. Review stays
  where it is: the flow screen shows unchecked texts as today, and publishing the game clears them.
- Live server: a cron entry for `translate:work` once AI is enabled there (owner's go-ahead, like any
  server change). Locally: run the command by hand or add it to the local crontab.
- Docs (CLAUDE.md): the cron line.

### Cost and limits

- The dialog shows the estimate before queueing, as now. The job records the real cost.
- The worker stops a job on `AiUnavailable` as today; the widget shows it as failed with the reason.
- Batch sizes and output room as fixed in 0.47.1.

## Constraints

- cyon: cron available, no long-running processes; one worker run must end within the cron interval.
- AI off: no button, no queue, the worker exits at once, no widget.
- Decoupling (owner rule): queue, lease and worker are in `preflow/i18n`; Folio only adapts (screens,
  widget, its text source). Usable without Folio.
- Upgrade: no new columns expected. Sites with AI on need the cron line; the release notes say so.

## Left to the builder

- Where the lease and the review index are stored (files beside jobs, or a table), within the
  constraints above.
- The exact wording of dialog and widget texts (English and German, Folio's tone).
- Whether the record form's now-unused review code is removed or kept for labels/alt (whichever keeps
  less code).
