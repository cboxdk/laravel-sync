CREATE TABLE IF NOT EXISTS sync_spaces (
    space TEXT NOT NULL,
    commit_sequence BIGINT NOT NULL DEFAULT 0,
    retained_from BIGINT NOT NULL DEFAULT 1,
    PRIMARY KEY (space)
);
CREATE TABLE IF NOT EXISTS sync_records (
    space TEXT NOT NULL,
    entity_type TEXT NOT NULL,
    entity_id TEXT NOT NULL,
    version BIGINT NOT NULL,
    deleted SMALLINT NOT NULL,
    payload TEXT NOT NULL,
    PRIMARY KEY (space, entity_type, entity_id)
);
CREATE TABLE IF NOT EXISTS sync_fields (
    space TEXT NOT NULL,
    entity_type TEXT NOT NULL,
    entity_id TEXT NOT NULL,
    field TEXT NOT NULL,
    value_hash CHAR(64) NOT NULL,
    PRIMARY KEY (space, entity_type, entity_id, field)
);
CREATE TABLE IF NOT EXISTS sync_conflict_groups (
    id TEXT NOT NULL,
    space TEXT NOT NULL,
    entity_type TEXT NOT NULL,
    entity_id TEXT NOT NULL,
    field TEXT NOT NULL,
    revision BIGINT NOT NULL,
    open_key TEXT NULL,
    payload TEXT NOT NULL,
    PRIMARY KEY (id)
);
CREATE TABLE IF NOT EXISTS sync_receipts (
    mutation_id TEXT NOT NULL,
    space TEXT NOT NULL,
    payload TEXT NOT NULL,
    PRIMARY KEY (mutation_id)
);
CREATE TABLE IF NOT EXISTS sync_streams (
    space TEXT NOT NULL,
    replica_id TEXT NOT NULL,
    acknowledged BIGINT NOT NULL,
    PRIMARY KEY (space, replica_id)
);
CREATE TABLE IF NOT EXISTS sync_commits (
    space TEXT NOT NULL,
    sequence BIGINT NOT NULL,
    entity_type TEXT NULL,
    payload TEXT NOT NULL,
    PRIMARY KEY (space, sequence)
);
CREATE INDEX IF NOT EXISTS sync_records_scan ON sync_records (space, deleted, entity_type, entity_id);
CREATE INDEX IF NOT EXISTS sync_fields_view ON sync_fields (space, field, value_hash, entity_type, entity_id);
CREATE UNIQUE INDEX IF NOT EXISTS sync_conflict_groups_open ON sync_conflict_groups (open_key);
CREATE INDEX IF NOT EXISTS sync_conflict_groups_entity ON sync_conflict_groups (space, entity_type, entity_id, field);
CREATE INDEX IF NOT EXISTS sync_commits_type ON sync_commits (space, entity_type, sequence);
