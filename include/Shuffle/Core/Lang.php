<?php
namespace Shuffle\Core;

/**
 * Internationalization (i18n) string loader.
 *
 * Loads translatable strings from JSON files and supports positional
 * placeholder replacement ({0}, {1}, etc.).
 *
 * Key convention: {domain}.{action_or_label} (e.g., board.create, error.forbidden)
 *
 * INTL (v1.22 §5.29): locale files are an *optional layer* over the English
 * base. When constructed against a non-English locale the caller may pass
 * 'en' as the fallback; a key missing from the locale file then resolves to
 * the English value — never a raw key, never an error. English itself is
 * authoritative: every key exists in en.json before it is used anywhere.
 *
 * Reserved metadata key: "{code}.__native_name" carries the language's name
 * in its own language (for the Profile language selector). It is metadata,
 * not a UI string: get() always returns the key itself for it, and
 * availableLanguages() is the only reader.
 */
class Lang
{
    /** @var array<string, string> Loaded translation strings (locale layer) */
    private array $strings = [];

    /** @var array<string, string> English base layer (INTL-04 fallback) */
    private array $fallbackStrings = [];

    /** @var string The resolved locale code (e.g., 'en', 'sv') */
    private string $locale;

    /**
     * Loads the translation file for the specified locale.
     *
     * @param string      $locale         Locale identifier (e.g., 'en', 'sv')
     * @param string      $langDir        Absolute path to the lang directory
     * @param string|null $fallbackLocale Locale to backfill missing keys with
     *                                    (INTL-04); 'en' in production, null
     *                                    when $locale IS the base language
     * @throws \RuntimeException If the language file cannot be loaded
     */
    public function __construct(string $locale, string $langDir, ?string $fallbackLocale = null)
    {
        // Validate locale format to prevent path traversal
        if (!preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $locale)) {
            throw new \InvalidArgumentException("Invalid locale format: {$locale}");
        }
        $this->locale = $locale;

        $this->strings = $this->loadFile(rtrim($langDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $locale . '.json');

        // INTL-04: English base layer, loaded only for non-English locales
        // (en never backfills itself). A missing fallback file is tolerated —
        // the locale layer alone still works; the backfill simply doesn't exist.
        if ($fallbackLocale !== null && $fallbackLocale !== $locale) {
            if (preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $fallbackLocale) === 1) {
                $fallbackFile = rtrim($langDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fallbackLocale . '.json';
                if (file_exists($fallbackFile)) {
                    $this->fallbackStrings = $this->loadFile($fallbackFile);
                }
            }
        }
    }

    /**
     * Returns the translated string for the given key.
     *
     * Resolution order (INTL-04): locale file → English base layer → the key
     * itself. The raw-key outcome is only reachable for a key missing from
     * BOTH layers, which cannot happen for shipped UI (en.json is the single
     * source of truth). No "missing translation" state is ever surfaced.
     *
     * Supports positional placeholders: {0}, {1}, etc.
     *
     * @param string $key    Translation key (e.g., 'board.create')
     * @param array  $params Positional replacement values
     * @return string        Translated string with placeholders replaced
     */
    public function get(string $key, array $params = []): string
    {
        // Reserved metadata: the __native_name entries are selector data, not
        // UI strings — get() never returns them as values.
        if (str_ends_with($key, '.__native_name')) {
            return $key;
        }

        $text = $this->strings[$key] ?? $this->fallbackStrings[$key] ?? $key;

        foreach ($params as $index => $value) {
            $text = str_replace('{' . $index . '}', (string) $value, $text);
        }

        return $text;
    }

    /**
     * TRUE if the key resolves in this locale — the locale layer OR the
     * English base layer (INTL-04 semantics: "usable in this locale").
     *
     * @param string $key Translation key
     * @return bool       True if a translation is available
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->strings) || array_key_exists($key, $this->fallbackStrings);
    }

    /**
     * The resolved (effective) locale code of this instance.
     *
     * Used by header.php for the HTML5 lang attribute (INTL-01: the attribute
     * must reflect the locale actually in force, not a static default).
     *
     * @return string e.g., 'en' | 'sv'
     */
    public function getLocale(): string
    {
        return $this->locale;
    }

    /**
     * Discovers the available interface languages (INTL-06).
     *
     * A language exists iff `{langDir}/{code}.json` exists, the code matches
     * the locale format (which doubles as the path-traversal guard), and the
     * file decodes to a JSON object. No code, config, or restart is needed to
     * add a language — one file IS the adoption path.
     *
     * @param string $langDir Absolute path to the lang directory
     * @return array<string, string> code => native name, sorted by name
     *                               (e.g., ['en' => 'English', 'sv' => 'Svenska'])
     *                               (a file without {code}.__native_name is
     *                               listed under its code — degraded, but visible)
     */
    public static function availableLanguages(string $langDir): array
    {
        $langDir = rtrim($langDir, DIRECTORY_SEPARATOR);
        $out = [];

        foreach (glob($langDir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            $code = basename($file, '.json');
            if (preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $code) !== 1) {
                continue; // not a language file (or a traversal-ish name) — skip
            }

            $data = json_decode((string) file_get_contents($file), true);
            if (!is_array($data)) {
                continue; // unparseable file is not a usable language — skip silently
            }

            $name = $data[$code . '.__native_name'] ?? $code;
            if (is_array($name)) {
                $name = $code;
            }
            $out[$code] = (string) $name;
        }

        asort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    /**
     * Loads + validates a single language file.
     *
     * @param string $file Absolute path to a .json language file
     * @return array<string, string|mixed> Decoded content
     * @throws \RuntimeException If the file is missing or invalid
     */
    private function loadFile(string $file): array
    {
        if (!file_exists($file)) {
            throw new \RuntimeException("Language file not found: {$file}");
        }

        $json = file_get_contents($file);
        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException("Invalid language file format: {$file}");
        }

        return $decoded;
    }
}
