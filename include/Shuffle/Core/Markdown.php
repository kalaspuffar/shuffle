<?php
namespace Shuffle\Core;

/**
 * Markdown rendering helper.
 *
 * Wraps Parsedown with safe mode enabled to prevent XSS.
 */
class Markdown
{
    private static ?\Parsedown $instance = null;

    /**
     * Renders Markdown text to sanitized HTML.
     *
     * Uses Parsedown with safe mode to prevent script injection.
     *
     * @param string|null $text Raw Markdown text
     * @return string Sanitized HTML output
     */
    public static function render(?string $text): string
    {
        if ($text === null || trim($text) === '') {
            return '';
        }

        if (self::$instance === null) {
            require_once ROOT_DIR . '/include/vendor/Parsedown.php';
            self::$instance = new \Parsedown();
            self::$instance->setSafeMode(true);
        }

        return self::$instance->text($text);
    }
}
