<?php

declare(strict_types=1);

namespace Shuffle\Core;

/**
 * Shared lane-title matchers — the single source of truth for "is this lane a
 * Done / Won't-fix / complete lane?" so every consumer (priority digest,
 * priority inbox, creator-Done notification, due-date reminder scan) agrees.
 *
 * v1.23 §5.30 promoted `isCompleteLane()` (and its constituents) out of
 * `PriorityService` (where they were private) so `NotificationService` reuses
 * the *exact same* predicate instead of re-deriving it. Case-insensitive,
 * anchored, word-bounded:
 *
 *   isDoneLane     — "Done" / "Done-ness" / "done — v2" match (hyphen IS a
 *                    word boundary); "In Progress" / "Wont fix" do not.
 *   isWontFixLane  — "Won't fix" / "Wont fix" / "won't fix (won't repro)"
 *                    match; "Will fix" / "Don't fix" do not.
 *   isCompleteLane — the union. A card on EITHER lane is out of the active
 *                    work (PRIO-09 inbox exclusion, PRIO-12 digest, NOTIF-08
 *                    creator-done, and NOTIF-05 due-reminder suppression).
 *
 * No state — pure title predicates, all `public static` so they can be called
 * without a service instance (and from `bin/` one-shot scripts).
 */
final class LaneRules
{
    private function __construct()
    {
        // No instances — purely a namespace for the static predicates.
    }

    /** "Done" / "Done-ness" / "done — v2". */
    public static function isDoneLane(string $title): bool
    {
        return preg_match('/\bdone\b/iu', trim($title)) === 1;
    }

    /** "Won't fix" / "Wont fix" / "won't fix (won't repro)". */
    public static function isWontFixLane(string $title): bool
    {
        return preg_match("/\bwon'?t fix\b/iu", trim($title)) === 1;
    }

    /** The union — a lane that marks the card as complete (v1.9 rule). */
    public static function isCompleteLane(string $title): bool
    {
        return self::isDoneLane($title) || self::isWontFixLane($title);
    }

    /** "In Progress" family (PRIO-04/09 inbox tier). */
    public static function isInProgressLane(string $title): bool
    {
        return preg_match('/\bin progress\b/iu', trim($title)) === 1;
    }

    /** "Inbox" family (PRIO-04/09 inbox tier). */
    public static function isInboxLane(string $title): bool
    {
        return preg_match('/\binbox\b/iu', trim($title)) === 1;
    }
}
