-- Schema-Version 3 speichert abgeschlossene Veranstaltungen getrennt von der
-- jeweils aktiven Veranstaltung. Zugangscodes werden nie archiviert.
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

CREATE INDEX IF NOT EXISTS idx_event_archives_closed_at ON event_archives(closed_at);
