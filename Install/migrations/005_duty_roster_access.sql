-- Schema-Version 5 begrenzt fehlgeschlagene Zugriffsversuche auf die
-- personenbezogene Diensteinteilung. Gespeichert wird nur ein HMAC-Wert,
-- niemals die Klartext-IP-Adresse.
CREATE TABLE IF NOT EXISTS duty_roster_login_attempts (
    attempt_key TEXT PRIMARY KEY,
    window_started_at INTEGER NOT NULL,
    failures INTEGER NOT NULL DEFAULT 0,
    blocked_until INTEGER NOT NULL DEFAULT 0,
    updated_at INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_duty_roster_attempts_updated_at
    ON duty_roster_login_attempts(updated_at);
