# Parking PHP/MySQL version

## Files

- `parking.php` — the page and PHP JSON endpoints.
- `schema.sql` — database and table setup.
- `config.example.php` — copy to `config.php` and enter the MySQL credentials.

## Setup

1. Create a MySQL database/user, or use the database creation statements in `schema.sql` if your host permits them.
2. Import `schema.sql` in phpMyAdmin or the MySQL command line.
3. Copy `config.example.php` to `config.php` in the same directory as `parking.php`.
4. Add the database credentials to `config.php`.
5. Upload `parking.php`, `config.php`, and the schema-related files to PHP hosting.
6. Ensure PHP has the `PDO_MySQL` extension enabled.
7. Keep `config.php` outside public access if the host supports it. Otherwise, deny direct access to it with the host’s rules.

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
