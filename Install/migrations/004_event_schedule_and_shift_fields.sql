-- Schema-Version 4 ergänzt strukturierte Angaben für beide bisherigen
-- Schichtkategorien. Bestehende Titel und Zuordnungen bleiben unverändert.
ALTER TABLE shifts ADD COLUMN shift_date TEXT;
ALTER TABLE shifts ADD COLUMN start_time TEXT;
ALTER TABLE shifts ADD COLUMN end_time TEXT;
ALTER TABLE shifts ADD COLUMN location TEXT;
ALTER TABLE shifts ADD COLUMN note TEXT;

ALTER TABLE springer_shifts ADD COLUMN shift_date TEXT;
ALTER TABLE springer_shifts ADD COLUMN start_time TEXT;
ALTER TABLE springer_shifts ADD COLUMN end_time TEXT;
ALTER TABLE springer_shifts ADD COLUMN location TEXT;
ALTER TABLE springer_shifts ADD COLUMN note TEXT;
