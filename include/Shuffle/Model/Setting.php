<?php
namespace Shuffle\Model;

use Shuffle\Core\Database;

/**
 * Key/value application settings stored in the `settings` table.
 *
 * Used to persist configuration (e.g. SMTP settings written by the setup wizard)
 * without requiring filesystem access or config file modifications.
 */
class Setting
{
    private Database $db;

    /**
     * @param Database $db Database instance
     */
    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Returns the value for the given key, or null if not found.
     *
     * @param string $key Setting key
     * @return string|null Stored value or null
     */
    public function get(string $key): ?string
    {
        $row = $this->db->fetch(
            'SELECT `value` FROM `settings` WHERE `key` = ?',
            [$key]
        );

        return $row !== null ? $row['value'] : null;
    }

    /**
     * Stores a setting value, creating or replacing any existing row for that key.
     *
     * @param string $key   Setting key
     * @param string $value Setting value
     */
    public function set(string $key, string $value): void
    {
        $this->db->execute(
            'INSERT INTO `settings` (`key`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
            [$key, $value]
        );
    }
}
