<?php
/**
 * Post-Activation Welcome Page
 *
 * Shown to new users immediately after activating their account.
 * Redirects to /boards.php if the user already has accessible boards,
 * to avoid a stale welcome state on repeat visits.
 */

require_once dirname(__DIR__) . '/include/bootstrap.php';

// Require authentication — unauthenticated visitors are redirected to login
$currentUser = $auth->requireAuth();

// Redirect returning users who already have accessible boards
$boardModel   = new Shuffle\Model\Board($db);
$boardService = new Shuffle\Service\BoardService($boardModel);
$boards = $boardService->listBoards($currentUser, false);

if (!empty($boards)) {
    header('Location: /boards.php');
    exit;
}

$pageTitle   = $lang->get('welcome.title');
$currentPage = 'boards';
require ROOT_DIR . '/include/templates/header.php';
?>

<div class="welcome-page">
    <div class="welcome-card">
        <div class="welcome-header">
            <h1 class="welcome-title"><?= htmlspecialchars($lang->get('welcome.title'), ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="welcome-tagline"><?= htmlspecialchars($lang->get('welcome.message'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <a href="/boards.php" class="btn btn-primary btn-full">
            <?= htmlspecialchars($lang->get('welcome.go_to_boards'), ENT_QUOTES, 'UTF-8') ?>
        </a>
    </div>
</div>

<?php require ROOT_DIR . '/include/templates/footer.php'; ?>
