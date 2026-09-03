# Parking PHP/MySQL version

## Files

- `parking.php` — the page and PHP JSON endpoints.
- `schema.sql` — database and table setup.
- `config.example.php` — copy to `config.php` and enter the MySQL credentials.
- `Dockerfile` / `render.yaml` — hosted deployment (see *Deploying*).

## Setup

1. Create a MySQL database/user, or use the database creation statements in `schema.sql` if your host permits them.
2. Import `schema.sql` in phpMyAdmin or the MySQL command line.
3. Copy `config.example.php` to `config.php` in the same directory as `parking.php`.
4. Add the database credentials to `config.php`.
5. Upload `parking.php`, `config.php`, and the schema-related files to PHP hosting.
6. Ensure PHP has the `PDO_MySQL` extension enabled.
7. Keep `config.php` outside public access if the host supports it. Otherwise, deny direct access to it with the host’s rules.

Instead of `config.php`, the same settings can come from the environment, which is what a
hosted deploy uses: `MYSQL_HOST`, `MYSQL_PORT`, `MYSQL_DATABASE`, `MYSQL_USER`,
`MYSQL_PASSWORD`, `MYSQL_SSL_CA`, `MYSQL_SSL_VERIFY`. Anything set there overrides
`config.php`, so no credential has to live in the repository, and `config.php` is in
`.gitignore`.

`MYSQL_SSL_CA` accepts a path to a CA file, the word `system` for the machine's own CA
bundle, or the certificate text itself — a PaaS dashboard has nowhere to put a file.

## Deploying to Render

Render's free tier has no MySQL, so the database comes from a provider that does
(Aiven, TiDB Cloud and Clever Cloud all offer a free MySQL) and Render runs only the app.

1. Create the free MySQL and note its host, port, database, user and password.
2. Import `schema.sql` into it.
3. In Render: **New → Web Service**, connect this repository, choose the **Docker** runtime
   and the **Free** plan. `render.yaml` describes the service if you use a Blueprint instead.
4. Set `MYSQL_HOST`, `MYSQL_PORT`, `MYSQL_DATABASE`, `MYSQL_USER` and `MYSQL_PASSWORD` in the
   service's environment, plus `MYSQL_SSL_CA=system` (hosted MySQL requires TLS). If the
   provider's certificate does not validate, paste its `ca.pem` contents into `MYSQL_SSL_CA`,
   or set `MYSQL_SSL_VERIFY=false` as a last resort.
5. Deploy. The image serves `parking.php` as the site root on the port Render assigns.

Two things worth knowing about the free plan: the service sleeps after inactivity, so the
first request after a quiet spell takes a few seconds, and there is no persistent disk —
which does not matter here, because all state lives in the external database.

### Access code

The app has no per-person login by design, so on a public URL any visitor could book and
cancel for the team. Set `PARKING_ACCESS_CODE` to a shared code and the page asks for it
once per browser session before anything is reachable, including the JSON endpoints. Leave
the variable unset and the page behaves exactly as it always has, completely open.

## Stored data

The database persists monthly plans, weekly registrations, automatic weekly allocations, annual usage history, and fob handover/return/lost/damaged records.

The page intentionally has no login: the five eligible colleagues can select a name, plan the next month, and cancel any existing plan. The server still validates the allowed names, the next-month-only rule, the monthly limit of two weeks per person, the Thursday–Friday registration window, the two-space allocation, and the annual limit.

## Two weeks per person per month

Every member can hold at most two weeks in any one month (`PARKING_MONTHLY_MAX`). The quota is shared by both booking routes: a week taken through the Thursday–Friday registration counts against it exactly like a planned week, so the two routes cannot be combined to obtain a third week. A week booked in both tables counts once.

A Monday–Friday week belongs to the month that holds its Wednesday. A week straddling two months therefore counts towards exactly one quota, and the planner shows it in that same month. `parking_month_weeks()` in PHP and `monthWeeks()` in the browser implement the identical rule, so the interface and the server always agree on which weeks a month contains.

Cancelling a plan or removing a name from the weekly registration frees the quota again immediately.

## Two spaces per week, first come first served

The garage has two spaces, so a week accepts at most two bookings in total, and they go to whoever books first. The limit is enforced on the server for both booking routes:

- The monthly planner refuses a week that already has two planners. The grid shows the occupancy per week (`0/2`, `1/2`, `2/2`) and locks a full week with a `Full` label.
- The Thursday–Friday weekly registration counts monthly plans for the same week first, then offers only the spaces left over. Someone who holds the week through a monthly plan keeps it and cannot be removed from the weekly form.
- Both routes take a MySQL named lock on the week, so two people saving at the same moment cannot both claim the last space.

The booking time decides the queue: `monthly_plans.created_at` and `weekly_registrations.registered_at` are merged into one ordered candidate list per week. Editing the weekly registration only inserts and deletes the names that actually changed, so an existing registration keeps its original timestamp and its place in the queue.

A cancelled plan releases the space immediately and the week reopens.

The date handling uses Europe/Luxembourg and normalizes older browser date serialization, so planning is not blocked by the Monday week-start validation. The eligible member is named `Jil` with one L. If an earlier database contains `Jill`, the current schema includes a migration update to rename the member while retaining related records.

The PHP file uses Europe/Luxembourg time for registration timing and generates the Luxembourg holiday display in the browser as before.

## Automatic weekly update

- Monthly plans automatically become candidates for their matching week; people do not need to register twice.
- Late weekly registrations can still be added from Thursday at 09:00 until Friday at 12:00, for whichever of the two spaces are still free.
- The final two-space allocation is created automatically from Friday at 12:00 onward, in booking order: the two members who claimed the week first receive the spaces, with the name as a deterministic tie-breaker for bookings saved in the same second. Someone at the annual limit is skipped and the space passes to the next in the queue.
- The page settles the running week as well as the coming one, so a week whose Friday 12:00 cutoff passed without anyone opening the page is still allocated on the next visit.
- The page refreshes its database state every 60 seconds and when the browser tab becomes active again.
- At a new week or month, the server calculates the new dates automatically. No weekly database reset or manual maintenance is required.
- Server-side rules remain authoritative even if a browser stays open across the cutoff. The “Preview open window” demo button is a dry run: it validates and reports the outcome without writing, so it cannot register anyone outside the Thursday–Friday window.

## Notes for the next person

Some things in here are deliberate, and some are simply not finished.

- **No login.** Anyone who can open the page can book or cancel as any of the five names.
  That is the original design; `PARKING_ACCESS_CODE` gates the page as a whole, not the
  individual names. The `fob_update` endpoint is unauthenticated in the same way and accepts
  any Monday and any slot.
- **The fob log is dead weight.** `fob_log`, the `fob_update` endpoint and the `lostFobFee`
  value in the state are all wired up server-side, but nothing in the page ever reads or
  writes them. The €100 fee shown on the page is fixed text.
- **`parking_holidays()` is unused.** The holiday list is generated in the browser. The PHP
  version needs `ext-calendar` for `easter_date()`, which is why nothing calls it.
- **The two-space capacity spans two tables**, so no unique key can enforce it. Both booking
  routes take a MySQL named lock on the week instead, and the automatic allocation takes a
  second, separate lock so two page loads cannot both fill slot 1. `GET_LOCK` needs MySQL
  5.7 or newer for the nested case to behave.
- **The browser no longer computes dates.** Which week is next and which month is open both
  come from the server, in Europe/Luxembourg. Only the clock and the holiday list still use
  the browser's clock, and both convert through `Intl` with an explicit time zone.
