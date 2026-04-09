-- Add 'risk' to sync_mappings local_type ENUM
ALTER TABLE sync_mappings MODIFY COLUMN local_type ENUM('hl_work_item','user_story','sprint','risk') NOT NULL;
