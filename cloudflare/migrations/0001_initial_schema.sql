-- MyPortfolio backend foundation schema for Cloudflare D1 / SQLite.
-- Wrangler records applied migrations in its d1_migrations table.
-- Legacy contact_messages records can be imported through the legacy ID columns
-- on conversations and conversation_messages.

CREATE TABLE IF NOT EXISTS admin_users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT NOT NULL UNIQUE,
  email TEXT UNIQUE,
  password_hash TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT 'admin',
  is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
  last_login_at TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_admin_users_active
  ON admin_users (is_active);

CREATE TABLE IF NOT EXISTS admin_tokens (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  admin_user_id INTEGER NOT NULL,
  token_hash TEXT NOT NULL UNIQUE,
  expires_at TEXT NOT NULL,
  revoked_at TEXT,
  last_used_at TEXT,
  ip_address TEXT,
  user_agent TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (admin_user_id) REFERENCES admin_users (id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_admin_tokens_user
  ON admin_tokens (admin_user_id);

CREATE INDEX IF NOT EXISTS idx_admin_tokens_expiry
  ON admin_tokens (expires_at);

CREATE TABLE IF NOT EXISTS conversations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  legacy_contact_message_id INTEGER UNIQUE,
  contact_name TEXT NOT NULL,
  contact_email TEXT NOT NULL,
  subject TEXT,
  status TEXT NOT NULL DEFAULT 'open',
  read_at TEXT,
  archived_at TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_message_at TEXT
);

CREATE INDEX IF NOT EXISTS idx_conversations_email
  ON conversations (contact_email);

CREATE INDEX IF NOT EXISTS idx_conversations_status
  ON conversations (status);

CREATE INDEX IF NOT EXISTS idx_conversations_archived
  ON conversations (archived_at);

CREATE INDEX IF NOT EXISTS idx_conversations_last_message
  ON conversations (last_message_at);

CREATE TABLE IF NOT EXISTS conversation_messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  conversation_id INTEGER NOT NULL,
  legacy_contact_message_id INTEGER UNIQUE,
  sender_type TEXT NOT NULL,
  sender_admin_id INTEGER,
  sender_name TEXT,
  sender_email TEXT,
  body TEXT NOT NULL,
  read_at TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE CASCADE,
  FOREIGN KEY (sender_admin_id) REFERENCES admin_users (id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_conversation_messages_conversation
  ON conversation_messages (conversation_id, created_at);

CREATE INDEX IF NOT EXISTS idx_conversation_messages_sender
  ON conversation_messages (sender_admin_id);

CREATE TABLE IF NOT EXISTS notifications (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  conversation_id INTEGER,
  conversation_message_id INTEGER,
  recipient_type TEXT NOT NULL,
  notification_type TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'pending',
  attempt_count INTEGER NOT NULL DEFAULT 0,
  last_error TEXT,
  sent_at TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE CASCADE,
  FOREIGN KEY (conversation_message_id) REFERENCES conversation_messages (id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_notifications_status
  ON notifications (status);

CREATE INDEX IF NOT EXISTS idx_notifications_conversation
  ON notifications (conversation_id);

CREATE INDEX IF NOT EXISTS idx_notifications_message
  ON notifications (conversation_message_id);

CREATE TABLE IF NOT EXISTS audit_logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  admin_user_id INTEGER,
  action TEXT NOT NULL,
  entity_type TEXT,
  entity_id INTEGER,
  metadata_json TEXT,
  ip_address TEXT,
  user_agent TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (admin_user_id) REFERENCES admin_users (id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_audit_logs_admin
  ON audit_logs (admin_user_id);

CREATE INDEX IF NOT EXISTS idx_audit_logs_entity
  ON audit_logs (entity_type, entity_id);

CREATE INDEX IF NOT EXISTS idx_audit_logs_created
  ON audit_logs (created_at);

CREATE TABLE IF NOT EXISTS contact_rate_limits (
  rate_key TEXT PRIMARY KEY,
  last_submission INTEGER NOT NULL,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Workers must set updated_at = CURRENT_TIMESTAMP explicitly whenever they
-- update application rows; SQLite does not support MySQL's ON UPDATE clause.

-- Preserve the existing administrator account without exposing or changing its hash.
INSERT INTO admin_users (username, email, password_hash, role, is_active)
VALUES (
  'admin',
  'admin@portfolio.local',
  '$2y$12$Tg69e68r/YLjsikCOGJO6umDELxgoWwRzkxhWShP6564WLNsqou8m',
  'admin',
  1
)
ON CONFLICT (username) DO UPDATE SET
  email = excluded.email,
  password_hash = excluded.password_hash,
  role = excluded.role,
  is_active = excluded.is_active;
