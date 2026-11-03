
# This is a fix for InnoDB in MySQL >= 4.1.x
# It "suspends judgement" for fkey relationships until are tables are set.
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- csv_importer_profile
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `csv_importer_profile`;

CREATE TABLE `csv_importer_profile`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `delimiter` VARCHAR(8),
    `encoding` VARCHAR(20),
    `decimal_separator` VARCHAR(1),
    `mapping` TEXT NOT NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `csv_importer_profile_name_unique` (`name`)
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_general_ci' ROW_FORMAT=DYNAMIC;

-- ---------------------------------------------------------------------
-- csv_importer_job
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `csv_importer_job`;

CREATE TABLE `csv_importer_job`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `profile_id` INTEGER,
    `admin_id` INTEGER,
    `status` VARCHAR(16) NOT NULL,
    `mode` VARCHAR(16) NOT NULL,
    `dry_run` TINYINT DEFAULT 0 NOT NULL,
    `source_name` VARCHAR(255) NOT NULL,
    `source_path` VARCHAR(255) NOT NULL,
    `total_rows` INTEGER DEFAULT 0 NOT NULL,
    `processed_rows` INTEGER DEFAULT 0 NOT NULL,
    `created_rows` INTEGER DEFAULT 0 NOT NULL,
    `updated_rows` INTEGER DEFAULT 0 NOT NULL,
    `unchanged_rows` INTEGER DEFAULT 0 NOT NULL,
    `skipped_rows` INTEGER DEFAULT 0 NOT NULL,
    `failed_rows` INTEGER DEFAULT 0 NOT NULL,
    `started_at` TIMESTAMP NULL,
    `finished_at` TIMESTAMP NULL,
    `error` TEXT,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_csv_importer_job_status` (`status`),
    INDEX `fi_csv_importer_job_profile` (`profile_id`),
    CONSTRAINT `fk_csv_importer_job_profile`
        FOREIGN KEY (`profile_id`)
        REFERENCES `csv_importer_profile` (`id`)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_general_ci' ROW_FORMAT=DYNAMIC;

-- ---------------------------------------------------------------------
-- csv_importer_job_row
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `csv_importer_job_row`;

CREATE TABLE `csv_importer_job_row`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `job_id` INTEGER NOT NULL,
    `line_number` INTEGER NOT NULL,
    `outcome` VARCHAR(16) NOT NULL,
    `reference` VARCHAR(255),
    `message` TEXT,
    PRIMARY KEY (`id`),
    INDEX `idx_csv_importer_job_row_outcome` (`job_id`, `outcome`),
    CONSTRAINT `fk_csv_importer_job_row_job`
        FOREIGN KEY (`job_id`)
        REFERENCES `csv_importer_job` (`id`)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_general_ci' ROW_FORMAT=DYNAMIC;

# This restores the fkey checks, after having unset them earlier
SET FOREIGN_KEY_CHECKS = 1;
