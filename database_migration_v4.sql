USE fsscbt_db;

SET @col = (
    SELECT COUNT(*)
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'results'
       AND COLUMN_NAME = 'total_questions'
);
SET @sql = IF(
    @col = 0,
    'ALTER TABLE results ADD COLUMN total_questions INT NOT NULL DEFAULT 0 AFTER submitted_at',
    'SELECT ''total_questions already exists'' AS skip'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col = (
    SELECT COUNT(*)
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'results'
       AND COLUMN_NAME = 'correct_answers'
);
SET @sql = IF(
    @col = 0,
    'ALTER TABLE results ADD COLUMN correct_answers INT NOT NULL DEFAULT 0 AFTER total_questions',
    'SELECT ''correct_answers already exists'' AS skip'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
