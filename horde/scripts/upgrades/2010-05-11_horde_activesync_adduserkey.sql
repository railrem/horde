ALTER TABLE horde_activesync_state ADD COLUMN sync_user VARCHAR(255);

ALTER TABLE horde_activesync_device DROP COLUMN device_user;
ALTER TABLE horde_activesync_device DROP COLUMN device_ping;
ALTER TABLE horde_activesync_device DROP COLUMN device_folders;

DROP INDEX activesync_device_user_idx;

CREATE TABLE horde_activesync_device_users (
    device_id         VARCHAR(255) NOT NULL,
    device_user       VARCHAR(255) NOT NULL,
    device_ping       TEXT,
    device_folders    TEXT,
--
    PRIMARY KEY (device_id, device_user)
);
CREATE INDEX activesync_device_users_idx ON horde_activesync_device_users (device_user);

ALTER TABLE horde_activesync_map ADD COLUMN sync_user VARCHAR(255);
CREATE INDEX activesync_map_user_idx ON horde_activesync_map (sync_user);

