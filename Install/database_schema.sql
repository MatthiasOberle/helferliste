PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS access_codes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL,
    code TEXT NOT NULL UNIQUE,
    created_at TEXT,
    used_at TEXT,
    last_login_at TEXT
);

CREATE TABLE IF NOT EXISTS entries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    status TEXT NOT NULL,
    note TEXT,
    created_at TEXT,
    access_code_id INTEGER,
    FOREIGN KEY (access_code_id) REFERENCES access_codes(id)
);

CREATE TABLE IF NOT EXISTS shifts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    max_slots INTEGER NOT NULL DEFAULT 1,
    sort_order INTEGER NOT NULL DEFAULT 1,
    active INTEGER NOT NULL DEFAULT 1,
    shift_date TEXT,
    start_time TEXT,
    end_time TEXT,
    location TEXT,
    note TEXT
);

CREATE TABLE IF NOT EXISTS springer_shifts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    max_slots INTEGER NOT NULL DEFAULT 1,
    sort_order INTEGER NOT NULL DEFAULT 1,
    active INTEGER NOT NULL DEFAULT 1,
    shift_date TEXT,
    start_time TEXT,
    end_time TEXT,
    location TEXT,
    note TEXT
);

CREATE TABLE IF NOT EXISTS entry_shifts (
    entry_id INTEGER NOT NULL,
    shift_id INTEGER NOT NULL,
    PRIMARY KEY (entry_id, shift_id),
    FOREIGN KEY (entry_id) REFERENCES entries(id) ON DELETE CASCADE,
    FOREIGN KEY (shift_id) REFERENCES shifts(id)
);

CREATE TABLE IF NOT EXISTS entry_springer_shifts (
    entry_id INTEGER NOT NULL,
    springer_shift_id INTEGER NOT NULL,
    PRIMARY KEY (entry_id, springer_shift_id),
    FOREIGN KEY (entry_id) REFERENCES entries(id) ON DELETE CASCADE,
    FOREIGN KEY (springer_shift_id) REFERENCES springer_shifts(id)
);

CREATE TABLE IF NOT EXISTS change_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    access_code_id INTEGER,
    entry_id INTEGER,
    name TEXT,
    message TEXT NOT NULL,
    requested_at TEXT,
    status TEXT NOT NULL DEFAULT 'open',
    handled_at TEXT,
    admin_note TEXT,
    FOREIGN KEY (access_code_id) REFERENCES access_codes(id),
    FOREIGN KEY (entry_id) REFERENCES entries(id)
);

CREATE TABLE IF NOT EXISTS visit_stats (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    visited_at TEXT,
    page TEXT,
    device_type TEXT
);

CREATE TABLE IF NOT EXISTS event_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at TEXT,
    action TEXT,
    detail TEXT
);

CREATE TABLE IF NOT EXISTS app_settings (
    setting_key TEXT PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS schema_migrations (
    version INTEGER PRIMARY KEY,
    app_version TEXT NOT NULL,
    applied_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS event_archives (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_name TEXT NOT NULL,
    event_organizer TEXT NOT NULL,
    closed_at TEXT NOT NULL,
    summary_json TEXT NOT NULL,
    template_json TEXT NOT NULL,
    personal_data_json TEXT,
    includes_personal_data INTEGER NOT NULL DEFAULT 0 CHECK (includes_personal_data IN (0, 1))
);

CREATE INDEX IF NOT EXISTS idx_access_codes_code ON access_codes(code);
CREATE INDEX IF NOT EXISTS idx_entries_access_code_id ON entries(access_code_id);
CREATE INDEX IF NOT EXISTS idx_entries_created_at ON entries(created_at);
CREATE INDEX IF NOT EXISTS idx_change_requests_status ON change_requests(status);
CREATE INDEX IF NOT EXISTS idx_change_requests_entry_id ON change_requests(entry_id);
CREATE INDEX IF NOT EXISTS idx_visit_stats_page ON visit_stats(page);
CREATE INDEX IF NOT EXISTS idx_event_archives_closed_at ON event_archives(closed_at);

INSERT OR IGNORE INTO shifts (id, title, max_slots, sort_order, active) VALUES
(1, 'Beispiel: Aufbau Freitag 16:00 - 20:00', 5, 1, 1),
(2, 'Beispiel: Dienst Samstag 11:00 - 15:00', 6, 2, 1),
(3, 'Beispiel: Abbau Sonntag ab 18:00', 8, 3, 1);

INSERT OR IGNORE INTO springer_shifts (id, title, max_slots, sort_order, active) VALUES
(1, 'Beispiel: Springer Samstag 11:00 - 15:00', 4, 1, 1),
(2, 'Beispiel: Springer Sonntag 15:00 - 19:00', 4, 2, 1);

INSERT INTO event_log (created_at, action, detail)
SELECT datetime('now'), 'installed', 'Helferliste wurde eingerichtet.'
WHERE NOT EXISTS (SELECT 1 FROM event_log WHERE action = 'installed');

PRAGMA user_version = 4;
