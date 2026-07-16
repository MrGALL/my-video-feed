-- SQLite equivalent of schema.mysql.sql: TIME/datetime/tinyint(1) -> TEXT/TEXT/INTEGER.

CREATE TABLE IF NOT EXISTS myvideofeed_blacklist (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  term TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS myvideofeed_channels (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  slug TEXT NOT NULL UNIQUE,
  title TEXT,
  active INTEGER NOT NULL DEFAULT 1,
  subscribe INTEGER NOT NULL DEFAULT 1,
  published TEXT,
  updated TEXT
);

CREATE TABLE IF NOT EXISTS myvideofeed_videos (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  channel_id INTEGER NOT NULL REFERENCES myvideofeed_channels(id),
  slug TEXT NOT NULL UNIQUE,
  title TEXT,
  content TEXT,
  duration TEXT,
  published TEXT,
  updated TEXT
);

CREATE INDEX IF NOT EXISTS idx_myvideofeed_videos_channel_id ON myvideofeed_videos (channel_id);
CREATE INDEX IF NOT EXISTS idx_myvideofeed_videos_updated ON myvideofeed_videos (updated);
CREATE INDEX IF NOT EXISTS idx_myvideofeed_videos_published ON myvideofeed_videos (published);
