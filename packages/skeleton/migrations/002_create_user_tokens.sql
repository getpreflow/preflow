CREATE TABLE IF NOT EXISTS user_tokens (
    uuid TEXT PRIMARY KEY,
    tokenHash TEXT NOT NULL UNIQUE,
    userId TEXT NOT NULL,
    name TEXT NOT NULL DEFAULT '',
    createdAt TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (userId) REFERENCES users(uuid) ON DELETE CASCADE
);
