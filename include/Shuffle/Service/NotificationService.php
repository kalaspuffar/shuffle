<?php
declare(strict_types=1);

namespace Shuffle\Service;

use Shuffle\Core\Lang;
use Shuffle\Core\Mailer;
use Shuffle\Model\Card;
use Shuffle\Model\Notification;
use Shuffle\Model\User;

/**
 * Notification business logic service.
 *
 * Handles notification CRUD and triggers for assignment and comment events.
 * Notifications are created for users when they are assigned to a card
 * or when someone comments on a card they are assigned to.
 *
 * v1.18 (NOTIF-06, spec §5.26): each trigger also fans out a per-recipient
 * EMAIL — but ONLY to users whose `users.email_notifications` opt-in is ON
 * (default OFF). The in-app row is always created first and the email step
 * is fully non-fatal: an SMTP failure is logged and swallowed, never
 * propagating into the caller (CardService / CommentService).
 * Delivery is enabled by setter injection (`setUserModel` + `setMailer`) —
 * callers that build the service without them get exactly the pre-v1.18
 * behavior (tests, CLI harnesses).
 */
class NotificationService
{
    private Notification $notificationModel;
    private Card $cardModel;
    private Lang $lang;

    // v1.18 NOTIF-06 delivery (optional — null = no email, legacy behavior)
    private ?User $userModel = null;
    private ?Mailer $mailer = null;
    private string $appUrl = '';

    /**
     * @param Notification $notificationModel Notification data access instance
     * @param Card         $cardModel         Card data access instance (for assignment lookups)
     * @param Lang         $lang              Internationalization service
     */
    public function __construct(Notification $notificationModel, Card $cardModel, Lang $lang)
    {
        $this->notificationModel = $notificationModel;
        $this->cardModel = $cardModel;
        $this->lang = $lang;
    }

    // v1.23 NOTIF-05 delivery (optional — null = no due-date reminders at all;
    // e.g. standalone test harnesses that do not wire the DB directly).
    private ?\Shuffle\Core\Database $database = null;

    /**
     * Injects the raw Database (for the `due_reminders` claim INSERT).
     * Required for scanDueReminders(); not required for the v1.18 notify* path.
     */
    public function setDatabase(\Shuffle\Core\Database $database): void
    {
        $this->database = $database;
    }

    /**
     * Injects the User model (recipient email + opt-in lookup).
     * Required alongside setMailer() for email delivery.
     */
    public function setUserModel(User $userModel): void
    {
        $this->userModel = $userModel;
    }

    /**
     * Enables email delivery (NOTIF-06): the Mailer + the app's public base
     * URL (for the deep links). Call setUserModel() first.
     *
     * @param Mailer   $mailer
     * @param string   $appUrl Base URL without trailing slash, e.g. "http://shuffle.example.com"
     */
    public function setMailer(Mailer $mailer, string $appUrl): void
    {
        $this->mailer = $mailer;
        $this->appUrl = rtrim($appUrl, '/');
    }

    /**
     * Returns notifications for a user, enriched with the card's board_id
     * (for the NOTIF-09 deep link /board.php?id={boardId}&card={cardId}).
     *
     * @param int  $userId     User ID
     * @param bool $unreadOnly Filter to unread only
     * @param int  $limit      Maximum results
     * @return array Array of notifications, each row with an added `board_id`
     *               key (int or null if the card / lane / board was removed).
     */
    public function getNotificationsForUser(int $userId, bool $unreadOnly = false, int $limit = 50): array
    {
        $rows = $this->notificationModel->findByUser($userId, $unreadOnly, $limit);
        if ($rows === []) {
            return $rows;
        }

        $cardIds = [];
        foreach ($rows as $row) {
            $cardIds[(int) $row['reference_id']] = true;
        }

        $boardByCard = $this->cardModel->boardsForCards(array_keys($cardIds));

        foreach ($rows as &$row) {
            $cardId = (int) $row['reference_id'];
            $row['board_id'] = isset($boardByCard[$cardId]) ? (int) $boardByCard[$cardId] : null;
        }
        unset($row);

        return $rows;
    }

    /**
     * Returns the unread notification count for a user.
     *
     * @param int $userId User ID
     * @return int Unread count
     */
    public function getUnreadCount(int $userId): int
    {
        return $this->notificationModel->countUnread($userId);
    }

    /**
     * Marks a single notification as read.
     *
     * Verifies ownership before marking.
     *
     * @param int $notificationId Notification ID
     * @param int $userId         Current user ID (ownership check)
     * @throws \RuntimeException If notification not found or not owned by user
     */
    public function markAsRead(int $notificationId, int $userId): void
    {
        $notification = $this->notificationModel->findById($notificationId);
        if ($notification === null || (int) $notification['user_id'] !== $userId) {
            throw new \RuntimeException('Notification not found');
        }

        $this->notificationModel->markRead($notificationId);
    }

    /**
     * Marks all notifications as read for a user.
     *
     * @param int $userId User ID
     */
    public function markAllAsRead(int $userId): void
    {
        $this->notificationModel->markAllRead($userId);
    }

    /**
     * Deletes a notification.
     *
     * Verifies ownership before deleting.
     *
     * @param int $notificationId Notification ID
     * @param int $userId         Current user ID (ownership check)
     * @throws \RuntimeException If notification not found or not owned by user
     */
    public function deleteNotification(int $notificationId, int $userId): void
    {
        $notification = $this->notificationModel->findById($notificationId);
        if ($notification === null || (int) $notification['user_id'] !== $userId) {
            throw new \RuntimeException('Notification not found');
        }

        $this->notificationModel->delete($notificationId);
    }

    /**
     * Creates assignment notifications for newly assigned users.
     *
     * Called when users are assigned to a card. Notifies each assigned user
     * except the user who performed the action (the assigner).
     *
     * @param int    $cardId        Card ID
     * @param array  $assignedUserIds Array of newly assigned user IDs
     * @param int    $assignerUserId  User ID of the person making the assignment
     * @param string $cardTitle      Card title for the notification message
     */
    public function notifyAssignment(int $cardId, array $assignedUserIds, int $assignerUserId, string $cardTitle): void
    {
        $notifiedIds = [];
        foreach ($assignedUserIds as $userId) {
            $userId = (int) $userId;

            // Don't notify the user who performed the assignment
            if ($userId === $assignerUserId) {
                continue;
            }

            $truncatedTitle = mb_substr($cardTitle, 0, 100, 'UTF-8');
            $message = $this->lang->get('notification.assigned_to', [$truncatedTitle]);

            $this->notificationModel->create([
                'user_id'      => $userId,
                'type'         => 'assignment',
                'reference_id' => $cardId,
                'message'      => $message,
            ]);
            $notifiedIds[] = $userId;
        }

        // v1.18 NOTIF-06: mirror to opted-in recipients (non-fatal).
        // Gate on a non-empty recipient list — when every candidate was the
        // assigner (self-assign) the loop never defines $message, and the
        // email has no recipients anyway (emitEmails early-returns too).
        if ($notifiedIds !== []) {
            $emailMessage = $this->lang->get('notification.assigned_to', [mb_substr($cardTitle, 0, 100, 'UTF-8')]);
            $this->emitEmails($notifiedIds, (int) $cardId, $emailMessage, null);
        }
    }

    /**
     * Creates comment notifications for all users assigned to a card.
     *
     * Called when a comment is posted. Notifies all assigned users
     * except the comment author.
     *
     * v1.8 (NOTIF-07): also creates a creator-scope notification for the
     * card's author — unless the author is the creator, or the creator
     * is already in the assignee set (in which case they get ONE
     * 'comment'-type row, not a duplicate). All rows produced by this
     * call share the new comment's id (`comment_id`) so the bell panel
     * can deep-link to it (NOTIF-09).
     *
     * @param int     $cardId        Card ID
     * @param int     $authorUserId  Comment author's user ID
     * @param string  $cardTitle     Card title for the notification message
     * @param string  $authorName    Comment author's display name
     * @param int|null $commentId    New comment's id (NOTIF-09 anchor)
     * @param array|null $card       Full card row (provides created_by for NOTIF-07);
     *                               read by reference only — no extra query
     */
    public function notifyComment(int $cardId, int $authorUserId, string $cardTitle, string $authorName, ?int $commentId = null, ?array $card = null): void
    {
        $assignedUsers = $this->cardModel->getAssignedUsers($cardId);
        $assignedIds = [];
        $commentRecipients = [];

        foreach ($assignedUsers as $user) {
            $userId = (int) $user['id'];
            $assignedIds[] = $userId;

            // Don't notify the comment author
            if ($userId === $authorUserId) {
                continue;
            }

            $truncatedTitle = mb_substr($cardTitle, 0, 100, 'UTF-8');
            $message = $this->lang->get('notification.commented_on', [$authorName, $truncatedTitle]);

            $this->notificationModel->create([
                'user_id'      => $userId,
                'type'         => 'comment',
                'reference_id' => $cardId,
                'comment_id'   => $commentId,
                'message'      => $message,
            ]);
            $commentRecipients[] = $userId;
        }

        // NOTIF-07: the card's creator also gets notified — but only if they
        // aren't the commenter AND aren't already on the assignee list (to
        // avoid double-notifying).
        $creatorId = ($card !== null && isset($card['created_by'])) ? (int) $card['created_by'] : null;
        if ($creatorId !== null
            && $creatorId !== $authorUserId
            && in_array($creatorId, $assignedIds, true) === false) {
            $truncatedTitle = mb_substr($cardTitle, 0, 100, 'UTF-8');
            $message = $this->lang->get('notification.creator_commented_on', [$authorName, $truncatedTitle]);
            $this->notificationModel->create([
                'user_id'      => $creatorId,
                'type'         => 'creator',
                'reference_id' => $cardId,
                'comment_id'   => $commentId,
                'message'      => $message,
            ]);
            $commentRecipients[] = $creatorId;
        }

        // v1.18 NOTIF-06: mirror to opted-in recipients (non-fatal).
        // Both recipient classes (assignees + creator) get the same
        // actor-centric line — "{actor} commented on ''{card}''" — which
        // satisfies the spec's "only the actor's display name appears" rule
        // with a single message shape (NOTIF-07's creator template differs
        // in wording but both are correct email text; one shape is simpler
        // and loses nothing).
        if ($commentRecipients !== []) {
            $emailMessage = $this->lang->get('notification.commented_on', [$authorName, mb_substr($cardTitle, 0, 100, 'UTF-8')]);
            $this->emitEmails($commentRecipients, (int) $cardId, $emailMessage, $commentId);
        }
    }

    /**
     * Creates a creator-scope notification that a card moved into a Done
     * lane (NOTIF-08 — "your card shipped").
     *
     * No-op when the actor IS the creator or the creator is unknown.
     * Uses the same `\bdone\b` (case-insensitive, word-bounded) matcher
     * as the priority digest (PRIO-13) — "Done-ness" never matches,
     * "Done — v2" does.
     *
     * @param int    $cardId        Card ID
     * @param int    $actorUserId   Acting user's ID (mover)
     * @param string $actorName     Acting user's display name
     * @param string $toLaneTitle   Lane the card landed in (Done-lane)
     * @param array|null $card      Full card row (for created_by + title)
     */
    public function notifyCreatorDoneMove(int $cardId, int $actorUserId, string $actorName, string $toLaneTitle, ?array $card = null): void
    {
        if ($card === null) {
            return;
        }

        $creatorId = isset($card['created_by']) ? (int) $card['created_by'] : null;
        if ($creatorId === null || $creatorId === $actorUserId) {
            return;
        }

        $truncatedTitle = mb_substr((string) ($card['title'] ?? ''), 0, 100, 'UTF-8');
        $message = $this->lang->get('notification.creator_done', [$actorName, $truncatedTitle, $toLaneTitle]);
        $this->notificationModel->create([
            'user_id'      => $creatorId,
            'type'         => 'creator',
            'reference_id' => $cardId,
            'comment_id'   => null,
            'message'      => $message,
        ]);

        // v1.18 NOTIF-06: mirror to the opted-in creator (non-fatal).
        $this->emitEmails([$creatorId], (int) $cardId, $message, null);
    }

    // ------------------------------------------------------------------
    // v1.18 NOTIF-06 — email delivery (spec §5.26)
    // ------------------------------------------------------------------

    /**
     * Fan-out email for a notification event (NOTIF-06).
     *
     * CONTRACT (spec §5.26, non-negotiable):
     *  - The in-app row(s) are ALWAYS created by the caller BEFORE this
     *    runs — a failure here must never suppress them.
     *  - Recipients = user_ids × `email_notifications = 1` (default OFF).
     *  - ONE batch `User::emailPrefsByIds()` query (no N+1).
     *  - ONE `Mailer::send()` per opted-in recipient (the Mailer speaks a
     *    single RCPT per transaction, matching the invite flow).
     *  - The WHOLE step is non-fatal: any \Throwable is logged and
     *    swallowed; it NEVER propagates into CardService / CommentService.
     *  - Delivery disabled (no injection, empty recipient set, no appUrl)
     *    → returns without touching Mailer.
     *
     * @param array<int> $recipientUserIds Users who got the in-app row (actor already excluded)
     * @param int        $cardId
     * @param string     $message          The in-app message text (subject + body line)
     * @param int|null   $commentId        Comment anchor → NOTIF-09 deep link (Comments tab + highlight)
     */
    private function emitEmails(array $recipientUserIds, int $cardId, string $message, ?int $commentId = null): void
    {
        if ($this->mailer === null || $this->userModel === null || $this->appUrl === '') {
            return; // delivery not configured (CLI / legacy / tests)
        }

        $recipientUserIds = array_values(array_unique(array_filter(array_map('intval', $recipientUserIds), fn (int $v): bool => $v > 0)));
        if ($recipientUserIds === []) {
            return;
        }

        try {
            $prefs = $this->userModel->emailPrefsByIds($recipientUserIds);

            $optedIn = [];
            foreach ($prefs as $id => $row) {
                if ((int) $row['email_notifications'] === 1) {
                    $optedIn[(int) $id] = $row['email'];
                }
            }
            if ($optedIn === []) {
                return; // nobody opted in — hot path costs exactly one indexed IN-query
            }

            $link = $this->cardLink($cardId, $commentId);
            if ($link === null) {
                return;
            }

            $subject = $message;
            $html = $this->emailBody($message, $link);
            $text = trim($message . "\n\n" . $link);

            foreach ($optedIn as $email) {
                $this->mailer->send($email, $subject, $html, $text);
            }
        } catch (\Throwable $e) {
            error_log('NOTIF-06 email for card ' . $cardId . ' failed: ' . $e->getMessage());
            // Non-fatal by contract: the underlying mutation stands.
        }
    }

    /**
     * Builds the NOTIF-09 deep link for a card, or null if the card's
     * board is gone (degrades to the board list instead of a dead id).
     *
     * Resolution is ONE `Card::boardsForCards()` call (batch helper, no N+1).
     */
    private function cardLink(int $cardId, ?int $commentId): ?string
    {
        $boardByCard = $this->cardModel->boardsForCards([$cardId]);
        $boardId = isset($boardByCard[$cardId]) ? (int) $boardByCard[$cardId] : 0;

        $link = $this->appUrl . '/board.php?id=' . $boardId . '&card=' . $cardId;
        if ($commentId !== null) {
            $link .= '&tab=comments&comment=' . (int) $commentId;   // NOTIF-09 comment landing
        } elseif ($boardId === 0) {
            // Card's board vanished: never serve a dead id=null — the board list.
            $link = $this->appUrl . '/boards.php';
        }

        return $link;
    }

    /**
     * Email body (HTML). Only the actor's display name (already embedded in
     * $message) appears — other users' addresses are never rendered.
     * $link null (test email) → no link line.
     */
    private function emailBody(string $message, ?string $link): string
    {
        $view = null;
        if ($link !== null) {
            $view = htmlspecialchars($this->lang->get('notification.email_view_card'), ENT_QUOTES, 'UTF-8');
        }
        $footer = htmlspecialchars($this->lang->get('notification.email_footer'), ENT_QUOTES, 'UTF-8');

        $body = '<html><body style="margin:0;padding:24px;background:#0D0D12;color:#E2E2EC;font-family:system-ui,sans-serif;font-size:15px;line-height:1.5;">';
        $body .= '<div style="max-width:480px;margin:0 auto;">';
        $body .= '<p style="margin:0 0 16px;">' . nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')) . '</p>';
        if ($view !== null && $link !== null) {
            $body .= '<p style="margin:0 0 20px;"><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '" style="color:#7AB4FF;text-decoration:none;">' . $view . ' &rarr;</a></p>';
        }
        $body .= '<hr style="border:none;border-top:1px solid #2A2A33;margin:16px 0;">';
        $body .= '<p style="margin:0;color:#8A8A99;font-size:12px;">' . $footer . '</p>';
        $body .= '</div></body></html>';

        return $body;
    }

    /**
     * Test email (NOTIF-06, spec §5.26): POST /v1/me/test-email.
     *
     * Sends a one-off confirmation to the actor's OWN address. BY DESIGN
     * this bypasses the `email_notifications` opt-in flag — the recipient
     * is the actor, and this is how a user verifies SMTP + their address
     * before flipping the opt-in.
     *
     * @param int $actorId
     * @throws \RuntimeException when the send fails (controller maps to 502)
     */
    public function sendTestEmail(int $actorId): void
    {
        if ($this->mailer === null || $this->userModel === null) {
            throw new \RuntimeException('Email delivery is not configured');
        }

        $user = $this->userModel->findById($actorId);
        if ($user === null) {
            throw new \RuntimeException('User not found');
        }

        $subject = $this->lang->get('notification.test_email_subject');
        $message = $this->lang->get('notification.test_email_body');

        $this->mailer->send(
            (string) $user['email'],
            $subject,
            $this->emailBody($message, null),
            $message
        );
    }

    /* ====================================================================
     * NOTIF-05 (v1.23 §5.30) — due-date reminders
     * ====================================================================
     * Scheduled one-shot (bin/due-reminder-scan.php, hourly). Fires a
     * per-user "remind N hours before" for every card whose due date is
     * approaching, at each assignee's own offset. One bell row per
     * (card, recipient), always; an email mirror for that recipient when
     * their NOTIF-06 opt-in is on (non-fatal). Once per card per
     * recipient, crash-safe: the due_reminders claim table is the single
     * dedupe truth — claim (INSERT IGNORE) BEFORE firing, so a crash
     * between the two biases to "at most one reminder, never two".
     *
     * Exclusions (spec §5.30): no due date, archived, completed lane
     * (LaneRules::isCompleteLane — shared with the priority digest), and
     * already past due (NOW() >= due_date + 1 day).
     */

    /**
     * Scans for due-date reminders that are due now and fires them.
     *
     * Returns the number of (card, recipient) reminders fired by this run.
     * Safe to call repeatedly and concurrently (the claim table serializes
     * firing per pair; a second run is a no-op for already-claimed pairs).
     *
     * Requires setDatabase() (claim INSERT). Bell rows are unconditional;
     * email fan-out additionally requires setUserModel() + setMailer().
     */
    public function scanDueReminders(): int
    {
        if ($this->database === null) {
            return 0; // delivery not wired (legacy / standalone harness)
        }

        // Candidate (card, assignee) pairs whose personal window has opened:
        //   NOW() >= due_date - due_remind_hours  (inside the user's window)
        //   NOW() <  due_date + 1 day             (not already past due)
        // The completed-lane exclusion is a regex predicate, so it is applied
        // in PHP below (LaneRules — the single source of truth) BEFORE the
        // claim, so a completed card never consumes a claim.
        $rows = $this->database->fetchAll(
            'SELECT c.id AS card_id, c.title AS card_title, c.due_date AS due_date,
                    l.title AS lane_title,
                    ca.user_id AS user_id,
                    u.due_remind_hours AS remind_hours
             FROM cards c
             JOIN lanes l              ON c.lane_id = l.id
             JOIN card_assignments ca  ON ca.card_id = c.id
             JOIN users u              ON u.id = ca.user_id
             WHERE c.due_date IS NOT NULL
               AND c.is_archived = 0
               AND u.due_remind_hours IS NOT NULL
               AND NOW() >= DATE_SUB(c.due_date, INTERVAL u.due_remind_hours HOUR)
               AND NOW() <  DATE_ADD(c.due_date, INTERVAL 1 DAY)
             ORDER BY c.due_date ASC, c.id ASC, u.id ASC',
            []
        );

        $fired = 0;
        $firedEmails = []; // list of {user_id, card_id, message} for opted-in recipients

        foreach ($rows as $row) {
            // Completed lanes are never reminded (LaneRules = digest parity).
            if (\Shuffle\Core\LaneRules::isCompleteLane((string) $row['lane_title'])) {
                continue;
            }

            $cardId  = (int) $row['card_id'];
            $userId  = (int) $row['user_id'];
            $dueDate = (string) $row['due_date'];
            $title   = mb_substr((string) $row['card_title'], 0, 100, 'UTF-8');
            $dueText = date('D, j M Y', strtotime($dueDate) ?: time());
            $message = $this->lang->get('notification.due_reminder', [$title, $dueText]);

            try {
                // CLAIM FIRST: one reminder per (card, user, due date),
                // crash-safe. execute() returns the affected-row count:
                // 1 = this run won the claim, 0 = already claimed (either
                // by an earlier scan or by the same card+user for the SAME
                // due date — a CHANGED due date is a different PK row and
                // re-arms the reminder, which is the desired behavior).
                $claimed = $this->database->execute(
                    'INSERT IGNORE INTO due_reminders (card_id, user_id, due_date) VALUES (?, ?, ?)',
                    [$cardId, $userId, $dueDate]
                );
                if ((int) $claimed === 0) {
                    continue; // already claimed
                }

                // BELL ROW (always, this run won the claim).
                $this->notificationModel->create([
                    'user_id'      => $userId,
                    'type'         => 'due',
                    'reference_id' => $cardId,
                    'comment_id'   => null,
                    'message'      => $message,
                ]);
                $fired++;

                // EMAIL (opt-in gated; NOTIF-06 contract) — batched after.
                $firedEmails[] = ['user_id' => $userId, 'card_id' => $cardId, 'message' => $message];
            } catch (\Throwable $e) {
                // A claim that cannot be honoured is logged, not fatal: the
                // scan continues to the next pair (NOTIF-06 non-fatal discipline).
                error_log('NOTIF-05 due reminder for card ' . $cardId . ' user ' . $userId . ' failed: ' . $e->getMessage());
            }
        }

        // Email fan-out for the fired pairs, opt-in gated, non-fatal.
        $this->emitDueEmails($firedEmails);

        return $fired;
    }

    /**
     * Mirrors fired due-reminder messages to opted-in mailboxes (NOTIF-06
     * contract: the body/link/footer are the existing NOTIF-06 helpers, so
     * the email is byte-identical in shape to the in-app row). Non-fatal —
     * a per-recipient SMTP failure is logged and never escapes.
     */
    private function emitDueEmails(array $fired): void
    {
        if ($fired === []) {
            return;
        }
        if ($this->mailer === null || $this->userModel === null || $this->appUrl === '') {
            return; // email delivery not configured (CLI / harness)
        }

        $userIds = [];
        foreach ($fired as $f) {
            $userIds[(int) $f['user_id']] = true;
        }
        try {
            $prefs = $this->userModel->emailPrefsByIds(array_keys($userIds));
        } catch (\Throwable $e) {
            error_log('NOTIF-05 due email prefetch failed: ' . $e->getMessage());
            return;
        }
        // Keep only opted-in recipients (id => email).
        $optedIn = [];
        foreach ($prefs as $id => $row) {
            if ((int) $row['email_notifications'] === 1 && (string) $row['email'] !== '') {
                $optedIn[(int) $id] = (string) $row['email'];
            }
        }
        if ($optedIn === []) {
            return; // nobody opted in — hot path costs exactly one IN-query
        }

        foreach ($fired as $f) {
            $userId = (int) $f['user_id'];
            if (!isset($optedIn[$userId])) {
                continue; // bell row already created; email is opt-in
            }
            $cardId  = (int) $f['card_id'];
            $message = (string) $f['message'];
            try {
                $link = $this->cardLink($cardId, null);
                if ($link === null) {
                    continue; // card's board vanished — degrade, don't send a dead link
                }
                $this->mailer->send($optedIn[$userId], $message, $this->emailBody($message, $link), trim($message . "\n\n" . $link));
            } catch (\Throwable $e) {
                // Non-fatal by contract: the bell row stands, the scan is done.
                error_log('NOTIF-05 due email for card ' . $cardId . ' to user ' . $userId . ' failed: ' . $e->getMessage());
            }
        }
    }
}
