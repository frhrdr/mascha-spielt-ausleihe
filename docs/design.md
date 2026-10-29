# Mascha spielt – Ausleihe: High-Level Design

Status: **Draft** · Last updated: 2026-10-24

## 1. Goal

A small web app that tracks which club member has which board game. Members can
look up a game, borrow it and return it from their own phone by scanning a QR
sticker on the box. Volunteers handle exceptions. It has to stay simple enough
that non-developers can run it after the initial build.

### Non-goals

- No payments or deposits (lending is free).
- Not a general club management or membership tool.
- Not a replacement for BoardGameGeek (BGG) as the catalog. **BGG stays the
  source of truth for which games the club owns.**

## 2. Context & constraints

| Topic | Known constraint |
|---|---|
| Collection | ~500 games, changes occasionally. Includes **duplicate copies** and **expansions** (each in its own box). Currently every game can be lent; not-lendable games may appear later. Tracked at [BGG: MaschaSpielt](https://boardgamegeek.com/collection/user/MaschaSpielt). |
| Scale | < 100 members, < 30 loans/week. Performance is not a concern. |
| Storage | Open shelf in a club room. Members often take games **without a volunteer present.** |
| Users | Three tiers (see §4). Members are **not** WordPress users today. **No machine-readable member list exists yet.** Building a proper member database is a separate project. |
| Login | Magic link by email (no passwords). |
| Language | German + English UI and emails. |
| Hosting | Club website is WordPress at IONOS, **probably Managed WordPress** (to be confirmed). Integrating with the site is preferred. Moving to another hosting plan is possible. Moving the whole site to another provider is not preferred. |
| Maintenance | Built by one developer. Must later be run by **non-developers**: syncing the catalog, managing members and printing QR codes all happen in a UI. |
| Legal | Club in Germany → GDPR: keep personal data to a minimum, provide a privacy notice, define retention. |
| BGG API | The BGG XML API now requires a **registered application + token** (an anonymous request returned `401`). Registration takes time, so start early. |

## 3. Core concepts (domain model)

```
Game (from BGG "thing")            Copy (physical box)               Member
─────────────────────────          ─────────────────────────        ──────────────────
bgg_id                             id                                id
name, year, image                  game_id ──► Game                  name, email
type: base | expansion             bgg_collid (BGG collection item)  role: admin | trusted | new
base_game_ids (for expansions)     qr_code (short random token)      language: de | en
players, playtime, weight          label ("#2" for duplicates)       active
synced_at, removed_from_bgg        lendable (bool)                   created_at
                                   location/shelf (optional)
                                   condition_notes
                                   retired (bool)

Loan                               Reservation                       Issue (condition report)
─────────────────────────          ─────────────────────────        ──────────────────
copy_id, member_id                 game_id (any copy will do)        copy_id
checked_out_at / _by               member_id                         reported_by, reported_at
due_at, extended_count             created_at, status                text, (photo?)
returned_at / _by                  notified_at, hold_until           resolved_at / _by
return_note / complete?
```

Key decisions:

- **Game vs. Copy.** BGG lists every owned box as its own collection item with
  its own `collid`, so duplicates come for free. Each Copy gets **one QR code**.
  Loans are per Copy. Reservations are per Game, because any copy will do.
- **Expansions** are their own Games (BGG subtype `boardgameexpansion`) with
  their own Copies and QR codes. Every listed expansion has its own box, so
  they are lent separately and **follow the same rules as base games** (loan
  period, limit, reservations). The UI links them to their base game.
- **Lendable** defaults to true, since every game can currently be lent.
  Later it can come from BGG (see §5.6). **Location** is club-specific and lives
  only in the app.
- **Never hard-delete.** A game removed from BGG is marked `removed_from_bgg`
  and its copies are `retired`, so the loan history stays intact.

## 4. Roles & permissions

| Action | New member | Trusted member | Admin (volunteer) |
|---|:-:|:-:|:-:|
| Browse catalog, see availability | ✅ | ✅ | ✅ |
| See own loans, reserve a game | ✅ | ✅ | ✅ |
| Borrow / return **by scanning, without help** | ❌ | ✅ | ✅ |
| Borrow / return **on behalf of** another member | ❌ | ❌ | ✅ |
| Report a missing part or damage | ✅ | ✅ | ✅ |
| Manage members & roles, upgrade new → trusted | ❌ | ❌ | ✅ |
| Run BGG sync, edit copies, print QR codes | ❌ | ❌ | ✅ |
| Override rules (limits, due dates, reservations) | ❌ | ❌ | ✅ |

New members borrow through an admin, who records the loan in their name.
Upgrading to trusted is a manual admin decision. (Later idea: suggest the
upgrade after N loans returned on time.)

## 5. Main flows

### 5.1 QR sticker

**Copy ID.** Each Copy has a short random ID, about 5 characters, e.g. `K7Q2X`.
It is not the BGG id (that breaks for duplicates) and not sequential. It uses
an alphabet without look-alike characters (Crockford Base32, which leaves out
I, L, O and U) and ends with a **check character**, so typos and misreads are
rejected instead of opening the wrong game.

**Hybrid sticker (chosen approach).** The QR encodes a **URL that ends in the
ID**, e.g. `https://machbarschaft-suedstadt.de/c/K7Q2X`. The sticker also shows the ID in
plain text, the game name and the copy number. There are three ways to
identify a game:

1. **Phone camera:** the regular camera opens the URL directly. No app and no
   extra step. This is the only route that depends on the domain.
2. **In-app scanner:** a "Scan" button in the web app reads the camera through
   the browser and decodes the code with a JS library (e.g. `zxing-js` or
   `html5-qrcode`; don't rely on `BarcodeDetector`, which Safari doesn't support
   reliably). It **reads only the ID from the scanned text and ignores the
   domain**, and it also accepts a bare ID. Old stickers therefore keep working
   even if the domain or hosting changes.
3. **Typing the code:** for damaged stickers or a camera that won't cooperate.
   The check character catches typos.

*Considered and rejected:* printing **bare IDs** with no URL. Then stickers never depend on a
domain, the code is smaller, and nobody can paste a lookalike code that sends
people to a fake page. The cost is that members always open the app first and
tap "Scan". The in-app scanner accepts bare IDs anyway, so switching later
would only change what gets printed.

**Other code formats considered**

| Format | Verdict |
|---|---|
| QR code | **Default.** Fast and forgiving to scan, widely supported. |
| Data Matrix | Good fallback for very small stickers. Square like QR, more compact for short IDs, and scans with the same libraries. |
| 1D barcode (Code 128 / 39) | Only for narrow box sides. Needs a wide strip and scans worse at an angle. |
| ArUco / AprilTag markers | Simple black-and-white markers that can be scanned many at a time, which would allow taking inventory by photographing a whole shelf. They hold only a number: AprilTag 36h11 has 587 IDs, too few to grow; ArUco has a set with 1000. Possible "later" feature, overkill for the MVP. |
| Publisher barcode already on the box | Identifies the *game*, not the copy: duplicates share it, and not every game has one. At most a help when adding new games. |
| Printed text + text recognition | Unreliable; plain text is only for people to type in. |
| NFC stickers | Reading NFC from a website works only in Chrome on Android, so iPhones would need a URL on the tag. Not a fit. |

**Printing**
- Admins can print sticker sheets from the app: "all new copies since the last
  print", or a chosen selection. Use durable stickers (e.g. vinyl). Put them in
  the same spot on every box (suggestion: the short side of the box, so they
  can be scanned while the game is on the shelf).

### 5.2 Scan → borrow / return (trusted member)

```
Scan QR ─► logged in? ──no──► enter email ─► magic link ─► back to this copy
              │yes
              ▼
        Copy status?
        ├─ available ────────────► [Ausleihen] (checks limit & reservations) ─► due date shown
        ├─ on loan to me ────────► [Zurückgeben] ─► pop-up "Vollständig?" ─► yes / skip: done
        │                                                                └► no: report missing parts
        ├─ on loan to someone ───► "Found it on the shelf?" ─► mark returned + notify admins
        ├─ reserved for someone ─► warn; only the reserving member (or an admin) can borrow
        └─ not lendable ─────────► "Nur vor Ort / on-site only"
```

- **Long-lived sessions** (e.g. 90–180 days), so members don't need a new magic
  link every time they are at the shelf. The magic link should land back on the
  scanned copy.
- **"Found on shelf" handling** matters for an open shelf. People will forget to
  record returns. Let any trusted member close a loan they find on the shelf,
  and record who did it.
- Borrowing takes **one tap after the scan**. Returning takes one tap, which
  closes the loan right away.
- **Completeness check on return (optional):** after the return, a pop-up asks
  "Is the game complete?" with three answers: *Yes*, *Skip*, or *No → report
  missing parts*. Reporting opens a short form (free-text comment: what is
  missing or damaged) and creates an Issue for admins. The return is valid
  whatever the member answers.

### 5.3 Admin desk flow (for new members, corrections)

Admin scans the copy (or searches for it) → picks a member → borrows/returns
for them. The same screen lets admins fix wrong records: change the borrower,
edit dates, cancel a loan.

### 5.4 Loan rules

Expansions count exactly like base games for every rule below.

The numbers below are starting values and will be tuned with experience. They
live in **one central place in the code or config** (e.g. a single settings
file or constants block), so the developer can change them without touching
any logic. **For now only the developer changes them**: there is no admin UI
for them. An admin settings page can come later, if needed. Admins can still
make case-by-case exceptions (§4).

| Setting | Value |
|---|---|
| Loan period | 4 weeks |
| Max renewals per loan | 2 |
| Max concurrent loans per member | 10 |
| Reservation hold after the copy is returned | 1 week |

- **Due date**: loan period of **4 weeks**. Members can renew a loan themselves
  **up to 2 times**, as long as nobody has reserved the game. Renewing is
  possible only in the **last week before the due date** (when the reminder
  email arrives) or later. Each renewal adds 4 weeks to the due date (to today,
  if overdue).
- **Reminders** by email: a week before the due date, on the due date, and
  then weekly while overdue. A weekly digest of overdue loans goes to admins.
- **Max concurrent loans:** **10** per member. Each expansion counts as one game.
- **Reservations / waitlist** per game. When a copy is returned, the next person
  in the queue gets an email, and the copy is held for **1 week**. If they
  haven't borrowed it by then, the reservation expires and the next person is
  notified.
  *Open-shelf problem:* the app can't stop someone else from taking a held copy
  off the shelf. Options: (a) after a return, the app tells the returner
  "reserved for X, please put it on the reservation shelf", (b) the app blocks
  anyone other than X from borrowing it, (c) both. Suggestion: (c).

### 5.5 Member onboarding

There is no machine-readable member list yet, so the app keeps its own minimal
one (name, email, role, language).

- **Invite only.** An admin adds a member (name + email + role), and the member
  gets a welcome email with a magic link. Members cannot register themselves.
- With < 100 members, adding them one by one is fine. A CSV import exists for
  the initial list (§11.3); its members get their welcome email later, with one
  "send to all who have none yet" button.
- A real club member database is a **separate project**. Keep the member model
  small and give it an optional `external_id`, so it can later be filled or
  synced from that database instead of by hand.

### 5.6 BGG sync

1. Fetch the collection with `own=1`, for both `boardgame` (excluding
   expansions) and `boardgameexpansion`. The BGG collection endpoint is
   **asynchronous**: it often answers `202 Accepted` first, so the sync has to
   retry with backoff until the data is ready.
2. Fetch game details via `/thing` in batches (players, playtime, image,
   expansion links).
3. **Diff** against the app DB by `collid`:
   - new collid → create a Copy (lendable by default) and add it to the "needs
     QR sticker" queue,
   - collid gone → mark the Copy retired. If the copy is on loan, flag it for an
     admin instead of retiring it.
   - changed game data → update it.
3. Show the admin a **sync report** ("3 new, 1 removed, 12 updated") before
   applying it, or right after.
4. *(Low priority, not needed yet.)* **Not lendable from BGG:** if a
   collection item's comment contains "nicht zum ausleihen" (ignoring case),
   set the Copy to `lendable = false`. If the phrase disappears, set it back to
   lendable. Today every game can be lent, so this waits until such a game
   actually shows up.

Triggers: a "Sync now" button for admins, plus an automatic weekly sync.
**Fallback:** BGG lets you export the collection as CSV from its website. Accept
a CSV upload, so the app doesn't depend on API access, which may be delayed or
revoked. The app must show the "Powered by BGG" attribution, as BGG's API terms
require. Check the current terms.

## 6. Architecture options

The big open question is **where the app runs**, and the answer depends mostly
on the IONOS plan. The domain model and flows above don't change with it.

### Option A – WordPress plugin on the existing site *(recommended if Managed WordPress allows custom plugins)*

- A custom plugin with its own DB tables (via `$wpdb`) and a REST API. A small
  mobile-first front end (plain JS / Alpine / Preact) is embedded through a
  shortcode on a page like `/ausleihe`, or served from a dedicated route.
- Members become WordPress users with custom roles (`ms_admin`, `ms_trusted`,
  `ms_new`). They never see wp-admin. The plugin handles magic-link login
  (token table + `wp_set_auth_cookie`), or we use an established magic-link
  plugin.
- Admin screens live in wp-admin: members, copies, sync, QR printing, settings.
  Non-developers already know their way around wp-admin.
- Emails go through `wp_mail` and an SMTP plugin (IONOS mailbox or a
  transactional email service).
- **Pros:** no new hosting, account or bill. Same domain and login as the club
  site. Easiest handover to non-developers. The member list and backups sit
  alongside the website.
- **Cons:** PHP/WordPress development. Shares the website's fate: a broken
  plugin or theme update can affect both. WP-Cron runs only when someone visits
  the site, which matters for reminders and syncs (see table below).
  Managed-hosting restrictions apply.

### Option B – Separate app on a subdomain, managed platform

- E.g. `ausleihe.machbarschaft-suedstadt.de` (a CNAME record in IONOS DNS) points to a small
  app on a free or cheap managed platform. One example: Supabase (Postgres +
  built-in magic-link auth + scheduled functions) with a static front end on
  Cloudflare Pages or Netlify. The WordPress site only links to it, through a
  menu item or a button.
- **Pros:** modern stack, magic links and real cron come built in. Fully
  decoupled from the website.
- **Cons:** another vendor and account to hand over (and to keep from being
  lost when people leave). Free-tier limits and policies can change. Some free
  tiers pause inactive projects. Non-developer admins need a custom admin UI.

### Option C – Standalone PHP app on IONOS web hosting

- Only available if the club is on (or moves to) a classic hosting package with
  PHP, MySQL, subdomains and cron. A small Laravel/Slim app on a subdomain,
  with the same DB server.
- **Pros:** one provider, real cron, no WordPress coupling.
- **Cons:** needs the plan change. Custom admin UI. Deployment over SFTP is
  more manual.

### Decisions that hinge on the IONOS plan

| Decision | Managed WordPress | Classic web hosting | Why it matters |
|---|---|---|---|
| Can we run custom code? | Yes, as a **WP plugin** (verify that uploading custom plugins is allowed) | Yes: a plugin **or** a standalone app | Decides between Option A and C. If neither works → B. |
| Own database tables | Through the WordPress DB (`$wpdb`, `dbDelta`) | Separate MySQL DB possible | Plugin tables are fine at this scale. |
| Scheduled jobs (reminders, sync) | WP-Cron only, which runs on visits. Fix: an external free pinger (e.g. cron-job.org) calls `wp-cron.php` every 15 min. Check this works on IONOS. | Real cron jobs are usually available | Overdue reminders must be reliable. |
| Subdomain (`ausleihe.…`) | DNS is possible, but it can point only to WordPress or to an external host (→ B) | A subdomain can host its own app | Affects the URL printed on the QR codes. **Choose it carefully, because stickers are hard to reprint.** |
| Outbound HTTP to BGG | Usually allowed (`wp_remote_get`). Watch the PHP timeouts during long BGG queue waits → run the sync as several short steps | Allowed | The BGG sync has to cope with the 202/retry pattern. |
| Email sending | `wp_mail` + SMTP plugin | PHP mail / SMTP | Magic links fail completely if emails land in spam. Use authenticated SMTP with SPF/DKIM. |
| Backups | IONOS WordPress backups cover the plugin data too | Must be set up separately for a standalone DB | |

**QR URL tip:** whichever option wins, print a URL that **we** control and that
can redirect, e.g. `https://machbarschaft-suedstadt.de/c/<code>`. If the app moves later
(e.g. from A to B), only a redirect changes and the stickers stay valid. Even
if the redirect is lost, the in-app scanner still reads the ID from old
stickers (§5.1).

### Hosting check results (2026-10-25)

Measured on the club site with the `tools/ms-hosting-check` plugin and over SSH.

| Check | Result |
|---|---|
| Custom plugin upload and activation | Works (upload via SFTP/SSH) |
| Runtime | PHP 8.3, WordPress 7.1.3, `memory_limit` 512M, `max_execution_time` 360 s |
| Extensions | curl, openssl, simplexml, dom, mbstring, intl, gd, imagick, zip all loaded (QR codes and BGG XML are covered) |
| Own DB table, custom user role | Works |
| Outbound HTTPS | Works. BGG answers `401` without a token, as expected (§2) |
| REST route, `/c/<code>` pretty URL, `wp-cron.php` loopback | All HTTP 200, so the planned QR URL shape works |
| `wp_mail` | Arrives, not in spam (SPF/DKIM still worth confirming for magic links) |
| WP-CLI | Installed (2.12.0), so deploy and cron can be driven over SSH |
| `crontab` | **Not available**: no real cron jobs on this plan |
| WP-Cron | `DISABLE_WP_CRON` is `true`, so visits do not trigger it. The host triggers it itself, **irregularly**: 15 runs in ~7 h, gaps up to 79 min against a 5 min target |

**Consequences**
- Option A (WordPress plugin) is confirmed.
- Scheduled jobs run on WP-Cron, but the host's own trigger is too irregular for overdue reminders. Add an external pinger (cron-job.org or UptimeRobot, free) that calls `https://machbarschaft-suedstadt.de/wp-cron.php` every 5 to 15 min. A browser visit to that URL gives a blank page, so it works.
- Jobs must be idempotent (a late or double run does no harm) and short (BGG sync in small steps).

### Recommendation (preliminary)

1. Confirm the IONOS plan, and confirm that custom plugins and an external cron
   pinger work.
2. If yes → **Option A** (WordPress plugin). It fits the "non-developers
   maintain it later" constraint best and keeps everything on one platform.
3. If managed hosting blocks custom plugins → choose between **C** (upgrade the
   plan) and **B** (external platform). Prefer C if the club wants a single
   provider.
4. Keep the domain logic (sync diff, loan rules) in a self-contained module
   behind a thin layer for persistence and auth. Then a later move between
   options means porting that layer, not a rewrite.

## 7. Cross-cutting concerns

- **i18n:** German by default, English optional. Each member has a language
  setting that applies to emails. Game names come from BGG and are not
  translated (BGG names are usually English, so consider the alternate German
  name when BGG has one).
- **Privacy (GDPR):** store only name, email, role and loan history. **Only
  admins see who has a game.** Members only see "on loan until 12.11." (and
  their own loans). Anonymize loan history after e.g. 12 months. Add a privacy
  notice and a page that shows a member all their data.
- **Audit trail:** every state change records who did it and when. On an
  honor-system shelf, this is what makes the data trustworthy.
- **Mobile-first, accessible UI.** Most use happens standing at a shelf with a
  phone.
- **Search & browse:** filters for player count, playtime and "available now".
  A public catalog page could even double as a nice feature on the club
  website.

## 8. Phased roadmap (suggestion)

| Phase | Scope |
|---|---|
| 0 – Groundwork | Confirm the IONOS plan. Register a BGG API app. Decide the QR URL. One-off import of the BGG collection into the DB. |
| 1 – MVP | Catalog + copies, admin-invited members with magic links, three roles, scan → borrow/return, admin desk flow, QR sheet printing, manual BGG sync + CSV fallback. |
| 2 – Rules | Due dates, extensions, reminder emails, max loans, overdue digest for admins, automatic weekly sync. |
| 3 – Community | Reservations/waitlist with holds, condition reports / missing parts workflow, "found on shelf" admin notifications. |
| Later | Stats (most popular games, never-borrowed games), suggesting new → trusted upgrades, public catalog widget for the website, photos in condition reports, not-lendable flag from BGG comments (§5.6), connecting to a future member database. |

## 9. Open questions

1. ~~Which IONOS plan, and are custom plugins and cron pinging possible?~~
   Answered, see "Hosting check results" in §6.
2. Which (sub)domain goes into the QR URL? Decide before printing anything. (The hosting check showed `https://machbarschaft-suedstadt.de/c/<code>` works, so this is likely settled. Confirm before printing.)
3. Which email address/domain sends the magic links? Are SPF/DKIM set up for it?

## 10. Decisions log

| # | Topic | Decision |
|---|---|---|
| D1 | Sticker format | Hybrid: a QR code with a URL ending in the copy ID, plus the ID printed in plain text. The in-app scanner ignores the domain. |
| D2 | Expansions | Every listed expansion has its own box, Copy and QR code, and follows the same rules as base games. |
| D3 | Borrower visibility | Only admins see who has a game. |
| D4 | Member onboarding | Admin invite only, no self-registration. |
| D5 | Loan rules | 4-week loan period, up to 2 renewals (only in the last week before the due date), max 10 concurrent loans, reservation held for 1 week after the copy is returned. |
| D6 | Not lendable | All games can be lent for now. Later (low priority): a BGG comment containing "nicht zum ausleihen" marks a copy as not lendable. |
| D7 | Completeness check | Optional. A pop-up after the return asks whether the game is complete, with a link to report missing parts plus a comment. |
| D8 | Member data | No machine-readable list exists. The app keeps a minimal list. A full member database is a separate project. |
| D9 | Rule settings | The loan rule values (D5) are kept in one central place, so the developer can change them easily. No admin UI for them for now. |
| D10 | Held copies on the open shelf | Option (c) from §5.4: the returner is told "reserved for X, please put it on the reservation shelf", and the app blocks everyone except X (or an admin borrowing for X). |
| D11 | Email times | Emails from the background job (reminders, hold expiry, admin summary) go out only between 8:00 and 20:00 local time. Emails caused by someone's action (sign-in link, "held for you" after a return) go out immediately. |

## 11. Implementation status (updated 2026-10-29)

**Read this first in a new session.** Sections 1–10 are the design. This section
says what is built, how to run it, and what was learned along the way.

### 11.1 Repo layout

| Path | What |
|---|---|
| `docs/design.md` | This document |
| `plugin/mascha-ausleihe/` | The WordPress plugin (Option A). Main file `mascha-ausleihe.php`, classes in `includes/class-*.php`, prefix `MAUS_` |
| `tools/deploy.sh` | Deploys the plugin to the live site over SSH (§11.2) |
| `tools/ms-hosting-check/` | Throwaway diagnostics plugin. **Already used, deactivated and removed from the live site.** Keep only as a record |

### 11.2 Local development

- **LocalWP** site `maschaspielt` in `~/Code/Local Sites/maschaspielt` (WP root `app/public`). Created with Apache (the live site is Apache) and MySQL 8.0 (Local offered no MariaDB; the live DB is MariaDB 10.11, so use only portable SQL through `$wpdb`/`dbDelta`) and PHP 8.3. Single site, not multisite.
- The plugin folder is **symlinked** into `app/public/wp-content/plugins/mascha-ausleihe`, so edits apply immediately.
- Admin pages: `http://maschaspielt.local/wp-admin/admin.php?page=maus` (overview), `maus-catalog`, `maus-import`, `maus-members`. Member pages: `http://maschaspielt.local/ausleihe` and `http://maschaspielt.local/c/<code>`. There is no separate subdomain.
- Not installed on the dev Mac: Node, Docker, WP-CLI, a MySQL client. PHP 8.x and Composer are present. Use Local's shell or its Database tab (Adminer) for DB access and `wp` commands. Plain PHP unit checks run with `php`, but anything touching `$wpdb` can only be tested inside Local.
- Deploy to the live site: run `tools/deploy.sh` (asks for the SSH password from the IONOS control panel). It streams the plugin over SSH with `tar` (scp/SFTP cannot see `/home/www/public` on this host), swaps the folder in one step, activates the plugin with WP-CLI and flushes the rewrite rules. Running it again updates the plugin and keeps the data. The WordPress root on the server is `/home/www/public`. If `wp` is not found in the SSH session, activate the plugin under **Plugins** in wp-admin instead.

### 11.3 Built so far

| Area | State |
|---|---|
| Settings (`class-settings.php`) | Loan rules from §5.4/D5 in one constant array (D9). Added values: reminder 7 days before due (renewing opens at the same time), overdue reminders every 7 days, welcome link valid 7 days (`welcome_link_days`), background emails only 8:00–20:00 (`mail_from_hour`, `mail_until_hour`, D11) |
| Schema (`class-db.php`) | Tables `wp_maus_games`, `game_links`, `copies`, `members`, `loans`, `reservations`, `issues`, created by `dbDelta`, versioned through the option `maus_db_version` (bump `VERSION` to migrate). Datetimes are UTC. Only games and copies hold data so far; the rest are empty |
| Copy IDs (`class-copy-id.php`) | 4 random Crockford-Base32 characters + 1 check character (e.g. `K7Q2X`). Data characters use only the first 31 symbols and the check is a weighted sum mod 31, which detects every single-character error and neighbour swap (verified over ~476k cases). `parse()` accepts a bare ID or a full URL (domain ignored), and maps O→0, I/L→1 |
| CSV import (`class-csv-import.php`, admin page "CSV import") | BGG collection export → preview report → Apply. Identifies copies by `collid`. New copy gets a QR code. Duplicates get labels `#1`, `#2`. Copies missing from a later CSV are retired, except when on loan (flagged instead). Refuses an empty file. Re-importing the same file changes nothing (verified). Runs in one transaction |
| Admin (`class-admin.php`) | Overview with counts, read-only **Catalog** list (filters: expansions, duplicates, retired; search by name or code), CSV import |
| Stickers (`class-stickers.php`, admin page "Stickers") | Printable A4 sheets of 60 × 30 mm labels (3 × 9 = 27 per sheet) for plain inkjet sticker paper, cut by hand along light hairlines. QR (error correction M, ~22 mm) on the left, ID / copy label / game name on the right. QR codes are drawn in the browser with the bundled MIT-licensed `assets/qrcode.js` (qrcode-generator 1.4.4), so the server needs no library. The URL base is `qr_base_url` in `MAUS_Settings` (the live domain, never the local one). Options: only unprinted copies or all, skip N cells to reuse a partly used sheet. After printing, "Mark as printed" sets `sticker_printed_at`. Geometry constants are at the top of the class. **Untested in a browser and on paper yet** |
| Sticker reprint | **Ausleihe → Catalog**: tick copies (also retired ones) and press "Print selected stickers". Same printable page, optional "skip N labels" to fill gaps on a partly used sheet |
| Members (`class-members.php`, admin page "Members") | Admin page with list and CSV import (columns `name, email, role, language, external_id`; comma or semicolon; matched by lowercase email; preview then apply; only adds/updates, never removes or deactivates; sends no mail). The WP admin list also shows when each member was welcomed and links to the member pages below |
| Member management (`class-front-members.php`, `/ausleihe/members`, admin members only, linked from their home page) | On the member pages, not WP admin, because lending-team volunteers have no WordPress login. **List** as a full-width table (name with email and "inactive"/"not welcomed yet" flags, role with language, open-loan count; inactive members last and dimmed). The whole page scrolls (no inner scroll box, which is fiddly on phones); a bar pinned to the top of the screen holds the search box (filters while typing, substring of name or email), quick filters **All / Not welcomed / With loans** with counts, and "shown / total". Filtering is client-side. Tested on Local, also at phone width (2026-10-27). **Add** (name, email, role, language; "send welcome email now" ticked by default) and **edit** (`/ausleihe/members/<id>`), with the member's open loans. Email must be unique; an admin cannot remove their own admin role or deactivate themselves, so the club keeps at least one admin. **Deactivate** keeps the member and the history, ends their sessions and unused sign-in links (`MAUS_Auth::revoke`); confirm dialog warns about open loans. **Reactivate** is one button. **Welcome email** (§5.5): a sign-in link valid 7 days, single use like any login link, plus how scanning works; `members.welcomed_at` (DB version 4) records when it went out. Send or resend per member, and **"Send to all N"** for active members who never got one (e.g. after the CSV import). Tested on Local (2026-10-27) |
| Login (`class-auth.php`, `class-front.php`, `class-mail.php`, `class-i18n.php`) | Magic link, no passwords. Pages: `/ausleihe` (home: own loans, for admins a link to open reports), `/ausleihe/login`, `/ausleihe/login/confirm`, `/ausleihe/logout`, rendered as standalone mobile pages (not the WP theme) with a footer fixed to the bottom of the screen holding "Zur Übersicht" (except on the overview and the sign-in pages) and page-specific back links such as "Zur Mitgliederliste", German/English by member language or browser. Link: random 256-bit token, only the SHA-256 hash is stored (`wp_maus_login_tokens`), single use, valid 15 min. Opening the link only shows a confirm button; signing in is a POST, so mail scanners cannot use it up. Session: random cookie token, hash in `wp_maus_sessions`, 30 days, HttpOnly, SameSite=Lax, Secure on HTTPS. Same response for unknown/inactive/valid addresses (no account enumeration). Rate limit: 3 links per address and 10 per IP per 15 min (transients). Logout and later state-changing forms use `MAUS_Auth::csrf_token()`. Tested on Local (2026-10-26) |
| Copy page (`/c/<code>`, `MAUS_Front::copy_page`, `class-copies.php`) | The sticker URL. Invalid code (checksum) → "code not valid" page. Lower case or O/I/L → redirect to the canonical code. Not signed in → login with `next=/c/<code>`; the `next` path travels through the login form and the emailed link and is restricted to `/c/<code>` or `/ausleihe` (no open redirect). Shows game name + copy label, expansion/players/playtime/year, image if set, and one status: retired ("no longer in the collection"), on loan to me (due date, overdue warning), on loan to someone (borrower name and due date for admins only, D3), not lendable ("on-site only"), or available (with "ask the lending team" for new members). Buttons per status, see next row. Also shows reservation holds and the Reserve button (see Reservations below). Tested on Local (2026-10-26), before reservations |
| Borrow / return (`class-loans.php`, buttons on `/c/<code>`) | Trusted members and admins only; new members see "ask the lending team" (§4). **Borrow**: one tap, checks lendable and the 10-loan limit, locks the copy row (`SELECT … FOR UPDATE` in a transaction) so two simultaneous taps cannot both borrow; due in 28 days. **Renew**: own loan, up to 2 times, only from 7 days before the due date (`reminder_days_before_due`; earlier the page says from which date), new due date = old due date + 28 days (today + 28 if overdue). Renewal window tested on Local (2026-10-27). **Return**: own loan, one tap. **Found on shelf**: any trusted member or admin can close someone else's loan (with a JS confirm); `returned_by` records who, and all active admins get an email. Forms post the loan id, and the action only applies if that is still the open loan, so a stale page cannot close a newer loan. **Completeness (D7)**: after a return, "Is the game complete?" → Yes sets `loans.complete = 1`, No opens the report form and sets `complete = 0`, Skip leaves it `NULL`; open for the returner for 1 hour. **Report a problem**: link on every copy page, creates an `issues` row and emails the admins. Pages use POST → redirect → GET with `done=`/`err=` messages; every form carries `MAUS_Auth::csrf_token()`. `/ausleihe` lists the member's open loans with due dates. Tested on Local (2026-10-26); the reservation checks were added later |
| Reservations (`class-reservations.php`, on `/c/<code>`, DB version 5) | Per game, any copy (§5.4). Statuses `waiting` → `held` → `fulfilled`, or `expired` / `cancelled` (`closed_at` records when). **Reserve** button only when no copy of the game can be borrowed right now (all on loan, held or not lendable) and the member is not already borrowing or reserving it; new members can reserve too (§4). Members see their place in the queue on the copy page and on `/ausleihe` and can cancel. **When a copy becomes free** (return, "found on shelf", admin return, cancelled loan, expired or cancelled hold) it is held for the first in the queue for 7 days (`reservation_hold_days`) and they get an email immediately (different text for new members: ask the lending team). The returner sees "reserved for X, please put it on the reservation shelf" (D10). **A held copy** can only be borrowed by that member, or by an admin for them ("Borrow for X"); others see "held for another member, please do not take it". If the member borrows a different copy of the game, the reservation is fulfilled and the held copy moves on. **Renewing is blocked** while anyone waits for the game. Admins see the queue on the copy page and can remove entries or cancel a hold; **Ausleihe → Reservations** in WP admin lists all open ones read-only. Tested on Local (2026-10-27) |
| Background job (`class-jobs.php`, DB version 5) | WP-Cron event `maus_jobs`, hourly; on the live site it only runs when cron-job.org calls `wp-cron.php` (§11.5). Each run: expire holds that ran out (copy moves to the next in line, member gets an email), then, only between 8:00 and 20:00 (D11), **reminders**: 7 days before the due date (with the renew link), on the due date (also sent as the first overdue mail if runs were missed), then every 7 days while overdue; and on Mondays the **overdue summary** to all admins (once per week, `maus_digest_week` option). Every email is claimed in the database (`loans.reminded_before_at`, `reminded_due_at`, `reminded_overdue_at`) before sending, and a lock option prevents parallel runs, so late, missing or doubled runs send nothing twice. Renewing or correcting a loan resets its reminders. WP admin overview shows the last and next run (warns if the last one is over 3 hours ago) and has a **Run now** button for testing, which ignores the email hours. Tested on Local with Run now (2026-10-27): hold expiry and all three reminder kinds |
| Desk flow (§5.3, on `/c/<code>` for members with role `admin`) | **Borrow for a member**: pick any active member (also `new`) from a list that shows each member's open-loan count; the loan limit does not apply (admins override, §4); `checked_out_by` is the admin. **Record return** of anyone's loan without the admin email that "found on shelf" sends. **Correct loan** (`?edit=1`): change borrower and due date (local date, due at 23:59 that day), or **cancel** a loan recorded by mistake, which deletes the row so it does not count as history. Tested on Local (2026-10-27) |
| Loans and issues in WP admin (**Ausleihe → Loans / Issues**) | Loans: open, overdue or latest returned, with renewals, who returned it (if not the borrower) and the completeness answer; changes happen on the copy page (code links open it). Issues: open or resolved (with date and resolving member), read-only. **Resolving** happens on the member pages, so `resolved_by` is always the signed-in admin member: `/ausleihe/issues` (all open reports, linked from the admin's home page with the open count) and the copy page (that copy's open reports), both with an **Erledigt … / Resolve …** button (`class-issues.php`). The button opens a dialog (`<dialog>`) that repeats the report and asks "What was done?" (required, stored in `issues.resolution_note`, DB version 3), with separate **Mark as resolved** and **Cancel** buttons so a single mis-tap cannot resolve a report. WP admin shows the note under Resolved. The overview shows open/overdue loans and open issues. Tested on Local (Issues 2026-10-26, Loans 2026-10-27) |
| Trial mode and feedback (`trial_mode` in `class-settings.php`, `class-feedback.php`, DB version 6) | See §11.9. Banner on every member page, "[Test]" plus a note in every email (all mail goes through `MAUS_Mail::send`). **Feedback geben** in the footer of every signed-in page opens `/ausleihe/feedback` with the page the member came from; entries are stored (`wp_maus_feedback`, with a copy of the member's name), emailed to the admins and listed in **Ausleihe → Feedback** with a CSV download (semicolons, UTF-8 BOM for Excel). The feedback link stays after the trial. Tested on Local (2026-10-29) |
| Reset (**Ausleihe → Reset**, `MAUS_DB::reset_usage_data`) | Deletes loans, reservations, issues, members, sessions and sign-in links in one transaction, optionally the feedback too. Keeps games, copies, codes and sticker print dates, so printed stickers keep working, plus WordPress users and settings. Shows the row counts and needs `RESET` typed in. Tested on Local (2026-10-29) |
| First real import | 537 active copies from the club's BGG collection |

**Not built yet:** the in-app scanner, BGG API sync, German/English
UI texts (admin strings are English for now), GDPR notice and retention.

### 11.4 BGG CSV export format (verified with the real file)

Columns include `objectname`, `objectid`, `collid`, `own`, `itemtype`,
`objecttype`, `yearpublished`, `minplayers`, `maxplayers`, `playingtime`,
`avgweight` (community weight; `weight` is the owner's own rating and is not used),
`comment`, `invlocation` and about 40 more.

- `itemtype` is `standalone` or `expansion`. That is how expansions are recognised.
- The export has no image URL (only `imageid`) and no expansion→base links. Both have to come from the API `/thing` call later (fills `image_url` and `game_links`).
- `comment` is where the future "nicht zum ausleihen" marker (§5.6 item 5) would be read. `invlocation` could fill the copy's `location`. Neither is imported yet.

### 11.5 Findings and constraints for the next steps

- **BGG API token:** applied for, still no answer as of 2026-10-27. Until it arrives, the catalog is kept up to date with the manual **CSV import** (BGG website → collection → export as CSV → **Ausleihe → CSV import**). An anonymous request gives `401`. Build the API sync against saved sample responses until it arrives. The CSV import remains the fallback.
- **Cron:** `crontab` does not exist on the host, and `DISABLE_WP_CRON` is `true`. A **cron-job.org** job calls `https://machbarschaft-suedstadt.de/wp-cron.php?doing_wp_cron` every 15 minutes (GET, notify on failure); set up 2026-10-27, first run answered 200. **TODO after deploying the plugin:** check **Ausleihe → overview → Background job → Last run** a few hours later. The plugin's job is hourly and tolerates late or double runs (§11.3).
- **Mail:** `wp_mail` delivered the test mail and it was not spam. Still to verify: SPF/DKIM for the sending address before relying on magic links.
- **Host limits are generous:** `memory_limit` 512M, `max_execution_time` 360 s, outbound HTTPS works, pretty URLs (`/c/<code>`) and REST routes work. Extensions gd, imagick, zip, intl, curl, simplexml, dom are loaded, so QR images can be generated server-side.
- **Requirements:** plugin header says PHP 8.0+ (code uses `str_contains`); the host runs 8.3.
- **WordPress 7.1.3** is installed on the host.

### 11.6 Suggested next steps

1. Test the sticker page: print one sheet on plain paper (Canon G550, A4, 100% scale, no margins), cut out a few labels and scan them with several phones. Adjust size/font in `class-stickers.php`. Stickers encode the live domain, so on Local open `http://maschaspielt.local/c/<code>` by hand. Use black-only printing (pigment black ink is the water-resistant one on this printer) and consider clear laminate or overlay film for wear.
2. BGG API sync once the token arrives (CSV import until then). Add `invlocation` and `comment` handling to the import if wanted.
3. Trial on the live site (§11.9).

### 11.7 Copy IDs: capacity and lifecycle

- 4 data characters from 31 symbols = 31⁴ = **923,521** possible IDs (the 5th character is the check character). The collection has ~537 copies, so collisions are negligible; generation retries on a clash anyway.
- IDs are **never reused**. The uniqueness check covers retired copies too, so a code on an old sticker can never point at a different game. A copy removed from BGG is retired and keeps its code and loan history. If the same `collid` returns in a later import, the copy is reactivated with the **same** code (old sticker works again). If a game is removed and later re-added in BGG it gets a new `collid`, hence a new copy and a new code.
- A sticker for a retired copy should show "no longer in the collection" on the future `/c/<code>` page rather than a 404.

### 11.8 Sample data

`docs/sample-data/members-sample.csv`: 12 fictional members (2 admins, 5 trusted, 5 new; German and English; umlauts). `members-sample-semicolon-with-errors.csv`: semicolon-separated, exercises the update path (Anna's email in capitals and role change) and the error report (bad email, unknown role, unknown language, duplicate, missing name). All addresses are `@example.com`; to receive real test mail, edit one row.

### 11.9 Trial phase and reset (from 2026-10-29)

Goal: club members try the app on the live site and give feedback, **without** the app tracking real loans yet. Afterwards all usage data is deleted and the app starts fresh.

1. **Deploy** with `tools/deploy.sh` (§11.2), `trial_mode` = `true`.
2. **Catalog:** WP admin → **Ausleihe → CSV import** with the BGG export.
3. **Members:** WP admin → **Ausleihe → Members** → CSV import of the testers. Include yourself with role `admin`.
4. **Check email delivery first:** request a sign-in link for yourself at `/ausleihe/login` and, in Gmail, open the mail → ⋮ → **Show original**: `SPF: PASS` and `DKIM: PASS` should be listed (§11.5). If not, fix that before inviting anyone, or the welcome mails may land in spam.
5. **Invite:** sign in, open `/ausleihe/members` → **Send to all N**.
6. **Background job:** WP admin → Ausleihe overview → **Last run** should update hourly (cron-job.org pinger, §11.5).
7. **During the trial** testers borrow, return, reserve and report with any game; nothing is physically tracked. Reminder emails really go out (marked [Test]), so test loans should be returned or cancelled. Feedback arrives by email and under **Ausleihe → Feedback**.
8. **After the trial:** download the feedback CSV, make a database backup in the IONOS control panel, then **Ausleihe → Reset** (type `RESET`). Then set `trial_mode` to `false`, deploy again, re-import the real member list and send the welcome emails.
