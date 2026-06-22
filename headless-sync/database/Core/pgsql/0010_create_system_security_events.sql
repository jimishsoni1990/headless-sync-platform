-- Migration: 0010_create_system_security_events
-- Authority: ARCHITECTURE_DECISIONS.md v1.4 — OPEN-8 (v1.4)
-- Adds to: Doc 3 §4
--
-- Infrastructure security audit trail. No business-domain content (Doc 3 §4).
--
-- event_type: fully-qualified security.<aggregate>.<action> — mirrors OPEN-1 convention
-- severity:   required triage field; values: 'low', 'medium', 'high', 'critical'
-- actor_type: disambiguates the actor_id namespace (e.g. 'user', 'worker', 'api_key');
--             NULL for unauthenticated or system-initiated events
-- actor_id:   VARCHAR(255) not UUID — must accommodate non-UUID actors (API keys,
--             external identifiers, unauthenticated sessions) — OPEN-8 rationale
-- ip_address: IPv4 or IPv6 (max 45 chars)
-- Timestamps: TIMESTAMPTZ (v1.2 canon)

CREATE TABLE IF NOT EXISTS system.security_events (
    id          UUID         NOT NULL,
    event_type  VARCHAR(100) NOT NULL,  -- security.<aggregate>.<action>
    severity    VARCHAR(20)  NOT NULL,  -- 'low' | 'medium' | 'high' | 'critical'
    actor_type  VARCHAR(50)  NULL,      -- 'user' | 'worker' | 'api_key' | NULL
    actor_id    VARCHAR(255) NULL,      -- platform actor identifier (non-UUID actors supported)
    ip_address  VARCHAR(45)  NULL,      -- IPv4 or IPv6
    metadata    JSONB        NOT NULL,
    created_at  TIMESTAMPTZ  NOT NULL,

    CONSTRAINT pk_system_security_events PRIMARY KEY (id)
);

-- Operational query: security event timeline by type
CREATE INDEX IF NOT EXISTS idx_security_events_type_time
    ON system.security_events (event_type, created_at);
