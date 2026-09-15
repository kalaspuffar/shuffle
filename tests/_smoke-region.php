<?php
// Smoke-render the shared board region renderer with a fake $board.
$board = [
    'lanes' => [
        [
            'id' => 1, 'title' => 'Inbox', 'icon' => '📥', 'position' => 1000,
            'cards' => [
                [
                    'id' => 7, 'title' => 'Test <card>', 'position' => 1000,
                    'due_date' => '2026-01-05', 'is_archived' => false,
                    'assigned_users' => [],
                    'comment_count' => 1,
                    'checklist_progress' => ['done'=>1,'total'=>2],
                    "attachment_count" => 0,
                    "labels" => [["id"=>1,"name"=>"Bug","color"=>"#FF0000"]],
                ],
                [
                    'id' => 8, 'title' => 'With assignees', 'position' => 2000,
                    'due_date' => null, 'is_archived' => false,
                    'assigned_users' => [['id'=>1,'name'=>'Alice'],['id'=>2,'name'=>'Bob']],
                    'comment_count' => 0,
                    'checklist_progress' => ['done'=>0,'total'=>0],
                    'attachment_count' => 1,
                    'labels' => [],
                ],
            ],
        ],
    ],
];
$canEdit = true;
$boardId = 3;
$lang = new class {
    public function get(string $key, array $params = []): string {
        $text = $key;
        foreach ($params as $i => $v) $text = str_replace('{'.$i.'}', (string)$v, $text);
        return $text;
    }
};
define('ROOT_DIR', dirname(__DIR__));
require __DIR__ . '/../include/templates/board-region.php';
echo "\n---RENDERED OK---\n";
