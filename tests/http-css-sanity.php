<?php
// CSS sanity check.
//
// Guards against the class of bug where a malformed comment fragment (a
// dangling "*/" with no matching "/*", or an unclosed "/*") makes the
// browser's CSS parser skip or truncate rules, silently breaking layout.
//
// History: 2026-09-16 (Daniel's double-pane report) — a stray closing "*/"
// in app.css swallowed the [hidden] display:none rule, so the description
// panes in the card modal co-existed. The fixes shipped: the comment was
// repaired and pane visibility moved to explicit class rules. This check
// keeps both directions from regressing:
//   * rule presence  — the key rules are actually in the served CSS.
//   * EOF comment    — the stylesheet is not truncated inside a comment.
//
// NOTE: a raw `/*`/`*/` count is unsound (a comment may legitimately embed
// a literal "/*" as text), so we scan with a small state machine instead.
//
// Run:   php tests/http-css-sanity.php
// Exit:  0 if all PASS, else 1.
require_once __DIR__ . '/../include/bootstrap.php';

$pass = 0; $fail = 0;
function ck($msg, $ok) {
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS  $msg\n"; }
    else     { $fail++; echo "FAIL  $msg\n"; }
}

// Fetch the served app.css (as the browser sees it) — the served bytes are
// the authoritative source for what the CSS parser receives.
$ch = curl_init('http://127.0.0.1/css/app.css');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
$served = (string) curl_exec($ch);
curl_close($ch);
ck('served app.css is non-empty', strlen($served) > 10000);

// Rules that MUST be present. Catches a rule that was deleted or made dead
// by a parse break.
$required = [
    ['[hidden]', 'display', 'none'],
    ['.card-detail-tabs', 'display', 'flex'],
    ['.description-wrap .form-textarea', 'display', 'none'],
    ['.description-wrap .description-preview', 'display', 'block'],
];

function rule_exists($css, $sel, $prop, $value) {
    $selRe = preg_quote($sel, '/');
    $re = '/' . $selRe . '\s*\{[^{}]*\b' . preg_quote($prop, '/') . '\s*:\s*' . preg_quote($value, '/') . '\s*[^{}]*\}/s';
    return (bool) preg_match($re, $css);
}
foreach ($required as $row) {
    list($sel, $prop, $value) = $row;
    ck("served CSS contains  $sel { $prop: $value }", rule_exists($served, $sel, $prop, $value));
}

// Comment-structure guard, tracked by state. A valid stylesheet must not
// have a "*/" with no open comment to close it (the exact 2026-09-16
// failure: an orphan "*/" broke the parser and swallowed the [hidden] rule
// — the rule text still exists, so a text-presence check cannot see it).
// It must also not end inside an unclosed "/*". A "/*" encountered while
// already in a comment is just text (CSS comments do not nest), so it is
// ignored — this is why a naive `/*`/`*/` count, or a "no nested /*" rule,
// is wrong (the v1.12 note legitimately embeds "/*" as text).
function css_has_stray_or_unclosed($css) {
    $n = strlen($css); $i = 0; $in = false;
    while ($i < $n - 1) {
        if ($in) {
            if ($css[$i] === '*' && $css[$i + 1] === '/') { $in = false; $i += 2; }
            else { $i++; }
        } else {
            if ($css[$i] === '*' && $css[$i + 1] === '/') return true;  // orphan */
            if ($css[$i] === '/' && $css[$i + 1] === '*')             { $in = true; $i += 2; }
            else { $i++; }
        }
    }
    return $in;   // true = ends inside an unclosed comment (truncated)
}
ck('CSS comments well-formed (no orphan */ or unclosed /*)', !css_has_stray_or_unclosed($served));

echo "\nCSS_SANE_RESULT  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
