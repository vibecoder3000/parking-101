<?php
declare(strict_types=1);
session_start();
date_default_timezone_set('Europe/Luxembourg');

const PARKING_MEMBERS = ['Nadia', 'Laurence', 'Lara', 'Jil', 'Erik'];
const PARKING_START_YEAR = 2026;
const PARKING_END_YEAR = 2030;
const PARKING_ANNUAL_MAX = 21;
const PARKING_MONTHLY_MAX = 2;
const PARKING_SPACES = 2;
const PARKING_LOST_FOB_FEE = 100;

function parking_json(mixed $value): string {
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function parking_db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $configFile = __DIR__ . '/config.php';
    if (!is_file($configFile)) {
        http_response_code(500);
        exit('Missing config.php. Copy config.example.php to config.php and add the MySQL credentials.');
    }
    $config = require $configFile;
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['database']);
    $pdo = new PDO($dsn, $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function parking_csrf(): string {
    if (empty($_SESSION['parking_csrf'])) $_SESSION['parking_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['parking_csrf'];
}

function parking_fail(string $message, int $status = 400): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo parking_json(['ok' => false, 'error' => $message]);
    exit;
}

function parking_ok(array $payload = []): never {
    header('Content-Type: application/json; charset=utf-8');
    echo parking_json(['ok' => true] + $payload);
    exit;
}

function parking_member(string $name): void {
    if (!in_array($name, PARKING_MEMBERS, true)) parking_fail('Unknown team member.');
}

function parking_monday(DateTimeImmutable $date): DateTimeImmutable {
    // Wednesday resolves to the coming Monday; Monday resolves to the following Monday.
    $day = (int)$date->format('N');
    $daysUntilNextMonday = $day === 1 ? 7 : 8 - $day;
    return $date->setTime(0, 0)->modify("+$daysUntilNextMonday days");
}

function parking_next_month(): array {
    $first = new DateTimeImmutable('first day of next month', new DateTimeZone('Europe/Luxembourg'));
    return ['year' => (int)$first->format('Y'), 'month' => (int)$first->format('n'), 'value' => $first->format('Y-m')];
}

// A Monday–Friday week belongs to the month holding its Wednesday, so every week counts
// towards exactly one monthly quota even when it straddles two months.
function parking_month_weeks(int $year, int $month): array {
    $tz = new DateTimeZone('Europe/Luxembourg');
    $first = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), $tz);
    $cursor = $first->modify('monday this week')->modify('-7 days');
    $weeks = [];
    for ($i = 0; $i < 8; $i++, $cursor = $cursor->modify('+7 days')) {
        $wednesday = $cursor->modify('+2 days');
        if ((int)$wednesday->format('Y') === $year && (int)$wednesday->format('n') === $month) {
            $weeks[] = $cursor->format('Y-m-d');
        }
    }
    return $weeks;
}

// week_start is always a Monday, so "Wednesday inside the month" is the same as
// "Monday inside the month shifted two days back". That keeps the quota a plain SQL range.
function parking_month_bounds(int $year, int $month): array {
    $tz = new DateTimeZone('Europe/Luxembourg');
    $first = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), $tz);
    return [$first->modify('-2 days')->format('Y-m-d'), $first->modify('last day of this month')->modify('-2 days')->format('Y-m-d')];
}

function parking_week_month(string $weekStart): array {
    $wednesday = (new DateTimeImmutable($weekStart, new DateTimeZone('Europe/Luxembourg')))->modify('+2 days');
    return ['year' => (int)$wednesday->format('Y'), 'month' => (int)$wednesday->format('n'), 'value' => $wednesday->format('Y-m')];
}

// The weeks a member already holds in one month, from monthly plans and weekly
// registrations alike. UNION collapses a week that exists in both tables into one booking.
function parking_member_month_weeks(PDO $pdo, string $member, int $year, int $month): array {
    [$from, $to] = parking_month_bounds($year, $month);
    $stmt = $pdo->prepare('SELECT week_start FROM monthly_plans WHERE member_name = ? AND week_start BETWEEN ? AND ? UNION SELECT week_start FROM weekly_registrations WHERE member_name = ? AND week_start BETWEEN ? AND ?');
    $stmt->execute([$member, $from, $to, $member, $from, $to]);
    return array_column($stmt->fetchAll(), 'week_start');
}

// Same count for everyone, so the interface can grey out members who used both monthly slots.
function parking_month_usage(PDO $pdo, int $year, int $month): array {
    [$from, $to] = parking_month_bounds($year, $month);
    $stmt = $pdo->prepare('SELECT member_name, COUNT(*) AS total FROM (SELECT member_name, week_start FROM monthly_plans WHERE week_start BETWEEN ? AND ? UNION SELECT member_name, week_start FROM weekly_registrations WHERE week_start BETWEEN ? AND ?) AS booked GROUP BY member_name');
    $stmt->execute([$from, $to, $from, $to]);
    $usage = array_fill_keys(PARKING_MEMBERS, 0);
    foreach ($stmt as $row) $usage[$row['member_name']] = (int)$row['total'];
    return $usage;
}

function parking_easter(int $year): DateTimeImmutable {
    $date = easter_date($year);
    return (new DateTimeImmutable('@' . $date))->setTimezone(new DateTimeZone('Europe/Luxembourg'))->setTime(0, 0);
}

function parking_holidays(int $year): array {
    $easter = parking_easter($year);
    $add = fn(int $days): string => $easter->modify("+$days days")->format('Y-m-d');
    return [
        ['date' => "$year-01-01", 'name' => 'New Year’s Day'],
        ['date' => "$year-05-01", 'name' => 'Labour Day'],
        ['date' => "$year-05-09", 'name' => 'Europe Day'],
        ['date' => $add(1), 'name' => 'Easter Monday'],
        ['date' => $add(39), 'name' => 'Ascension Day'],
        ['date' => $add(50), 'name' => 'Whit Monday'],
        ['date' => "$year-06-23", 'name' => 'National Day'],
        ['date' => "$year-08-15", 'name' => 'Assumption Day'],
        ['date' => "$year-11-01", 'name' => 'All Saints’ Day'],
        ['date' => "$year-12-25", 'name' => 'Christmas Day'],
        ['date' => "$year-12-26", 'name' => 'St Stephen’s Day'],
    ];
}

function parking_after_cutoff(): bool {
    $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Luxembourg'));
    if ((int)$now->format('N') === 4) return (int)$now->format('H') >= 9;
    if ((int)$now->format('N') === 5) return (int)$now->format('H') >= 12;
    return in_array((int)$now->format('N'), [6, 7], true);
}

function parking_registration_open(): bool {
    $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Luxembourg'));
    if ((int)$now->format('N') === 4) return ((int)$now->format('H') * 60 + (int)$now->format('i')) >= 540;
    if ((int)$now->format('N') === 5) return ((int)$now->format('H') * 60 + (int)$now->format('i')) < 720;
    return false;
}

function parking_usage(PDO $pdo, int $year): array {
    $start = sprintf('%04d-01-01', $year);
    $end = sprintf('%04d-12-31', $year);
    $stmt = $pdo->prepare('SELECT member_name, COUNT(*) AS total FROM weekly_allocations WHERE week_start BETWEEN ? AND ? GROUP BY member_name');
    $stmt->execute([$start, $end]);
    $usage = array_fill_keys(PARKING_MEMBERS, 0);
    foreach ($stmt as $row) $usage[$row['member_name']] = (int)$row['total'];
    return $usage;
}

// Everyone competing for a space in one week: monthly plans plus late weekly registrations.
// The two-space capacity is counted against this union, never against one table alone.
// Returned oldest booking first: the order is the first-come, first-served queue.
function parking_week_candidates(PDO $pdo, string $weekStart): array {
    $stmt = $pdo->prepare('SELECT member_name, MIN(booked_at) AS booked_at FROM (SELECT member_name, registered_at AS booked_at FROM weekly_registrations WHERE week_start = ? UNION ALL SELECT member_name, created_at AS booked_at FROM monthly_plans WHERE week_start = ?) AS candidates GROUP BY member_name ORDER BY booked_at, member_name');
    $stmt->execute([$weekStart, $weekStart]);
    return array_column($stmt->fetchAll(), 'member_name');
}

// Members holding one of the week's spaces through a monthly plan. They keep the space
// and cannot be removed by someone else's weekly registration.
function parking_week_planned(PDO $pdo, string $weekStart): array {
    $stmt = $pdo->prepare('SELECT member_name FROM monthly_plans WHERE week_start = ? ORDER BY member_name');
    $stmt->execute([$weekStart]);
    return array_column($stmt->fetchAll(), 'member_name');
}

// The capacity spans two tables, so no single unique key can enforce it. A named lock
// makes the count-then-insert atomic when several people save at the same moment.
// MySQL frees the lock when the connection closes, which also covers the exit() inside
// parking_ok() and parking_fail().
function parking_lock_week(PDO $pdo, string $weekStart): void {
    $stmt = $pdo->prepare('SELECT GET_LOCK(?, 5)');
    $stmt->execute(['parking_week_' . $weekStart]);
    if ((int)$stmt->fetchColumn() !== 1) parking_fail('Another booking for that week is being saved. Please try again.', 503);
}

function parking_auto_allocate(PDO $pdo, string $weekStart): void {
    // A week is allocated from the preceding Friday at 12:00 onward.
    // Computing the cutoff from the requested week also allows a missed allocation
    // to be completed automatically on Monday or later when the page is next opened.
    $tz = new DateTimeZone('Europe/Luxembourg');
    $weekDate = new DateTimeImmutable($weekStart, $tz);
    $cutoff = $weekDate->modify('-3 days')->setTime(12, 0);
    $now = new DateTimeImmutable('now', $tz);
    if ($now < $cutoff) return;
    $check = $pdo->prepare('SELECT COUNT(*) FROM weekly_allocations WHERE week_start = ?');
    $check->execute([$weekStart]);
    if ((int)$check->fetchColumn() > 0) return;
    // Monthly plans automatically participate in that week's allocation.
    // The Thursday/Friday registration remains available for late additions.
    // parking_week_candidates() already returns the queue in booking order, so the two
    // spaces simply go to whoever booked the week first.
    $registered = parking_week_candidates($pdo, $weekStart);
    if (!$registered) return;
    $usage = parking_usage($pdo, (int)substr($weekStart, 0, 4));
    $insert = $pdo->prepare('INSERT INTO weekly_allocations (week_start, slot_number, member_name) VALUES (?, ?, ?)');
    $pdo->beginTransaction();
    try {
        $slot = 1;
        foreach ($registered as $member) {
            if ($slot > PARKING_SPACES) break;
            if (($usage[$member] ?? 0) >= PARKING_ANNUAL_MAX) continue;
            $insert->execute([$weekStart, $slot, $member]);
            $usage[$member]++;
            $slot++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function parking_state(PDO $pdo): array {
    $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Luxembourg'));
    $nextWeek = parking_monday($now)->format('Y-m-d');
    // The running week is settled first. parking_monday() always points at a future Monday,
    // so without this call a week whose Friday 12:00 cutoff passed with no page view would
    // never be allocated at all once it had started.
    parking_auto_allocate($pdo, $now->modify('monday this week')->setTime(0, 0)->format('Y-m-d'));
    parking_auto_allocate($pdo, $nextWeek);
    $nextMonth = parking_next_month();
    $plans = array_fill_keys(PARKING_MEMBERS, []);
    $planWeeks = parking_month_weeks($nextMonth['year'], $nextMonth['month']);
    $allowed = array_flip($planWeeks);
    $stmt = $pdo->prepare('SELECT member_name, week_start FROM monthly_plans WHERE week_start BETWEEN ? AND ? ORDER BY week_start');
    $stmt->execute([$planWeeks[0] ?? "$nextMonth[value]-01", end($planWeeks) ?? "$nextMonth[value]-31"]);
    foreach ($stmt as $row) if (isset($allowed[$row['week_start']])) $plans[$row['member_name']][$row['week_start']] = true;
    $registrations = parking_week_candidates($pdo, $nextWeek);
    $stmt = $pdo->prepare('SELECT slot_number, member_name FROM weekly_allocations WHERE week_start = ? ORDER BY slot_number');
    $stmt->execute([$nextWeek]);
    $allocations = array_map(fn($row) => ['slot' => (int)$row['slot_number'], 'member' => $row['member_name']], $stmt->fetchAll());
    $stmt = $pdo->prepare('SELECT slot_number, member_name, status, returned_at FROM fob_log WHERE week_start = ? ORDER BY slot_number');
    $stmt->execute([$nextWeek]);
    $fobLog = array_map(fn($row) => ['slot' => (int)$row['slot_number'], 'member' => $row['member_name'], 'status' => $row['status'], 'returnedAt' => $row['returned_at']], $stmt->fetchAll());
    // Two quotas are in play at once: the planner works on next month, while the weekly
    // registration works on the month that owns next week. They differ around month ends.
    $weekMonth = parking_week_month($nextWeek);
    return [
        'serverNow' => $now->format(DateTimeInterface::ATOM),
        'nextWeek' => $nextWeek,
        'nextMonth' => $nextMonth,
        'weekMonth' => $weekMonth,
        'monthlyPlans' => $plans,
        'registrations' => $registrations,
        'plannedNextWeek' => parking_week_planned($pdo, $nextWeek),
        'allocations' => $allocations,
        'fobLog' => $fobLog,
        'usage' => parking_usage($pdo, (int)$now->format('Y')),
        'planMonthUsage' => parking_month_usage($pdo, $nextMonth['year'], $nextMonth['month']),
        'weekMonthUsage' => parking_month_usage($pdo, $weekMonth['year'], $weekMonth['month']),
        'currentYear' => (int)$now->format('Y'),
        'registrationOpen' => parking_registration_open(),
        'spaces' => PARKING_SPACES,
        'annualMax' => PARKING_ANNUAL_MAX,
        'monthlyMax' => PARKING_MONTHLY_MAX,
        'lostFobFee' => PARKING_LOST_FOB_FEE,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!hash_equals(parking_csrf(), (string)($_POST['csrf'] ?? ''))) parking_fail('Invalid request token.', 403);
    $pdo = parking_db();
    $action = (string)$_POST['action'];
    try {
        if ($action === 'get_state') {
            parking_ok(['state' => parking_state($pdo)]);
        }
        if ($action === 'save_plan') {
            $member = (string)($_POST['member'] ?? '');
            $week = (string)($_POST['week_start'] ?? '');
            $planned = (string)($_POST['planned'] ?? '') === '1';
            parking_member($member);
            $weekDate = DateTimeImmutable::createFromFormat('!Y-m-d', $week, new DateTimeZone('Europe/Luxembourg'));
            if (!$weekDate || $weekDate->format('Y-m-d') !== $week) parking_fail('Invalid week date.');
            // Accept the old browser bug that sent local Monday as Sunday UTC, then normalize it.
            if ($weekDate->format('N') === '7') {
                $weekDate = $weekDate->modify('+1 day');
                $week = $weekDate->format('Y-m-d');
            }
            // Normalize any client-side date variation to the next Monday instead of blocking the booking.
            // The browser sends local dates, but older browsers may serialize local Monday as Sunday UTC.
            if ($weekDate->format('N') !== '1') {
                $weekDate = $weekDate->modify('next monday');
                $week = $weekDate->format('Y-m-d');
            }
            $nextMonth = parking_next_month();
            $allowedWeeks = parking_month_weeks($nextMonth['year'], $nextMonth['month']);
            if (!in_array($week, $allowedWeeks, true)) parking_fail('Only weeks belonging to the next calendar month can be planned.');
            parking_lock_week($pdo, $week);
            $pdo->beginTransaction();
            $exists = $pdo->prepare('SELECT COUNT(*) FROM monthly_plans WHERE member_name = ? AND week_start = ?');
            $exists->execute([$member, $week]);
            if ($planned && (int)$exists->fetchColumn() === 0) {
                // Every member gets PARKING_MONTHLY_MAX weeks a month. Weekly registrations
                // count against the same quota, so the two routes cannot be combined to get more.
                $booked = array_diff(parking_member_month_weeks($pdo, $member, $nextMonth['year'], $nextMonth['month']), [$week]);
                if (count($booked) >= PARKING_MONTHLY_MAX) parking_fail(sprintf('%s has already booked %d weeks in %s, the monthly maximum.', $member, PARKING_MONTHLY_MAX, $nextMonth['value']));
                // The garage has PARKING_SPACES spaces per week. Without this check every
                // member could plan the same week and only two of them would ever get a space.
                $taken = count(parking_week_candidates($pdo, $week));
                if ($taken >= PARKING_SPACES) parking_fail(sprintf('That week is already fully booked: %d of %d spaces are taken. Please choose another week.', $taken, PARKING_SPACES));
                $insert = $pdo->prepare('INSERT INTO monthly_plans (member_name, week_start) VALUES (?, ?)');
                $insert->execute([$member, $week]);
            } elseif (!$planned) {
                $delete = $pdo->prepare('DELETE FROM monthly_plans WHERE member_name = ? AND week_start = ?');
                $delete->execute([$member, $week]);
            }
            $pdo->commit();
            parking_ok(['state' => parking_state($pdo)]);
        }
        if ($action === 'save_weekly_registration') {
            // "preview" is a demo aid. It validates the request and reports what would happen
            // without writing, so the button can never push a real booking past the window.
            $preview = !empty($_POST['preview']);
            if (!parking_registration_open() && !$preview) parking_fail('Weekly registration is closed. It opens Thursday at 09:00 and closes Friday at 12:00.');
            $names = json_decode((string)($_POST['names'] ?? '[]'), true, 20, JSON_THROW_ON_ERROR);
            if (!is_array($names)) parking_fail('Invalid registration list.');
            $names = array_values(array_unique(array_filter($names, fn($name) => is_string($name) && in_array($name, PARKING_MEMBERS, true))));
            $week = parking_monday(new DateTimeImmutable('now', new DateTimeZone('Europe/Luxembourg')))->format('Y-m-d');
            parking_lock_week($pdo, $week);
            // Members holding the week through a monthly plan already occupy a space. They are
            // not counted twice and cannot be dropped from here; only the rest is open to late sign-ups.
            $held = parking_week_planned($pdo, $week);
            $requested = array_values(array_diff($names, $held));
            $free = PARKING_SPACES - count($held);
            if (count($requested) > max(0, $free)) {
                $note = $held ? sprintf(' %s already hold%s a space through the monthly plan.', implode(' and ', $held), count($held) === 1 ? 's' : '') : '';
                parking_fail($free > 0
                    ? sprintf('Only %d of %d spaces are still free next week.%s', $free, PARKING_SPACES, $note)
                    : sprintf('Next week is already fully booked.%s', $note));
            }
            // The monthly quota covers both routes: a member who already holds
            // PARKING_MONTHLY_MAX weeks that month cannot add another one here.
            $weekMonth = parking_week_month($week);
            foreach ($requested as $name) {
                $booked = array_diff(parking_member_month_weeks($pdo, $name, $weekMonth['year'], $weekMonth['month']), [$week]);
                if (count($booked) >= PARKING_MONTHLY_MAX) {
                    parking_fail(sprintf('%s has already booked %d weeks in %s, the monthly maximum.', $name, PARKING_MONTHLY_MAX, $weekMonth['value']));
                }
            }
            if ($preview) parking_ok(['preview' => true, 'state' => parking_state($pdo)]);
            $names = $requested;
            // Weekly changes only affect weekly registrations. Monthly plans remain intact
            // and automatically participate in the same week's allocation.
            $pdo->beginTransaction();
            // Only genuine additions and removals touch the table. Rewriting every row would
            // reset registered_at, and that timestamp is the member's place in the queue.
            $current = $pdo->prepare('SELECT member_name FROM weekly_registrations WHERE week_start = ?');
            $current->execute([$week]);
            $existing = array_column($current->fetchAll(), 'member_name');
            $delete = $pdo->prepare('DELETE FROM weekly_registrations WHERE week_start = ? AND member_name = ?');
            foreach (array_diff($existing, $names) as $name) $delete->execute([$week, $name]);
            $insert = $pdo->prepare('INSERT INTO weekly_registrations (week_start, member_name) VALUES (?, ?)');
            foreach (array_diff($names, $existing) as $name) $insert->execute([$week, $name]);
            $pdo->commit();
            parking_ok(['state' => parking_state($pdo)]);
        }
        if ($action === 'fob_update') {
            $week = (string)($_POST['week_start'] ?? '');
            $slot = (int)($_POST['slot_number'] ?? 0);
            $member = (string)($_POST['member'] ?? '');
            $status = (string)($_POST['status'] ?? '');
            parking_member($member);
            $weekDate = DateTimeImmutable::createFromFormat('!Y-m-d', $week, new DateTimeZone('Europe/Luxembourg'));
            if (!$weekDate || $weekDate->format('Y-m-d') !== $week || $weekDate->format('N') !== '1') parking_fail('Invalid fob week.');
            if ($slot < 1 || $slot > PARKING_SPACES || !in_array($status, ['handed_over', 'returned', 'lost', 'damaged'], true)) parking_fail('Invalid fob record.');
            $stmt = $pdo->prepare('INSERT INTO fob_log (week_start, slot_number, member_name, status, returned_at) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE member_name = VALUES(member_name), status = VALUES(status), returned_at = VALUES(returned_at)');
            $stmt->execute([$week, $slot, $member, $status, $status === 'returned' ? date('Y-m-d H:i:s') : null]);
            parking_ok(['state' => parking_state($pdo)]);
        }
        parking_fail('Unknown action.');
    } catch (JsonException) {
        parking_fail('Invalid JSON payload.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log($e->getMessage());
        parking_fail('The database could not complete that change.', 500);
    }
}

$pdo = parking_db();
$bootstrap = parking_state($pdo);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>101 Parking — Weekly fob registration</title>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Manrope:wght@400;500;600;700;800&display=swap');
    :root {
      /* Ink and surfaces */
      --ink: #0e2030;
      --ink-soft: #33475a;
      --muted: #5c6b75;
      --faint: #869299;
      --line: #dde4e6;
      --line-soft: #ebeff0;
      --paper: #f4f6f5;
      --white: #ffffff;
      --wash: #f8faf9;
      /* Accents. Fills use --orange, text on light uses --orange-ink for contrast. */
      --blue: #1669d3;
      --blue-dark: #0e4c9a;
      --blue-wash: #eff5fd;
      --blue-line: #cfe0f6;
      --mint: #c8f1df;
      --mint-ink: #0a5c40;
      --orange: #ff7a36;
      --orange-ink: #9e3d0e;
      --orange-soft: #fff1e9;
      /* Type scale: distinct steps, no 10-13px mush */
      --fs-micro: .625rem;
      --fs-xs: .75rem;
      --fs-sm: .8125rem;
      --fs-base: .875rem;
      --fs-md: 1rem;
      --fs-lg: 1.125rem;
      --fs-xl: 1.375rem;
      --fs-2xl: 1.625rem;
      /* Spacing rhythm */
      --s1: .25rem; --s2: .5rem; --s3: .75rem; --s4: 1rem;
      --s5: 1.5rem; --s6: 2rem; --s7: 3rem;
      --shadow: 0 18px 50px rgba(14,32,48,.09);
      --shadow-sm: 0 1px 3px rgba(14,32,48,.06);
      --shadow-lift: 0 12px 30px rgba(14,32,48,.14);
      --radius: 20px;
      --radius-md: 14px;
      --radius-sm: 10px;
      --tap: 44px;
      --pad: clamp(1rem, 3vw, 1.75rem);
    }
    * { box-sizing: border-box; }
    html { -webkit-text-size-adjust: 100%; }
    body {
      margin: 0;
      color: var(--ink);
      background: var(--paper);
      font-family: Manrope, Arial, sans-serif;
      font-size: var(--fs-base);
      line-height: 1.5;
      -webkit-font-smoothing: antialiased;
    }
    button, select { font: inherit; }
    button { cursor: pointer; }
    button:focus-visible, select:focus-visible { outline: 3px solid #94c9ff; outline-offset: 3px; }
    .mono { font-family: 'DM Mono', monospace; letter-spacing: -.03em; }
    .app { min-height: 100vh; }

    /* ── Top bar ─────────────────────────────────────────── */
    .topbar {
      position: sticky; top: 0; z-index: 20;
      background: var(--ink); color: white;
      padding: var(--s3) clamp(.8rem, 5vw, 4.5rem);
      display: flex; align-items: center; justify-content: space-between; gap: var(--s4);
    }
    .brand { display: flex; align-items: center; gap: var(--s3); min-width: 0; }
    .brand-mark {
      flex: none; width: 34px; height: 34px; border-radius: 50%; background: var(--orange);
      display: grid; place-items: center; color: var(--ink); font-size: var(--fs-sm); font-weight: 800;
    }
    .brand-name { display: block; font-size: var(--fs-base); font-weight: 800; letter-spacing: -.025em; }
    .brand-sub { display: block; color: #93a4ae; font-size: var(--fs-xs); }
    .clock { flex: none; color: #c3cfd6; font-size: var(--fs-xs); text-align: right; }

    main { width: min(1180px, calc(100% - 2.5rem)); margin: 0 auto; padding: var(--s7) 0 var(--s7); }

    /* ── Hero ────────────────────────────────────────────── */
    .eyebrow { color: var(--blue); font-family: 'DM Mono', monospace; text-transform: uppercase; font-size: var(--fs-xs); letter-spacing: .1em; font-weight: 500; }
    h1 { font-size: clamp(2.25rem, 6.5vw, 3.9rem); letter-spacing: -.055em; line-height: 1; margin: var(--s3) 0 var(--s4); max-width: 16ch; }
    h1 em { font-style: italic; color: var(--blue); }
    .lede { color: var(--muted); max-width: 62ch; margin: 0; font-size: var(--fs-md); line-height: 1.55; }
    .hero { display: flex; justify-content: space-between; align-items: flex-end; gap: var(--s6); margin-bottom: var(--s6); }
    .hero-note { flex: none; width: 230px; padding: var(--s4); border: 1px solid var(--line); background: rgba(255,255,255,.62); border-radius: var(--radius-md); }
    .hero-note strong { display: block; font-size: var(--fs-xl); letter-spacing: -.04em; }
    .hero-note span { display: block; margin-top: var(--s1); color: var(--muted); font-size: var(--fs-xs); line-height: 1.45; }

    /* ── Panels ──────────────────────────────────────────── */
    .grid { display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(0, .88fr); gap: var(--s5); align-items: start; }
    .panel { background: var(--white); border: 1px solid var(--line); border-radius: var(--radius); box-shadow: var(--shadow); overflow: hidden; }
    .panel-head { display: flex; justify-content: space-between; align-items: center; gap: var(--s4); border-bottom: 1px solid var(--line); padding: var(--s5) var(--pad); }
    .panel-title { margin: 0; font-size: var(--fs-lg); letter-spacing: -.035em; }
    .panel-caption { margin: var(--s1) 0 0; color: var(--muted); font-size: var(--fs-xs); }
    .panel-tag { flex: none; color: var(--faint); font-size: var(--fs-xs); text-transform: uppercase; letter-spacing: .08em; }
    .status {
      display: inline-flex; align-items: center; gap: var(--s2); padding: var(--s2) var(--s3); border-radius: 99px;
      font-size: var(--fs-xs); font-weight: 700; white-space: nowrap;
    }
    .status-open { background: var(--mint); color: var(--mint-ink); }
    .status-closed { background: #eceff0; color: #5b686f; }
    .status-dot { width: 7px; height: 7px; border-radius: 50%; background: currentColor; }
    .status-open .status-dot { animation: pulse 2.4s ease-in-out infinite; }
    @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: .35; } }

    /* ── Section rhythm inside the main panel ────────────── */
    .week-strip { padding: var(--s5) var(--pad) var(--pad); }
    .sec { border-top: 1px solid var(--line); margin-top: var(--s6); padding-top: var(--s5); }
    .sec-head { display: flex; justify-content: space-between; align-items: flex-start; gap: var(--s4); margin-bottom: var(--s4); }
    .sec-eyebrow { display: block; margin-bottom: var(--s2); font-family: 'DM Mono', monospace; font-size: var(--fs-micro); text-transform: uppercase; letter-spacing: .12em; color: var(--faint); }
    .sec-title { margin: 0; font-size: var(--fs-xl); letter-spacing: -.04em; line-height: 1.15; }
    .sec-caption { margin: var(--s2) 0 0; color: var(--muted); font-size: var(--fs-xs); max-width: 58ch; line-height: 1.5; }
    .sec-aside { flex: none; text-align: right; }

    /* ── This week + fobs ────────────────────────────────── */
    .week-date { display: flex; align-items: baseline; gap: var(--s3); flex-wrap: wrap; }
    .week-date h2 { margin: 0; font-size: clamp(1.75rem, 5.5vw, 2.5rem); letter-spacing: -.055em; line-height: 1.05; }
    .week-date span { color: var(--muted); font-size: var(--fs-base); font-family: 'DM Mono', monospace; }
    .week-holiday { display: none; margin-top: var(--s3); padding: var(--s2) var(--s3); border-radius: var(--radius-sm); background: var(--orange-soft); color: var(--orange-ink); font-size: var(--fs-xs); font-weight: 600; }
    .week-holiday.show { display: block; }
    .fobs { display: grid; grid-template-columns: 1fr 1fr; gap: var(--s3); margin-top: var(--s5); }
    .fob {
      position: relative; overflow: hidden; min-height: 128px; padding: var(--s4); border-radius: var(--radius-md);
      background: var(--ink); color: white; display: flex; flex-direction: column; justify-content: space-between;
    }
    .fob:nth-child(2) { background: var(--blue); }
    .fob:after { content: ''; position: absolute; width: 96px; height: 96px; border: 1px solid rgba(255,255,255,.22); border-radius: 50%; right: -28px; bottom: -40px; }
    .fob-tag { font-family: 'DM Mono', monospace; font-size: var(--fs-micro); letter-spacing: .1em; text-transform: uppercase; color: #a3b6c1; }
    .fob:nth-child(2) .fob-tag { color: #bcdcff; }
    .fob-person { margin-top: var(--s5); font-size: var(--fs-xl); font-weight: 800; letter-spacing: -.045em; line-height: 1.1; }
    .fob-person.is-empty { color: rgba(255,255,255,.5); font-weight: 600; font-size: var(--fs-md); }
    .rules { display: grid; grid-template-columns: 1fr 1fr; gap: var(--s3); margin-top: var(--s3); }
    .rule { padding: var(--s3) var(--s4); border-radius: var(--radius-sm); background: var(--wash); border: 1px solid var(--line-soft); }
    .rule b { display: block; font-size: var(--fs-sm); }
    .rule span { display: block; margin-top: 2px; color: var(--muted); font-size: var(--fs-xs); }

    /* ── Saved allocation grid ───────────────────────────── */
    .saved-calendar { border: 1px solid var(--line); border-radius: var(--radius-md); overflow: hidden; background: white; }
    .calendar-scroll { overflow-x: auto; }
    .saved-grid { min-width: 560px; display: grid; grid-template-columns: 150px repeat(5, 1fr); }
    .saved-grid > div { min-height: 62px; padding: var(--s3); border-right: 1px solid var(--line-soft); border-bottom: 1px solid var(--line-soft); display: flex; flex-direction: column; justify-content: center; }
    .saved-grid > div:nth-child(6n) { border-right: 0; }
    .saved-grid > div:nth-last-child(-n+5) { border-bottom: 0; }
    .saved-grid .day-head { min-height: 48px; background: var(--wash); color: var(--muted); font-family: 'DM Mono', monospace; font-size: var(--fs-micro); letter-spacing: .08em; text-transform: uppercase; }
    .saved-grid .corner { color: var(--ink); font-weight: 500; }
    .space-label { font-family: 'DM Mono', monospace; color: var(--faint); font-size: var(--fs-micro); letter-spacing: .1em; text-transform: uppercase; }
    .space-week { display: none; margin-top: 2px; color: var(--muted); font-size: var(--fs-xs); }
    .saved-name { display: block; margin-top: var(--s1); font-size: var(--fs-base); font-weight: 800; letter-spacing: -.03em; }
    .saved-empty { color: var(--faint); font-size: var(--fs-sm); font-weight: 600; }
    .saved-calendar-foot { display: flex; justify-content: space-between; gap: var(--s3); padding: var(--s3) var(--s4); color: var(--muted); font-size: var(--fs-xs); background: var(--wash); border-top: 1px solid var(--line-soft); }
    .waitlist { color: var(--orange-ink); font-weight: 700; }

    /* ── Register (primary action) ───────────────────────── */
    .register { border: 1px solid var(--blue-line); border-radius: var(--radius-md); background: var(--blue-wash); padding: var(--pad); }
    .register .sec-eyebrow { color: var(--blue); }
    .register-top { display: flex; justify-content: space-between; gap: var(--s4); align-items: flex-start; }
    .register h3 { margin: 0; font-size: var(--fs-xl); letter-spacing: -.04em; }
    .register p { margin: var(--s2) 0 var(--s4); color: var(--muted); font-size: var(--fs-xs); max-width: 58ch; line-height: 1.5; }
    .count-pill { flex: none; padding: var(--s2) var(--s3); border-radius: 99px; background: white; border: 1px solid var(--blue-line); color: var(--blue-dark); font-size: var(--fs-sm); font-weight: 700; white-space: nowrap; }
    .people { display: grid; grid-template-columns: repeat(auto-fit, minmax(9.5rem, 1fr)); gap: var(--s2); }
    .person {
      min-height: var(--tap); border: 1px solid var(--blue-line); background: white; border-radius: var(--radius-sm);
      padding: var(--s3); color: var(--ink); text-align: left;
      transition: transform .15s ease, border-color .15s ease, background .15s ease, box-shadow .15s ease;
    }
    .person:hover:not(:disabled) { transform: translateY(-2px); border-color: var(--blue); box-shadow: var(--shadow-sm); }
    .person.selected { background: var(--blue); color: white; border-color: var(--blue); box-shadow: var(--shadow-sm); }
    .person:disabled { cursor: not-allowed; opacity: .5; }
    .person.selected:disabled { opacity: .85; }
    .person-name { display: block; font-size: var(--fs-base); font-weight: 800; letter-spacing: -.02em; }
    .person-count { display: block; font-family: 'DM Mono', monospace; font-size: var(--fs-micro); margin-top: 3px; opacity: .72; }
    .register-foot { display: flex; justify-content: space-between; align-items: center; gap: var(--s4); margin-top: var(--s4); }
    .fine { color: var(--muted); font-size: var(--fs-xs); }
    .primary {
      border: 0; color: white; background: var(--blue); min-height: var(--tap); padding: var(--s3) var(--s5);
      border-radius: var(--radius-sm); font-size: var(--fs-base); font-weight: 800; letter-spacing: -.01em;
      transition: background .15s ease, transform .15s ease;
    }
    .primary:hover:not(:disabled) { background: var(--blue-dark); transform: translateY(-1px); }
    .primary:disabled { background: #a9b7bf; cursor: not-allowed; }
    .notice { display: none; margin-top: var(--s3); padding: var(--s3) var(--s4); border-radius: var(--radius-sm); background: var(--mint); color: var(--mint-ink); font-size: var(--fs-sm); font-weight: 700; }
    .notice.show { display: block; }

    /* ── Month planner ───────────────────────────────────── */
    .month-planner-controls { display: flex; align-items: center; gap: var(--s2); flex-wrap: wrap; justify-content: flex-end; }
    .month-planner-controls .sec-eyebrow { margin-bottom: 0; }
    .month-planner-controls select,
    .calendar-controls select { border: 1px solid var(--line); border-radius: var(--radius-sm); min-height: var(--tap); padding: var(--s2) var(--s3); background: white; color: var(--ink); font-size: var(--fs-base); font-weight: 700; }
    .planner-shell { border: 1px solid var(--line); border-radius: var(--radius-md); overflow: hidden; background: white; }
    .planner-scroll { overflow-x: auto; }
    .planner-grid { min-width: 596px; display: grid; grid-template-columns: 116px repeat(5, 1fr); }
    .planner-grid > div { min-height: 60px; padding: var(--s2); border-right: 1px solid var(--line-soft); border-bottom: 1px solid var(--line-soft); display: flex; align-items: center; }
    .planner-grid > div:nth-child(6n) { border-right: 0; }
    .planner-grid > div:nth-last-child(-n+5) { border-bottom: 0; }
    .planner-grid .planner-head-cell { min-height: 64px; background: var(--wash); color: var(--muted); font-family: 'DM Mono', monospace; font-size: var(--fs-micro); letter-spacing: .06em; text-transform: uppercase; flex-direction: column; justify-content: center; align-items: flex-start; gap: 2px; line-height: 1.35; }
    .planner-grid .planner-corner { color: var(--ink); font-weight: 500; }
    .planner-head-seats { display: inline-block; padding: 1px var(--s2); border-radius: 99px; background: var(--mint); color: var(--mint-ink); font-size: var(--fs-micro); font-weight: 500; }
    .planner-head-seats.is-full { background: var(--orange-soft); color: var(--orange-ink); }
    .planner-person { display: flex; align-items: center; gap: var(--s2); font-size: var(--fs-base); font-weight: 800; letter-spacing: -.02em; }
    .planner-person.active { color: var(--blue); }
    .planner-person.active:before { content: ''; width: 5px; height: 18px; border-radius: 3px; background: var(--blue); flex: none; }
    .plan-cell { width: 100%; min-height: var(--tap); border: 1px solid var(--blue-line); border-radius: var(--radius-sm); background: white; color: var(--blue-dark); font-size: var(--fs-xs); font-weight: 800; letter-spacing: .01em; transition: border-color .15s ease, background .15s ease, color .15s ease; }
    .plan-cell:hover:not(:disabled) { border-color: var(--blue); background: var(--blue-wash); }
    .plan-cell.selected { border-color: var(--blue); background: var(--blue); color: white; }
    .plan-cell.locked { background: var(--line-soft); border-color: var(--line); color: #8d989d; cursor: not-allowed; }
    .plan-cell.holiday-plan:not(.selected):not(.locked) { border-color: #f3ceb9; background: var(--orange-soft); color: var(--orange-ink); }
    .plan-cell:disabled:not(.locked) { opacity: .45; cursor: not-allowed; }
    .planner-foot { display: flex; justify-content: space-between; align-items: center; gap: var(--s4); margin-top: var(--s4); }
    .planner-foot span { color: var(--muted); font-size: var(--fs-xs); max-width: 46ch; }
    .planner-limit { margin-top: var(--s3); padding: var(--s3) var(--s4); border-radius: var(--radius-sm); background: var(--blue-wash); border: 1px solid var(--blue-line); color: var(--blue-dark); font-size: var(--fs-xs); line-height: 1.5; }
    .planner-limit strong { font-weight: 800; }
    .planner-notice { display: none; margin-top: var(--s3); padding: var(--s3) var(--s4); border-radius: var(--radius-sm); background: var(--mint); color: var(--mint-ink); font-size: var(--fs-sm); font-weight: 700; }
    .planner-notice.show { display: block; }

    /* Stacked week cards, used instead of the matrix on narrow screens */
    .planner-cards { display: none; flex-direction: column; gap: var(--s3); }
    .week-card { border: 1px solid var(--line); border-radius: var(--radius-md); background: white; padding: var(--s4); }
    .week-card.is-full { background: var(--wash); }
    .week-card.is-mine { border-color: var(--blue); box-shadow: 0 0 0 1px var(--blue); }
    .week-card-top { display: flex; justify-content: space-between; align-items: center; gap: var(--s3); }
    .week-card-date { font-size: var(--fs-md); font-weight: 800; letter-spacing: -.035em; }
    .week-card-holiday { display: inline-block; margin-top: var(--s1); padding: 1px var(--s2); border-radius: 99px; background: var(--orange-soft); color: var(--orange-ink); font-size: var(--fs-micro); font-weight: 700; }
    .week-card-who { margin-top: var(--s2); color: var(--muted); font-size: var(--fs-xs); }
    .week-card-who b { color: var(--ink); font-weight: 800; }
    .week-card .plan-cell { margin-top: var(--s3); }

    /* ── Side panels ─────────────────────────────────────── */
    .side-stack { display: grid; gap: var(--s5); }
    .ledger-list { padding: var(--s2) var(--pad) var(--s4); }
    .ledger-row { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: var(--s2) var(--s4); align-items: center; padding: var(--s4) 0; border-bottom: 1px solid var(--line-soft); }
    .ledger-row:last-child { border-bottom: 0; }
    .ledger-person { font-size: var(--fs-base); font-weight: 800; letter-spacing: -.02em; }
    .ledger-meta { display: flex; gap: var(--s4); justify-content: flex-end; }
    .ledger-cell { text-align: right; }
    .ledger-label { display: block; font-family: 'DM Mono', monospace; color: var(--faint); font-size: var(--fs-micro); letter-spacing: .08em; text-transform: uppercase; }
    .ledger-value { display: block; margin-top: 2px; font-family: 'DM Mono', monospace; font-size: var(--fs-sm); font-weight: 500; }
    .quota { height: 6px; margin-top: var(--s2); background: #e8edee; border-radius: 10px; overflow: hidden; }
    .quota-bar { height: 100%; background: var(--orange); border-radius: inherit; transition: width .4s ease; }
    .how { padding: var(--s2) var(--pad) var(--s5); }
    .step { display: grid; grid-template-columns: 30px 1fr; gap: var(--s3); padding: var(--s4) 0; border-bottom: 1px solid var(--line-soft); }
    .step:last-child { border-bottom: 0; }
    .step-num { width: 30px; height: 30px; display: grid; place-items: center; border-radius: 50%; background: var(--orange-soft); color: var(--orange-ink); font-family: 'DM Mono', monospace; font-size: var(--fs-xs); font-weight: 500; }
    .step b { font-size: var(--fs-base); letter-spacing: -.02em; }
    .step p { margin: 2px 0 0; font-size: var(--fs-xs); color: var(--muted); }

    /* ── Calendar + etiquette ────────────────────────────── */
    .calendar-panel { margin-top: var(--s5); }
    .calendar-controls { display: flex; align-items: center; gap: var(--s2); }
    .holiday-list { padding: var(--s2) var(--pad) var(--s4); display: grid; grid-template-columns: 1fr 1fr; gap: 0 var(--s6); }
    .holiday-row { display: flex; justify-content: space-between; gap: var(--s3); padding: var(--s3) 0; border-bottom: 1px solid var(--line-soft); font-size: var(--fs-base); }
    .holiday-row b { font-weight: 700; letter-spacing: -.02em; }
    .holiday-row span:last-child { color: var(--muted); text-align: right; font-family: 'DM Mono', monospace; font-size: var(--fs-sm); white-space: nowrap; }
    .policy { margin: 0 var(--pad) var(--s5); padding: var(--s4); border-radius: var(--radius-md); background: var(--orange-soft); color: var(--orange-ink); font-size: var(--fs-sm); line-height: 1.5; }
    .policy b { color: #7d2f0a; }
    .etiquette { margin-top: var(--s5); }
    .etiquette-list { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0 var(--s6); padding: var(--s2) var(--pad) var(--s5); }
    .etiquette-item { display: grid; grid-template-columns: 30px 1fr; gap: var(--s3); padding: var(--s4) 0; border-bottom: 1px solid var(--line-soft); }
    .etiquette-item:nth-last-child(-n+2) { border-bottom: 0; }
    .etiquette-icon { width: 30px; height: 30px; display: grid; place-items: center; border-radius: var(--radius-sm); background: var(--orange-soft); color: var(--orange-ink); font-family: 'DM Mono', monospace; font-size: var(--fs-xs); font-weight: 500; }
    .etiquette-item b { display: block; font-size: var(--fs-base); letter-spacing: -.02em; }
    .etiquette-item p { margin: var(--s1) 0 0; color: var(--muted); font-size: var(--fs-xs); line-height: 1.55; }

    .admin { display: flex; justify-content: space-between; align-items: center; gap: var(--s4); padding: var(--s3) var(--pad); border-top: 1px solid var(--line); background: var(--wash); }
    .admin span { font-size: var(--fs-xs); color: var(--muted); }
    .admin button { background: transparent; border: 1px solid var(--line); border-radius: var(--radius-sm); min-height: var(--tap); padding: var(--s2) var(--s3); font-size: var(--fs-xs); font-weight: 700; color: var(--ink); }
    .admin button:hover { border-color: var(--ink); }
    .footer { margin-top: var(--s5); display: flex; justify-content: space-between; gap: var(--s5); color: var(--muted); font-size: var(--fs-xs); }
    .footer strong { color: var(--ink); }

    /* Staggered entrance, calm enough for a page opened every week */
    @media (prefers-reduced-motion: no-preference) {
      .panel, .hero > * { animation: rise .5s cubic-bezier(.22,.8,.3,1) backwards; }
      .hero > *:nth-child(2) { animation-delay: .06s; }
      .grid > .panel { animation-delay: .1s; }
      .side-stack .panel:nth-child(2) { animation-delay: .16s; }
      .calendar-panel { animation-delay: .2s; }
      .etiquette { animation-delay: .24s; }
    }
    @keyframes rise { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: none; } }

    /* ── Responsive ──────────────────────────────────────── */
    @media (max-width: 1080px) {
      .grid { grid-template-columns: 1fr; }
      .side-stack { grid-template-columns: 1fr 1fr; align-items: start; }
    }
    @media (max-width: 900px) {
      .hero { flex-direction: column; align-items: flex-start; gap: var(--s5); }
      .hero-note { width: 100%; }
      main { padding-top: var(--s6); }
    }
    @media (max-width: 760px) {
      .side-stack { grid-template-columns: 1fr; }
      .holiday-list, .etiquette-list { grid-template-columns: 1fr; }
      .etiquette-item:nth-last-child(-n+2) { border-bottom: 1px solid var(--line-soft); }
      .etiquette-item:last-child { border-bottom: 0; }
      /* The people x weeks matrix cannot work at this width: use stacked week cards. */
      .planner-shell { display: none; }
      .planner-cards { display: flex; }
      /* Every weekday column repeats the same name, so show one card per space instead. */
      .calendar-scroll { overflow-x: visible; }
      .saved-grid { min-width: 0; grid-template-columns: 1fr; }
      .saved-grid > div:not(.space-row) { display: none; }
      .saved-grid > div { border-right: 0; }
      .saved-grid > div.space-row { border-bottom: 1px solid var(--line-soft); }
      .saved-grid > div.space-row:last-of-type { border-bottom: 0; }
      .space-week { display: block; }
      .saved-calendar-foot { flex-direction: column; gap: var(--s1); }
    }
    @media (max-width: 560px) {
      main { width: min(100% - 1.5rem, 1180px); }
      h1 { max-width: none; }
      .brand-sub { display: none; }
      .clock-zone { display: none; }
      .fobs, .rules { grid-template-columns: 1fr; }
      .fob { min-height: 0; }
      .fob-person { margin-top: var(--s4); }
      .panel-head, .sec-head { flex-direction: column; align-items: flex-start; gap: var(--s3); }
      .hero-note { display: flex; align-items: baseline; gap: var(--s3); }
      .hero-note strong { flex: none; }
      .hero-note span { margin-top: 0; }
      .hero { margin-bottom: var(--s5); }
      main { padding-top: var(--s5); }
      .sec-aside, .month-planner-controls { text-align: left; justify-content: flex-start; width: 100%; }
      .month-planner-controls select { flex: 1; }
      .register-foot, .planner-foot, .footer, .admin { flex-direction: column; align-items: stretch; gap: var(--s3); }
      .register-foot .primary, .planner-foot .primary, .admin button { width: 100%; }
      .people { grid-template-columns: 1fr 1fr; }
      .footer { text-align: left; }
    }
    @media (prefers-reduced-motion: reduce) { *, *:before, *:after { transition: none !important; animation: none !important; } }
  </style>
</head>
<body>
  <div class="app">
    <header class="topbar">
      <div class="brand">
        <div class="brand-mark">101</div>
        <div><span class="brand-name">Parking / weekly fobs</span><span class="brand-sub">Gonderange</span></div>
      </div>
      <div class="clock" id="clock">Loading local time…</div>
    </header>

    <main>
      <section class="hero">
        <div>
          <div class="eyebrow">Shared resource / 02 spaces / 05 people</div>
          <h1>One week. One fob.<br><em>First come, first served.</em></h1>
          <p class="lede">Every person can book two weeks a month. Claim a week in the monthly planner, or register for the following Monday–Friday week before Friday noon. The two spaces go to whoever booked the week first.</p>
        </div>
        <div class="hero-note"><strong>2 / month</strong><span>weeks per person, on both spaces, allocated in booking order and capped at 21 weeks a year.</span></div>
      </section>

      <div class="grid">
        <section class="panel">
          <div class="panel-head">
            <div><h2 class="panel-title">Next allocation</h2><p class="panel-caption">The fobs change hands every Friday afternoon.</p></div>
            <span class="status status-closed" id="windowStatus"><i class="status-dot"></i><span>Registration closed</span></span>
          </div>
          <div class="week-strip">
            <div class="week-date"><h2 id="weekLabel">17–21 Aug</h2><span id="weekYear">2026</span></div>
            <div class="week-holiday" id="weekHoliday"></div>
            <div class="fobs">
              <div class="fob"><div class="fob-tag">FOB A / SPACE 01</div><div class="fob-person" id="fobA">Unallocated</div></div>
              <div class="fob"><div class="fob-tag">FOB B / SPACE 02</div><div class="fob-person" id="fobB">Unallocated</div></div>
            </div>
            <div class="rules">
              <div class="rule"><b>Opens Thursday 09:00</b><span>For the following Monday.</span></div>
              <div class="rule"><b>Closes Friday 12:00</b><span>Allocation is then locked.</span></div>
            </div>

            <section class="sec" aria-live="polite">
              <div class="sec-head">
                <div>
                  <span class="sec-eyebrow">Confirmed</span>
                  <h3 class="sec-title">Saved spaces this week</h3>
                  <p class="sec-caption" id="savedCalendarCaption">The allocation appears here once registrations are saved.</p>
                </div>
                <div class="sec-aside"><span class="status status-closed mono" id="savedCalendarStatus">NOT YET SAVED</span></div>
              </div>
              <div class="saved-calendar">
                <div class="calendar-scroll"><div class="saved-grid" id="savedCalendarGrid"></div></div>
                <div class="saved-calendar-foot"><span>Each saved name holds the space for the full Monday–Friday week.</span><span class="waitlist" id="waitlistText"></span></div>
              </div>
            </section>

            <section class="sec">
              <div class="register" id="registerBox">
                <div class="register-top">
                  <div>
                    <span class="sec-eyebrow">Act now · Thursday 09:00 – Friday 12:00</span>
                    <h3>Who needs a space next week?</h3>
                  </div>
                  <span class="count-pill mono" id="registrationCount">0 / 2</span>
                </div>
                <p id="registerHelp">Registration is currently closed. It opens automatically during the Thursday–Friday window.</p>
                <div class="people" id="people"></div>
                <div class="register-foot"><span class="fine">You can opt out at any time before the deadline.</span><button class="primary" id="registerBtn" disabled>Save registration</button></div>
                <div class="notice" id="notice">Registration saved for the next allocation.</div>
              </div>
            </section>

            <section class="sec" aria-live="polite">
              <div class="sec-head">
                <div>
                  <span class="sec-eyebrow">Plan ahead</span>
                  <h3 class="sec-title">Plan next month</h3>
                  <p class="sec-caption month-planner-caption">Only the next calendar month is open for planning. Select a name to add weeks; any colleague may cancel an existing plan.</p>
                </div>
                <div class="sec-aside month-planner-controls"><label class="mono sec-eyebrow" for="plannerUser">Book as</label><select id="plannerUser" aria-label="Select the person booking parking"></select></div>
              </div>
              <div class="planner-shell"><div class="planner-scroll"><div class="planner-grid" id="plannerGrid"></div></div></div>
              <div class="planner-cards" id="plannerCards"></div>
              <div class="planner-limit" id="plannerLimit"></div>
              <div class="planner-notice" id="plannerNotice"></div>
              <div class="planner-foot"><span>Planning is visible to the team; it is not a guaranteed reservation until the weekly allocation is saved.</span><button class="primary" id="savePlanBtn" disabled>Save monthly plan</button></div>
            </section>
          </div>
          <div class="admin"><span>Demo controls — useful while testing the flow</span><button id="toggleWindow">Preview open window</button></div>
        </section>

        <aside class="side-stack">
          <section class="panel">
            <div class="panel-head"><div><h2 class="panel-title" id="ledgerTitle">2026 usage ledger</h2><p class="panel-caption">2 weeks per person per month, in booking order.</p></div><span class="panel-tag mono">max 21</span></div>
            <div class="ledger-list" id="ledger"></div>
          </section>
          <section class="panel">
            <div class="panel-head"><div><h2 class="panel-title">The weekly rhythm</h2><p class="panel-caption">No Monday morning scramble.</p></div></div>
            <div class="how">
              <div class="step"><div class="step-num">01</div><div><b>Register</b><p>Thursday 09:00 to Friday 12:00.</p></div></div>
              <div class="step"><div class="step-num">02</div><div><b>Allocate</b><p>The first two bookings keep the spaces.</p></div></div>
              <div class="step"><div class="step-num">03</div><div><b>Exchange</b><p>Fobs are handed over Friday afternoon.</p></div></div>
              <div class="step"><div class="step-num">04</div><div><b>Return</b><p>Bring both fobs back the following Friday.</p></div></div>
            </div>
          </section>
        </aside>
      </div>
      <section class="panel calendar-panel">
        <div class="panel-head">
          <div><h2 class="panel-title">Luxembourg calendar</h2><p class="panel-caption">Public holidays are shown so weekly registrations stay predictable.</p></div>
          <div class="calendar-controls"><label class="mono" for="holidayYear" style="font-size:11px;color:var(--muted)">YEAR</label><select id="holidayYear" aria-label="Select calendar year"></select></div>
        </div>
        <div class="holiday-list" id="holidayList"></div>
        <div class="policy"><b>Fob responsibility:</b> Keep the assigned fob secure and return it on Friday afternoon. A lost or unreturned fob is charged at <strong>€100</strong>.</div>
      </section>
      <section class="panel etiquette">
        <div class="panel-head">
          <div><h2 class="panel-title">Garage etiquette &amp; responsibility</h2><p class="panel-caption">Please use the garage carefully and plan your week with consideration for colleagues.</p></div>
        </div>
        <div class="etiquette-list">
          <div class="etiquette-item"><div class="etiquette-icon">01</div><div><b>Enter and exit slowly</b><p>The garage is quite narrow. Take extra care when entering or leaving to avoid damaging your own car or another parked car. Any damage or associated costs are at the driver’s own expense.</p></div></div>
          <div class="etiquette-item"><div class="etiquette-icon">02</div><div><b>Close the garage when you leave</b><p>The garage door does not close automatically. Before driving away, check that the door has fully closed and that the garage is secure.</p></div></div>
          <div class="etiquette-item"><div class="etiquette-icon">03</div><div><b>Plan around Friday home office</b><p>Before registering, check whether you will be working from home on Friday. If you will not need the space for the full week, please do not take a weekly allocation unless you have agreed a respectful swap.</p></div></div>
          <div class="etiquette-item"><div class="etiquette-icon">04</div><div><b>Check holidays and absences</b><p>Review public holidays, your holidays and other planned absences before registering. Opt out early when appropriate so the space can be allocated fairly to someone who will use it.</p></div></div>
          <div class="etiquette-item"><div class="etiquette-icon">05</div><div><b>Respect the weekly allocation</b><p>Use the assigned space only for your allocated Monday–Friday week, return the fob on time, and communicate any change as soon as possible.</p></div></div>
          <div class="etiquette-item"><div class="etiquette-icon">06</div><div><b>Lost fob: €100</b><p>A lost, damaged or unreturned fob is charged at <strong>€100</strong>. Keep it secure throughout the week and return it on Friday afternoon.</p></div></div>
        </div>
      </section>
      <div class="footer"><span><strong>Eligible:</strong> Nadia · Laurence · Lara · Jil · Erik</span><span>Marc and Daniel have separate parking.</span></div>
    </main>
  </div>

  <script>
    const SERVER_BOOTSTRAP = <?php echo parking_json($bootstrap); ?>;
    const SERVER_CSRF = <?php echo parking_json(parking_csrf()); ?>;
    const people = ['Nadia', 'Laurence', 'Lara', 'Jil', 'Erik'];
    const START_YEAR = 2026;
    const END_YEAR = 2030;
    const ANNUAL_MAX = 21;
    const SPACES = SERVER_BOOTSTRAP.spaces || 2;
    const MONTHLY_MAX = SERVER_BOOTSTRAP.monthlyMax || 2;
    const LOST_FOB_FEE = 100;
    const state = {
      openPreview: false,
      registrations: [],
      usageByYear: {
        2026: { Nadia: 12, Laurence: 10, Lara: 11, Jil: 9, Erik: 10 },
        2027: { Nadia: 0, Laurence: 0, Lara: 0, Jil: 0, Erik: 0 },
        2028: { Nadia: 0, Laurence: 0, Lara: 0, Jil: 0, Erik: 0 },
        2029: { Nadia: 0, Laurence: 0, Lara: 0, Jil: 0, Erik: 0 },
        2030: { Nadia: 0, Laurence: 0, Lara: 0, Jil: 0, Erik: 0 }
      },
      awardsByYear: {
        2026: { Nadia: '07 Aug', Laurence: '31 Jul', Lara: '24 Jul', Jil: '07 Aug', Erik: '31 Jul' },
        2027: {}, 2028: {}, 2029: {}, 2030: {}
      },
      selectedHolidayYear: new Date().getFullYear(),
      selectedPlannerMonth: '',
      currentUser: 'Nadia',
      monthlyPlans: {
        'Nadia': {}, 'Laurence': {}, 'Lara': {}, 'Jil': {}, 'Erik': {}
      }
    };
    state.monthlyPlans = SERVER_BOOTSTRAP.monthlyPlans || state.monthlyPlans;
    state.registrations = SERVER_BOOTSTRAP.registrations || [];
    state.plannedNextWeek = SERVER_BOOTSTRAP.plannedNextWeek || [];
    state.allocations = SERVER_BOOTSTRAP.allocations || [];
    state.fobLog = SERVER_BOOTSTRAP.fobLog || [];
    // Weeks each member has already booked this month, counted by the server across both
    // the monthly planner and the weekly registration.
    state.planMonthUsage = SERVER_BOOTSTRAP.planMonthUsage || {};
    state.weekMonthUsage = SERVER_BOOTSTRAP.weekMonthUsage || {};
    state.usageByYear[SERVER_BOOTSTRAP.currentYear] = SERVER_BOOTSTRAP.usage || state.usageByYear[SERVER_BOOTSTRAP.currentYear];
    state.selectedHolidayYear = SERVER_BOOTSTRAP.currentYear;

    async function parkingPost(action, values = {}) {
      const body = new URLSearchParams({ action, csrf: SERVER_CSRF, ...values });
      const response = await fetch(window.location.href, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body });
      const data = await response.json();
      if (!data.ok) throw new Error(data.error || 'The change could not be saved.');
      return data;
    }
    function applyServerState(serverState) {
      if (!serverState) return;
      state.monthlyPlans = serverState.monthlyPlans || state.monthlyPlans;
      state.registrations = serverState.registrations || [];
      state.plannedNextWeek = serverState.plannedNextWeek || [];
      state.allocations = serverState.allocations || [];
      state.fobLog = serverState.fobLog || [];
      state.planMonthUsage = serverState.planMonthUsage || {};
      state.weekMonthUsage = serverState.weekMonthUsage || {};
      if (serverState.currentYear && serverState.usage) state.usageByYear[serverState.currentYear] = serverState.usage;
      if (serverState.currentYear) state.selectedHolidayYear = serverState.currentYear;
    }
    async function refreshParkingState() {
      try {
        const data = await parkingPost('get_state');
        applyServerState(data.state);
        render();
      } catch (error) {
        console.warn('Parking state refresh failed:', error.message);
      }
    }

    // Luxembourg public holidays, calculated for every year from 2026 through 2030.
    // Easter Monday and Ascension are calculated from Easter Sunday; Whit Monday is 50 days later.
    function easterSunday(year) {
      const a = year % 19, b = Math.floor(year / 100), c = year % 100;
      const d = Math.floor(b / 4), e = b % 4, f = Math.floor((b + 8) / 25);
      const g = Math.floor((b - f + 1) / 3), h = (19 * a + b - d - g + 15) % 30;
      const i = Math.floor(c / 4), k = c % 4, l = (32 + 2 * e + 2 * i - h - k) % 7;
      const m = Math.floor((a + 11 * h + 22 * l) / 451);
      const month = Math.floor((h + l - 7 * m + 114) / 31);
      const day = ((h + l - 7 * m + 114) % 31) + 1;
      return new Date(year, month - 1, day);
    }
    function addDays(date, days) { const d = new Date(date); d.setDate(d.getDate() + days); return d; }
    function localDateKey(date) {
      return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
    }
    function luxembourgHolidays(year) {
      const easter = easterSunday(year);
      return [
        { date: new Date(year, 0, 1), name: 'New Year’s Day' },
        { date: new Date(year, 4, 1), name: 'Labour Day' },
        { date: new Date(year, 4, 9), name: 'Europe Day' },
        { date: addDays(easter, 1), name: 'Easter Monday' },
        { date: addDays(easter, 39), name: 'Ascension Day' },
        { date: addDays(easter, 50), name: 'Whit Monday' },
        { date: new Date(year, 5, 23), name: 'National Day' },
        { date: new Date(year, 7, 15), name: 'Assumption Day' },
        { date: new Date(year, 10, 1), name: 'All Saints’ Day' },
        { date: new Date(year, 11, 25), name: 'Christmas Day' },
        { date: new Date(year, 11, 26), name: 'St Stephen’s Day' }
      ].sort((a, b) => a.date - b.date);
    }
    function nextMonday(date = new Date()) {
      const d = new Date(date); d.setHours(0,0,0,0);
      const day = d.getDay();
      const add = day === 1 ? 7 : (8 - day) % 7 || 7;
      d.setDate(d.getDate() + add);
      return d;
    }
    function fmt(d, options) { return new Intl.DateTimeFormat('en-GB', options).format(d); }
    function luxembourgDateParts(date = new Date()) {
      const parts = new Intl.DateTimeFormat('en-GB', {
        timeZone: 'Europe/Luxembourg', weekday: 'short', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false
      }).formatToParts(date).reduce((result, part) => { result[part.type] = part.value; return result; }, {});
      return { ...parts, weekdayNumber: ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].indexOf(parts.weekday) };
    }
    function isRegistrationOpen() {
      const now = luxembourgDateParts();
      if (Number(now.year) < START_YEAR || Number(now.year) > END_YEAR) return false;
      const minutes = Number(now.hour) * 60 + Number(now.minute);
      return (now.weekdayNumber === 4 && minutes >= 9 * 60) || (now.weekdayNumber === 5 && minutes < 12 * 60);
    }
    function updateClock() {
      const now = new Date();
      document.getElementById('clock').innerHTML = `<span>${fmt(now, { weekday:'short', day:'2-digit', month:'short', hour:'2-digit', minute:'2-digit', timeZone:'Europe/Luxembourg' })}</span><span class="clock-zone"> · Luxembourg</span>`;
    }
    function setWeek() {
      const monday = nextMonday();
      const friday = new Date(monday); friday.setDate(monday.getDate() + 4);
      document.getElementById('weekLabel').textContent = `${fmt(monday,{day:'2-digit',month:'short'})}–${fmt(friday,{day:'2-digit',month:'short'})}`;
      document.getElementById('weekYear').textContent = monday.getFullYear();
      const holidays = luxembourgHolidays(monday.getFullYear()).filter(item => item.date >= monday && item.date <= friday);
      const holidayBox = document.getElementById('weekHoliday');
      holidayBox.textContent = holidays.length ? `Holiday in this week: ${holidays.map(item => `${item.name} (${fmt(item.date, { weekday: 'short', day: '2-digit', month: 'short' })})`).join(' · ')}` : '';
      holidayBox.classList.toggle('show', holidays.length > 0);
    }
    function isWithinSupportedPeriod(date) {
      return date.getFullYear() >= START_YEAR && date.getFullYear() <= END_YEAR;
    }
    function renderHolidays() {
      const list = document.getElementById('holidayList');
      const year = Number(state.selectedHolidayYear);
      const holidays = luxembourgHolidays(year);
      list.innerHTML = holidays.map(item => {
        const day = fmt(item.date, { weekday: 'short' });
        const date = fmt(item.date, { day: '2-digit', month: 'short' });
        return `<div class="holiday-row"><span><b>${item.name}</b></span><span>${day}, ${date}</span></div>`;
      }).join('');
      document.getElementById('ledgerTitle').textContent = `${year} usage ledger`;
    }
    function setupHolidayYears() {
      const select = document.getElementById('holidayYear');
      const current = Math.min(END_YEAR, Math.max(START_YEAR, new Date().getFullYear()));
      state.selectedHolidayYear = current;
      select.innerHTML = Array.from({ length: END_YEAR - START_YEAR + 1 }, (_, i) => `<option value="${START_YEAR + i}">${START_YEAR + i}</option>`).join('');
      select.value = String(current);
      select.addEventListener('change', e => { state.selectedHolidayYear = Number(e.target.value); renderHolidays(); renderLedger(); });
    }
    function renderLedger() {
      const box = document.getElementById('ledger');
      const year = Number(state.selectedHolidayYear);
      const usage = state.usageByYear[year] || Object.fromEntries(people.map(name => [name, 0]));
      const awards = state.awardsByYear[year] || {};
      box.innerHTML = people.map(name => {
        const count = usage[name] || 0;
        const pct = Math.min(100, count / ANNUAL_MAX * 100);
        return `<div class="ledger-row">
          <div><div class="ledger-person">${name}</div><div class="quota"><div class="quota-bar" style="width:${pct}%"></div></div></div>
          <div class="ledger-meta">
            <div class="ledger-cell"><span class="ledger-label">last week</span><span class="ledger-value">${awards[name] || '—'}</span></div>
            <div class="ledger-cell"><span class="ledger-label">used</span><span class="ledger-value">${count} / ${ANNUAL_MAX}</span></div>
          </div>
        </div>`;
      }).join('');
    }
    function renderSavedCalendar() {
      const grid = document.getElementById('savedCalendarGrid');
      const caption = document.getElementById('savedCalendarCaption');
      const status = document.getElementById('savedCalendarStatus');
      const waitlistText = document.getElementById('waitlistText');
      const monday = nextMonday();
      const days = Array.from({ length: 5 }, (_, index) => addDays(monday, index));
      // The server returns candidates oldest booking first, which is the allocation order.
      const ranked = [...state.registrations];
      const saved = state.allocations.length
        ? [...state.allocations].sort((a, b) => a.slot - b.slot).map(item => item.member)
        : ranked.slice(0, SPACES);
      const waiting = state.allocations.length ? ranked.filter(name => !saved.includes(name)) : ranked.slice(SPACES);
      const selectedYear = monday.getFullYear();
      const weekText = `${fmt(monday, { day: '2-digit', month: 'short' })}–${fmt(addDays(monday, 4), { day: '2-digit', month: 'short' })} ${selectedYear}`;
      caption.textContent = saved.length ? `Confirmed allocation for ${weekText}.` : `No confirmed allocation yet for ${weekText}.`;
      status.textContent = `${saved.length} / ${SPACES} spaces saved`;
      status.className = `status mono ${saved.length >= SPACES ? 'status-open' : 'status-closed'}`;
      waitlistText.textContent = waiting.length ? `Waiting list: ${waiting.join(' · ')}` : '';
      const headers = ['<div class="day-head corner">Space</div>', ...days.map(day => `<div class="day-head">${fmt(day, { weekday: 'short' })}<br>${fmt(day, { day: '2-digit', month: '2-digit' })}</div>`)].join('');
      const rows = Array.from({ length: SPACES }, (_, index) => {
        const name = saved[index];
        // The first cell of each row carries .space-row: on narrow screens it is the only
        // cell shown, because the five weekday columns just repeat the same name.
        return `<div class="space-row"><span class="space-label">Space 0${index + 1}</span><span class="saved-name ${name ? '' : 'saved-empty'}">${name || 'Available'}</span><span class="space-week mono">${weekText} · Mon–Fri</span></div>${days.map(() => `<div><span class="saved-name ${name ? '' : 'saved-empty'}">${name || '—'}</span></div>`).join('')}`;
      }).join('');
      grid.innerHTML = headers + rows;
    }
    // Mirrors parking_month_weeks() in PHP: a Monday–Friday week belongs to the month
    // holding its Wednesday, so a week that straddles two months counts in one of them only.
    function monthWeeks(year, monthIndex) {
      const first = new Date(year, monthIndex, 1);
      const monday = new Date(first);
      const firstDay = monday.getDay();
      monday.setDate(first.getDate() - (firstDay === 0 ? 6 : firstDay - 1) - 7);
      const weeks = [];
      for (let i = 0, cursor = monday; i < 8; i++, cursor = addDays(cursor, 7)) {
        const wednesday = addDays(cursor, 2);
        if (wednesday.getFullYear() === year && wednesday.getMonth() === monthIndex) weeks.push(new Date(cursor));
      }
      return weeks;
    }
    function getNextPlanningMonth() {
      const now = new Date();
      const next = new Date(now.getFullYear(), now.getMonth() + 1, 1);
      return { year: next.getFullYear(), monthIndex: next.getMonth(), value: `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, '0')}` };
    }
    function setupPlannerUsers() {
      const select = document.getElementById('plannerUser');
      select.innerHTML = people.map(name => `<option value="${name}">${name}</option>`).join('');
      select.value = state.currentUser;
      select.addEventListener('change', event => { state.currentUser = event.target.value; renderPlanner(); });
    }
    function renderPlanner() {
      const grid = document.getElementById('plannerGrid');
      const nextMonth = getNextPlanningMonth();
      state.selectedPlannerMonth = nextMonth.value;
      const weeks = monthWeeks(nextMonth.year, nextMonth.monthIndex);
      const active = state.currentUser;
      const activePlans = state.monthlyPlans[active] || {};
      const monthlyLimit = MONTHLY_MAX;
      // Counted by the server, so a week taken through the weekly registration also
      // fills part of the monthly quota.
      const activePlanCount = (state.planMonthUsage || {})[active] ?? Object.keys(activePlans).length;
      // How many of the week's spaces are already claimed, across every member.
      const takenPerWeek = {};
      people.forEach(name => Object.keys(state.monthlyPlans[name] || {}).forEach(week => { takenPerWeek[week] = (takenPerWeek[week] || 0) + 1; }));
      const monthLabel = fmt(new Date(nextMonth.year, nextMonth.monthIndex, 1), { month: 'long', year: 'numeric' });
      document.querySelector('.month-planner-caption').textContent = `Only ${monthLabel} is open for planning. Every week has ${SPACES} spaces handed out first come, first served; once both are taken the week closes. Select a name to add weeks; any colleague may cancel an existing plan.`;
      document.getElementById('plannerLimit').innerHTML = `<strong>${active}:</strong> ${monthlyLimit} week${monthlyLimit === 1 ? '' : 's'} per person in ${monthLabel} — the same quota for everyone, and weeks taken through the Thursday–Friday registration count towards it. ${activePlanCount} of ${monthlyLimit} used.`;
      const header = ['<div class="planner-head-cell planner-corner">Person / week</div>', ...weeks.map(week => {
        const taken = takenPerWeek[localDateKey(week)] || 0;
        return `<div class="planner-head-cell">${fmt(week, { day: '2-digit', month: 'short' })}<br>${fmt(addDays(week, 4), { day: '2-digit', month: 'short' })}<br><span class="planner-head-seats ${taken >= SPACES ? 'is-full' : ''}">${taken}/${SPACES}</span></div>`;
      })].join('');
      const rows = people.map(name => {
        const isActive = name === active;
        const plans = state.monthlyPlans[name] || {};
        const personCell = `<div><span class="planner-person ${isActive ? 'active' : ''}">${name}${isActive ? ' · selected' : ''}</span></div>`;
        const weekCells = weeks.map(week => {
          const key = localDateKey(week);
          const holiday = luxembourgHolidays(week.getFullYear()).some(item => item.date >= week && item.date <= addDays(week, 4));
          const planned = Boolean(plans[key]);
          const canChange = planned || isActive;
          const limitReached = !planned && isActive && activePlanCount >= monthlyLimit;
          const weekFull = !planned && (takenPerWeek[key] || 0) >= SPACES;
          const disabled = !canChange || limitReached || weekFull;
          const label = planned ? 'Cancel' : weekFull ? 'Full' : limitReached ? 'Limit' : holiday ? 'Holiday' : 'Plan';
          const seats = `${takenPerWeek[key] || 0} of ${SPACES} spaces taken`;
          return `<div><button class="plan-cell ${planned ? 'selected' : ''} ${holiday ? 'holiday-plan' : ''} ${limitReached || weekFull ? 'locked' : ''}" data-week="${key}" data-person="${name}" ${disabled ? 'disabled' : ''} aria-label="${name}, week of ${fmt(week, { day: '2-digit', month: 'long', year: 'numeric' })}, ${planned ? 'planned' : 'not planned'}, ${seats}" title="${seats}">${label}</button></div>`;
        }).join('');
        return personCell + weekCells;
      }).join('');
      grid.innerHTML = header + rows;

      // Narrow screens get one card per week instead of the people x weeks matrix,
      // which would otherwise need ~660px of horizontal scrolling to use.
      document.getElementById('plannerCards').innerHTML = weeks.map(week => {
        const key = localDateKey(week);
        const taken = takenPerWeek[key] || 0;
        const holders = people.filter(name => (state.monthlyPlans[name] || {})[key]);
        const holiday = luxembourgHolidays(week.getFullYear()).some(item => item.date >= week && item.date <= addDays(week, 4));
        const planned = holders.includes(active);
        const limitReached = !planned && activePlanCount >= monthlyLimit;
        const weekFull = !planned && taken >= SPACES;
        const label = planned ? `Cancel ${active}’s plan` : weekFull ? 'Week full' : limitReached ? `${active} has reached the monthly limit` : `Plan this week as ${active}`;
        const range = `${fmt(week, { day: '2-digit', month: 'short' })} – ${fmt(addDays(week, 4), { day: '2-digit', month: 'short' })}`;
        return `<div class="week-card ${weekFull ? 'is-full' : ''} ${planned ? 'is-mine' : ''}">
          <div class="week-card-top">
            <div>
              <div class="week-card-date">${range}</div>
              ${holiday ? '<span class="week-card-holiday">Public holiday</span>' : ''}
            </div>
            <span class="planner-head-seats ${taken >= SPACES ? 'is-full' : ''}">${taken}/${SPACES}</span>
          </div>
          <div class="week-card-who">${holders.length ? holders.map(n => `<b>${n}</b>`).join(' · ') : 'Nobody booked yet'}</div>
          <button class="plan-cell ${planned ? 'selected' : ''} ${limitReached || weekFull ? 'locked' : ''}" data-week="${key}" data-person="${active}" ${(limitReached || weekFull) ? 'disabled' : ''}>${label}</button>
        </div>`;
      }).join('');

      document.querySelectorAll('#plannerGrid .plan-cell:not(:disabled), #plannerCards .plan-cell:not(:disabled)').forEach(button => button.addEventListener('click', () => {
        const key = button.dataset.week;
        const name = button.dataset.person;
        const plans = state.monthlyPlans[name] || {};
        const planned = !plans[key];
        parkingPost('save_plan', { member: name, week_start: key, planned: planned ? '1' : '0' })
          .then(data => { applyServerState(data.state); render(); })
          .catch(error => { const notice = document.getElementById('plannerNotice'); notice.textContent = error.message; notice.classList.add('show'); setTimeout(() => notice.classList.remove('show'), 4500); });
      }));
      document.getElementById('savePlanBtn').disabled = Object.keys(activePlans).length === 0;
    }
    function renderPeople() {
      const open = state.currentOpen;
      const box = document.getElementById('people');
      const held = state.plannedNextWeek || [];
      const full = state.registrations.length >= SPACES;
      box.innerHTML = people.map(name => {
        const selected = state.registrations.includes(name);
        const year = Number(state.selectedHolidayYear);
        const usage = state.usageByYear[year] || {};
        const count = usage[name] || 0;
        const atCap = count >= ANNUAL_MAX;
        // A monthly plan already holds the space, and the last free space cannot be over-subscribed.
        const planLocked = held.includes(name);
        const noSpaceLeft = !selected && full;
        // The two weeks a month are shared with the planner, so someone who already
        // booked both cannot pick up next week here either.
        const monthUsed = (state.weekMonthUsage || {})[name] || 0;
        const atMonthCap = !selected && !planLocked && monthUsed >= MONTHLY_MAX;
        const disabled = !open || atCap || planLocked || noSpaceLeft || atMonthCap;
        const note = planLocked ? ' · planned' : atCap ? ' · cap' : atMonthCap ? ` · ${MONTHLY_MAX} this month` : noSpaceLeft ? ' · week full' : '';
        return `<button class="person ${selected ? 'selected':''}" data-name="${name}" ${disabled ? 'disabled':''}><span class="person-name">${name}</span><span class="person-count">${count} week${count === 1 ? '' : 's'} used${note}</span></button>`;
      }).join('');
      box.querySelectorAll('.person').forEach(btn => btn.addEventListener('click', () => {
        const name = btn.dataset.name;
        if (state.registrations.includes(name)) state.registrations = state.registrations.filter(x => x !== name);
        else if (state.registrations.length < SPACES) state.registrations = [...state.registrations, name];
        render();
      }));
    }
    function render() {
      const open = state.openPreview || isRegistrationOpen();
      state.currentOpen = open;
      const status = document.getElementById('windowStatus');
      status.className = `status ${open ? 'status-open' : 'status-closed'}`;
      status.innerHTML = `<i class="status-dot"></i><span>${open ? 'Registration open' : 'Registration closed'}</span>`;
      document.getElementById('registerHelp').textContent = open ? `Select your name, then save. Only ${SPACES} spaces exist and they go first come, first served, so the week closes once both are taken. Each person can book ${MONTHLY_MAX} weeks a month. Registration locks at Friday 12:00.` : 'Registration is currently closed. It opens automatically during the Thursday–Friday window.';
      document.getElementById('registrationCount').textContent = `${state.registrations.length} / ${SPACES}`;
      document.getElementById('registerBtn').disabled = !open || state.registrations.length === 0;
      document.getElementById('toggleWindow').textContent = open ? 'Close preview window' : 'Preview open window';
      const allocationBySlot = Object.fromEntries((state.allocations || []).map(item => [item.slot, item.member]));
      [['fobA', 1], ['fobB', 2]].forEach(([id, slot]) => {
        const el = document.getElementById(id);
        const member = allocationBySlot[slot];
        el.textContent = member || 'Not allocated yet';
        el.classList.toggle('is-empty', !member);
      });
      renderPeople(); renderLedger(); renderHolidays(); renderSavedCalendar(); renderPlanner(); setWeek();
    }
    document.getElementById('toggleWindow').addEventListener('click', () => { state.openPreview = !state.openPreview; render(); });
    document.getElementById('savePlanBtn').addEventListener('click', () => {
      const planned = Object.keys(state.monthlyPlans[state.currentUser] || {}).length;
      const notice = document.getElementById('plannerNotice');
      notice.textContent = `${state.currentUser}’s monthly plan is saved for ${planned} week${planned === 1 ? '' : 's'}. Weekly allocation will still be confirmed during the normal registration window.`;
      notice.classList.add('show');
      setTimeout(() => notice.classList.remove('show'), 4500);
    });
    document.getElementById('registerBtn').addEventListener('click', async () => {
      const notice = document.getElementById('notice');
      try {
        const chosen = state.registrations.join(' · ');
        // Only a genuinely closed window is a dry run; inside the real window this always saves.
        const data = await parkingPost('save_weekly_registration', { names: JSON.stringify(state.registrations), preview: (state.openPreview && !isRegistrationOpen()) ? '1' : '0' });
        applyServerState(data.state);
        notice.textContent = data.preview
          ? `Preview only — nothing was saved. ${chosen} would fit the ${SPACES} spaces. Real registration opens Thursday 09:00.`
          : `Saved: ${chosen} registered for next week.`;
        notice.classList.add('show'); render();
      } catch (error) { notice.textContent = error.message; notice.classList.add('show'); }
      setTimeout(() => notice.classList.remove('show'), 4200);
    });
    setupHolidayYears();
    setupPlannerUsers();
    updateClock();
    render();
    // Keep every open browser in sync. On Friday after 12:00 this call also
    // triggers the server-side allocation; on a new week/month it loads the new period.
    setInterval(() => { updateClock(); refreshParkingState(); }, 60000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshParkingState(); });
  </script>
</body>
</html>