<?php
/**
 * Partial: Assignee avatar stack contents.
 *
 * Renders the visible avatar spans followed by an overflow badge for any
 * extras.  The outer container element is the caller's responsibility so
 * that different pages can attach their own classes and ARIA attributes.
 *
 * Required variables (set by caller before require):
 *   array $_avatarUsers  Full list of assigned users: [['id' => …, 'name' => …], …]
 *   int   $_avatarCap    Maximum visible avatars before the overflow badge appears
 *   Lang  $lang          Language instance (already in scope on every page)
 */
$_avatarVisible  = array_slice($_avatarUsers, 0, $_avatarCap);
$_avatarOverflow = array_slice($_avatarUsers, $_avatarCap);
$_avatarOverflowCount = count($_avatarOverflow);
?>
<?php foreach ($_avatarVisible as $_avatarUser): ?>
<span class="card-assignee-avatar"
    role="img"
    aria-label="<?= htmlspecialchars($_avatarUser['name'], ENT_QUOTES, 'UTF-8') ?>"
    title="<?= htmlspecialchars($_avatarUser['name'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(mb_strtoupper(mb_substr($_avatarUser['name'], 0, 1), 'UTF-8'), ENT_QUOTES, 'UTF-8') ?></span>
<?php endforeach; ?>
<?php if ($_avatarOverflowCount > 0): ?>
<?php
    $_avatarOverflowKey   = $_avatarOverflowCount === 1
        ? 'card.assignee_overflow_singular'
        : 'card.assignee_overflow_plural';
    $_avatarOverflowLabel = $lang->get($_avatarOverflowKey, [$_avatarOverflowCount]);
    $_avatarOverflowNames = implode(', ', array_column($_avatarOverflow, 'name'));
?>
<span class="card-assignee-avatar card-assignee-avatar-overflow"
    aria-label="<?= htmlspecialchars($_avatarOverflowLabel, ENT_QUOTES, 'UTF-8') ?>"
    title="<?= htmlspecialchars($_avatarOverflowNames, ENT_QUOTES, 'UTF-8') ?>">+<?= $_avatarOverflowCount ?></span>
<?php endif; ?>
