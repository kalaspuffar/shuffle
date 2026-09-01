-- Shuffle Database Schema
-- All tables use InnoDB, utf8mb4 charset, utf8mb4_unicode_ci collation.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------
-- organizations (must exist before users due to FK reference)
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `organizations` (
    `id`         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(128)    NOT NULL,
    `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- users
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id`                      INT UNSIGNED                          NOT NULL AUTO_INCREMENT,
    `username`                VARCHAR(64)                           NOT NULL,
    `password_hash`           VARCHAR(255)                          NOT NULL,
    `name`                    VARCHAR(128)                          NOT NULL,
    `email`                   VARCHAR(255)                          NOT NULL,
    `role`                    ENUM('admin', 'member', 'viewer')     NOT NULL DEFAULT 'member',
    `organization_id`         INT UNSIGNED                          NULL,
    `is_placeholder`          TINYINT(1)                            NOT NULL DEFAULT 0,
    `status`                  ENUM('active', 'inactive')            NOT NULL DEFAULT 'active',
    `invite_token`            VARCHAR(128)                          NULL,
    `invite_token_expires_at` DATETIME                              NULL,
    `trello_id`               VARCHAR(64)                           NULL,
    `created_at`              DATETIME                              NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`              DATETIME                              NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_users_username` (`username`),
    UNIQUE KEY `uk_users_email` (`email`),
    UNIQUE KEY `uk_users_invite_token` (`invite_token`),
    KEY `fk_users_organization` (`organization_id`),
    CONSTRAINT `fk_users_organization` FOREIGN KEY (`organization_id`)
        REFERENCES `organizations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- boards
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `boards` (
    `id`          INT UNSIGNED                            NOT NULL AUTO_INCREMENT,
    `title`       VARCHAR(255)                            NOT NULL,
    `description` TEXT                                    NULL,
    `visibility`  ENUM('private', 'organization')         NOT NULL DEFAULT 'private',
    `is_archived` TINYINT(1)                              NOT NULL DEFAULT 0,
    `version`     INT UNSIGNED                            NOT NULL DEFAULT 1,
    `created_by`  INT UNSIGNED                            NOT NULL,
    `trello_id`   VARCHAR(64)                             NULL,
    `created_at`  DATETIME                                NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME                                NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_boards_trello_id` (`trello_id`),
    KEY `fk_boards_created_by` (`created_by`),
    CONSTRAINT `fk_boards_created_by` FOREIGN KEY (`created_by`)
        REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- board_organizations (many-to-many: boards <-> organizations)
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `board_organizations` (
    `board_id`        INT UNSIGNED NOT NULL,
    `organization_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`board_id`, `organization_id`),
    KEY `fk_bo_organization` (`organization_id`),
    CONSTRAINT `fk_bo_board` FOREIGN KEY (`board_id`)
        REFERENCES `boards` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_bo_organization` FOREIGN KEY (`organization_id`)
        REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- lanes
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lanes` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `board_id`   INT UNSIGNED NOT NULL,
    `title`      VARCHAR(255) NOT NULL,
    `icon`       VARCHAR(16)  NULL,
    `position`   INT UNSIGNED NOT NULL,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_lanes_board_position` (`board_id`, `position`),
    CONSTRAINT `fk_lanes_board` FOREIGN KEY (`board_id`)
        REFERENCES `boards` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- cards
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cards` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `lane_id`     INT UNSIGNED NOT NULL,
    `title`       VARCHAR(255) NOT NULL,
    `description` TEXT         NULL,
    `due_date`    DATE         NULL,
    `position`    INT UNSIGNED NOT NULL,
    `is_archived` TINYINT(1)   NOT NULL DEFAULT 0,
    `created_by`  INT UNSIGNED NOT NULL,
    `trello_id`   VARCHAR(64)  NULL,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_cards_lane_position` (`lane_id`, `position`),
    FULLTEXT KEY `ft_cards_title_description` (`title`, `description`),
    KEY `fk_cards_created_by` (`created_by`),
    CONSTRAINT `fk_cards_lane` FOREIGN KEY (`lane_id`)
        REFERENCES `lanes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cards_created_by` FOREIGN KEY (`created_by`)
        REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- card_assignments (many-to-many: cards <-> users)
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `card_assignments` (
    `card_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`card_id`, `user_id`),
    KEY `fk_ca_user` (`user_id`),
    CONSTRAINT `fk_ca_card` FOREIGN KEY (`card_id`)
        REFERENCES `cards` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ca_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- comments
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `comments` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `card_id`    INT UNSIGNED NOT NULL,
    `user_id`    INT UNSIGNED NOT NULL,
    `body`       TEXT         NOT NULL,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_comments_card_created` (`card_id`, `created_at`),
    KEY `fk_comments_user` (`user_id`),
    CONSTRAINT `fk_comments_card` FOREIGN KEY (`card_id`)
        REFERENCES `cards` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_comments_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- checklists
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `checklists` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `card_id`    INT UNSIGNED NOT NULL,
    `title`      VARCHAR(255) NOT NULL,
    `position`   INT UNSIGNED NOT NULL,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_checklists_card_position` (`card_id`, `position`),
    CONSTRAINT `fk_checklists_card` FOREIGN KEY (`card_id`)
        REFERENCES `cards` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- checklist_items
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `checklist_items` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `checklist_id`     INT UNSIGNED NOT NULL,
    `title`            VARCHAR(255) NOT NULL,
    `is_checked`       TINYINT(1)   NOT NULL DEFAULT 0,
    `assigned_user_id` INT UNSIGNED NULL,
    `position`         INT UNSIGNED NOT NULL,
    `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_checklist_items_checklist_position` (`checklist_id`, `position`),
    KEY `fk_checklist_items_user` (`assigned_user_id`),
    CONSTRAINT `fk_checklist_items_checklist` FOREIGN KEY (`checklist_id`)
        REFERENCES `checklists` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_checklist_items_user` FOREIGN KEY (`assigned_user_id`)
        REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- attachments
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attachments` (
    `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `card_id`     INT UNSIGNED    NOT NULL,
    `user_id`     INT UNSIGNED    NOT NULL,
    `file_name`   VARCHAR(255)    NOT NULL,
    `file_size`   BIGINT UNSIGNED NOT NULL,
    `s3_key`      VARCHAR(512)    NOT NULL,
    `mime_type`   VARCHAR(128)    NOT NULL,
    `uploaded_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_attachments_card` (`card_id`),
    KEY `fk_attachments_user` (`user_id`),
    CONSTRAINT `fk_attachments_card` FOREIGN KEY (`card_id`)
        REFERENCES `cards` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_attachments_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- notifications
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifications` (
    `id`           INT UNSIGNED                    NOT NULL AUTO_INCREMENT,
    `user_id`      INT UNSIGNED                    NOT NULL,
    `type`         ENUM('assignment', 'comment', 'creator') NOT NULL,
    `reference_id` INT UNSIGNED                    NOT NULL,
    `comment_id`   INT UNSIGNED                    NULL,
    `message`      VARCHAR(512)                    NOT NULL,
    `is_read`      TINYINT(1)                      NOT NULL DEFAULT 0,
    `created_at`   DATETIME                        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_notifications_user_read_created` (`user_id`, `is_read`, `created_at`),
    CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_notifications_comment` FOREIGN KEY (`comment_id`)
        REFERENCES `comments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- labels
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `labels` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `board_id`   INT UNSIGNED NOT NULL,
    `name`       VARCHAR(64)  NOT NULL,
    `color`      VARCHAR(7)   NOT NULL,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_labels_board` (`board_id`),
    CONSTRAINT `fk_labels_board` FOREIGN KEY (`board_id`)
        REFERENCES `boards` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- card_labels (many-to-many: cards <-> labels)
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `card_labels` (
    `card_id`  INT UNSIGNED NOT NULL,
    `label_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`card_id`, `label_id`),
    KEY `fk_cl_label` (`label_id`),
    CONSTRAINT `fk_cl_card` FOREIGN KEY (`card_id`)
        REFERENCES `cards` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cl_label` FOREIGN KEY (`label_id`)
        REFERENCES `labels` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- settings (application key/value configuration store)
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
    `key`   VARCHAR(64) NOT NULL,
    `value` TEXT        NOT NULL,
    PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- sessions (MySQL-backed session storage)
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sessions` (
    `id`            VARCHAR(128) NOT NULL,
    `user_id`       INT UNSIGNED NULL,
    `data`          TEXT         NOT NULL,
    `last_activity` DATETIME     NOT NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_sessions_user` (`user_id`),
    KEY `idx_sessions_last_activity` (`last_activity`),
    CONSTRAINT `fk_sessions_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- user_prio (personal priority list, PRIO-01..11)
-- Per-user membership + custom ordering for the "work on
-- next" view. Stores only (user, card) pairs; all card data
-- is read live from the board on every render (no mirrors).
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_prio` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `card_id`    INT UNSIGNED NOT NULL,
    `position`   INT UNSIGNED NOT NULL,
    `added_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_prio_user_card` (`user_id`, `card_id`),
    KEY `idx_user_prio_user_pos` (`user_id`, `position`),
    CONSTRAINT `fk_user_prio_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_user_prio_card` FOREIGN KEY (`card_id`)
        REFERENCES `cards` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- card_activity (card history / audit log, ACTIVITY-01..03)
-- Append-only per-card event log. One row per notable card
-- event (move, edit, assign, archive, comment lifecycle, ...)
-- with actor + timestamp. Lane/user names are snapshotted
-- into payload_json at write time so the record survives
-- later renames and deletions (Trello/Linear pattern).
-- The log starts cold by design: no backfill of pre-feature
-- history (cards.updated_at is unreliable).
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `card_activity` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `card_id`        INT UNSIGNED NOT NULL,
    `board_id`       INT UNSIGNED NOT NULL,
    `event`          VARCHAR(32)  NOT NULL,
    `actor_id`       INT UNSIGNED NOT NULL,
    `payload_json`   JSON         NULL,
    `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_card_activity_card_id_id` (`card_id`, `id`),
    KEY `idx_card_activity_board_event_created` (`board_id`, `event`, `created_at`),
    KEY `idx_card_activity_actor_created` (`actor_id`, `created_at`),
    CONSTRAINT `fk_card_activity_card` FOREIGN KEY (`card_id`)
        REFERENCES `cards` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_card_activity_actor` FOREIGN KEY (`actor_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
