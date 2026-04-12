CREATE TABLE IF NOT EXISTS users (
    uuid TEXT PRIMARY KEY,
    email TEXT NOT NULL UNIQUE,
    passwordHash TEXT NOT NULL,
    roles TEXT NOT NULL DEFAULT '[]',
    createdAt TEXT DEFAULT (datetime('now'))
);
