<?php
namespace Shuffle\Core;

/**
 * Internationalization (i18n) string loader.
 *
 * Loads translatable strings from JSON files and supports positional
 * placeholder replacement ({0}, {1}, etc.).
 *
 * Key convention: {domain}.{action_or_label} (e.g., board.create, error.forbidden)
 */
class Lang
{
    /** @var array<string, string> Loaded translation strings */
    private array $strings = [];

    /**
     * Loads the translation file for the specified locale.
     *
     * @param string $locale  Locale identifier (e.g., 'en')
     * @param string $langDir Absolute path to the lang directory
     * @throws \RuntimeException If the language file cannot be loaded
     */
    public function __construct(string $locale, string $langDir)
    {
        $file = rtrim($langDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $locale . '.json';

        if (!file_exists($file)) {
            throw new \RuntimeException("Language file not found: {$file}");
        }

        $json = file_get_contents($file);
        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException("Invalid language file format: {$file}");
        }

        $this->strings = $decoded;
    }

    /**
     * Returns the translated string for the given key.
     *
     * Supports positional placeholders: {0}, {1}, etc.
     * Returns the key itself if no translation is found.
     *
     * @param string $key    Translation key (e.g., 'board.create')
     * @param array  $params Positional replacement values
     * @return string        Translated string with placeholders replaced
     */
    public function get(string $key, array $params = []): string
    {
        $text = $this->strings[$key] ?? $key;

        foreach ($params as $index => $value) {
            $text = str_replace('{' . $index . '}', (string) $value, $text);
        }

        return $text;
    }

    /**
     * Checks if a translation key exists.
     *
     * @param string $key Translation key
     * @return bool       True if the key exists
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->strings);
    }
}
