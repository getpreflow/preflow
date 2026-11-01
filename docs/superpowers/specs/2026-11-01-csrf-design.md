# CSRF for every form, with or without a session — design

Owner request, 2026-11-01. Roadmap: "CSRF without a session (core)" under "Security and data integrity".

## Why

Two problems with today's `Preflow\Core\Http\Csrf\CsrfMiddleware`:

1. **Public forms have no check.** The middleware returns early when the request has no session. Sites with
   `auth.session.paths` (smart-stack, holz-objekte, BG Genius, smeyer.me) start a session only under a few paths,
   so contact, registration, like and job forms on public pages are never checked, and `csrf_field()` prints
   nothing there. holz-objekte works around it with its own `App\Http\FormToken` (an HMAC tied to the visitor's
   address, which fails when the address changes or the page stays open over midnight).
2. **Forms left open too long fail.** The token lives in the session; once the session has expired (2 hours on
   smart-stack), the POST is refused (403, or `CsrfSessionExpiredException`). The owner sees this most on login
   forms, and finds it annoying.

The owner's worst case is login data being stolen. CSRF cannot do that (a forged request can't read the answer;
passwords travel over HTTPS; script injection is the CSP's job). What CSRF can do: send forms in a visitor's name
(spam, fake registrations, likes counted per address), act for a signed-in member, or sign a visitor into an
attacker's account (login CSRF). This design closes those.

## Goals

- Every unsafe request (POST, PUT, PATCH, DELETE) is checked, with or without a session.
- A real visitor's form never fails because the page was open for a long time.
- Most secure combination where a session exists (owner chose this): the browser's origin check **and** the
  session token, both must pass.
- holz-objekte's `FormToken` can be deleted; forms use `csrf_field()` everywhere.

## Non-goals

- No new cookie on public pages (sites keep public pages cookie-free; GDPR and caching reasons).
- No starting a session on public pages or on POST.
- Not an anti-spam measure: a script posting directly to a public form isn't CSRF and isn't stopped by this
  (sites keep their throttles/honeypots).
- No change to component actions (`/--component/` stays exempt; its tokens are signed already).

## Anti-goals (a failure even if it "works")

- A cross-site POST that gets through because some header was missing.
- Webhooks or API clients that worked before suddenly getting 403s after an upgrade, without the upgrade notes
  saying so.
- A POST whose session expired being *applied* in a member's name without a fresh proof (see "Expired sessions").

## How it works (decided)

### Layer 1: origin check (every unsafe request)

Decide where the request came from, using the first signal present:

1. `Sec-Fetch-Site`: `same-origin` passes; `cross-site` and `same-site` are refused (a sibling subdomain is not
   trusted by default); `none` (typed or bookmarked, no page) is refused for unsafe methods.
2. else `Origin`: passes when it equals the site's origin (`app.url`) or the request's own scheme+host, or one of
   the configured trusted origins; `null` counts as no signal.
3. else `Referer`: same comparison on its origin.
4. no signal at all: Layer 2's token decides alone.

A refused origin is refused even with a valid token.

Trusted origins: config `csrf.trusted_origins` (list of `https://host[:port]`), for example a stage host posting
to production. Default empty. *(My call after the builder check:)* when `Sec-Fetch-Site` is not `same-origin`, the
`Origin` header is still compared against this list, so a listed sibling or stage host passes despite
`Sec-Fetch-Site: same-site`/`cross-site`.

### Layer 2: token

- **With a session:** the session token as today (`CsrfToken`, `_csrf_token` field or `X-CSRF-Token` header).
  Both layers must pass.
- **Which check applies** *(my call after the builder check)*: decided by the request, not by the page.
  - The request carries **no session cookie** (a public path; or a visitor whose browser blocks cookies on a site
    with a session everywhere, like BG Genius): treated as "without a session" below. Without ambient
    credentials there is nothing in the visitor's name to forge.
  - It carries a session cookie and the session is alive: the session token, both layers must pass.
  - It carries a session cookie but the session is new (expired, or the page was public and the visitor has an
    old cookie): a valid **stateless** token plus a passing origin is accepted (a public page posting to a session
    path); a **session-shaped** token that doesn't match is the "Expired sessions" case below. The two token
    kinds must be told apart by their format.
- **Without a session:** a stateless signed token: HMAC-SHA256 with `app.key` over a version, a time slot and a
  fixed purpose string, in the same `_csrf_token` field / header. Not tied to a visitor (no cookie, no address),
  so the same token is valid for everyone in its window. That makes pages cacheable and is fine *because* it is
  only the fallback for requests without origin signals; it is never accepted against a refused origin.
  Validity: the current and the previous slot, slot length 24 hours, so a page works for at least a day.
  `csrf_token()` / `csrf_field()` print it when there is no session (today they print nothing), and the
  `CsrfToken` request attribute carries it too, because Folio, rbac, FormBuilder and others read the attribute
  and print `{{ csrf }}` themselves.
- **Key** *(my call)*: when `app.key` is empty or the framework's built-in default, the stateless token is never
  issued nor accepted (fail closed: requests without origin signals and without a session are refused), and
  debug mode logs a warning once per request that needs it.
- Without a session, a request **with** a passing origin signal does not need the token (old forms without
  `csrf_field()` keep working on modern browsers).

### Expired sessions

The session existed when the form was rendered, but not anymore (today: `CsrfSessionExpiredException` /
`ExpiredSessionHandler`). New rule:

- origin refused or no signal: refused as today (403 / exception).
- origin passes and the path is a **sign-in form** (a list packages register: members' login and Folio's staff
  login; sites can add paths through config `csrf.sign_in_paths`): let through with the new session and its
  fresh token. The posted password is the proof; login CSRF is blocked by the origin check.
- origin passes, any other path: the site's bound `ExpiredSessionHandler` answers first (holz-objekte's wizard
  keeps working unchanged). Without one, the framework's default answer: the request is **not applied**; a full
  POST gets a 303 back to the page it came from (Referer, same origin only, else `/`) with a flash
  "Your session had expired, please send the form again" (translated), and the posted fields kept as old input
  so the form shows them again *(my call: a small old-input flash in core, read by validation's `old()`,
  FormBuilder and members; never kept: `_csrf_token`, any field whose name contains `password`, file uploads)*; htmx gets a 409 with `HX-Refresh: true`... (exact htmx answer: builder's call,
  it must not apply the data). Nothing runs in the member's name: a remember-me cookie could sign them in
  again, so the data waits for one more click from a page with a fresh token.

### Config

`config/csrf.php` (optional; missing means the defaults):

```php
return [
    'exempt' => ['/--component/'],     // path prefixes without a check; only for routes that can't carry the attribute below
    'trusted_origins' => [],           // extra origins allowed to post
    'sign_in_paths' => [],             // extra sign-in forms (packages register their own)
];
```

**Exempting a route where it is declared (owner, 2026-11-01).** A webhook or other server-to-server endpoint is
exempted on the controller, not in a third place that gets forgotten: `#[CsrfExempt('why')]` on a controller
class (all its routes) or on one route method, with a required reason (for example
`'Stripe webhook, verified by its signature'`). The CSRF middleware runs before routing today, so for unsafe
requests it must find the matching route first (how: builder's call; core must not depend on routing, so
routing records the flag on its route entries and core asks through a small interface or request attribute).
Cost (owner asked, 2026-11-01): the flag is read in the route scan that already happens (no extra reflection;
cached with the routes once route caching exists); only unsafe requests look the route up; the match is kept on
the request and the router's dispatch reuses it, so a POST is matched once, as today.
File-based routes can't carry attributes; for those, and for paths outside the router, `csrf.exempt` stays as
the fallback.

**A public page posting into a session path (owner chose, 2026-11-01):** accepted when its stateless token is
valid and the origin passes, even if the visitor's old session cookie leads to a new session and a remember-me
cookie signs them in again (option a; the stricter refusal was rejected because those forms would fail for anyone
whose session ran out).

Requests carrying an `Authorization` header and no cookie are not checked (API clients; nothing ambient to
abuse). The middleware is always added (today only when a session service exists).

Paths (`exempt`, `sign_in_paths`, the packages' sign-in paths) are matched after the locale prefix is stripped
(`/de/members/login` matches `/members/login`), and members registers its login from its configured route
prefix, not a fixed string.

### Upgrading (release notes must say this)

Unsafe requests without a session were unchecked until now. A site with a public POST endpoint called by a
server (a webhook, a payment provider, a cron job via HTTP) must mark its route with `#[CsrfExempt('…')]` (or, for
a file route, list it in `csrf.exempt`) before upgrading.
For the sites: list their public POST routes in the release checklist and check each by hand: smeyer.me's
contact form, holz-objekte's like and contact forms. BG Genius has a session on every path, so its POSTs are
checked already; what changes there is that cookieless visitors pass on the origin check.

## Left to the builder

- Class layout inside `preflow/core` (`Http/Csrf/`), how packages register sign-in paths (a small registry
  like `SeoSite`), the htmx answer for an expired non-sign-in POST, translation keys.
- Token encoding (keep it URL- and HTML-safe), constant-time comparison everywhere.
- Tests: every row of Layer 1 (including `Sec-Fetch-Site: same-site`, `Origin: null`, Referer only, nothing),
  stateless token current/previous/expired slot and wrong key, session + refused origin with a valid token,
  expired session on a sign-in path and on another path (with and without a site handler), `Authorization`
  without cookie, exempt paths; a Folio and a members integration test.
- README sections in core (CSRF), auth (`csrf_field()` without a session), members and Folio (sign-in paths).
- holz-objekte: replace `FormToken` with `csrf_field()` after the release (a site task, not part of this
  framework change).
