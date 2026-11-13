'use strict';

const { DatabaseSync } = require('node:sqlite');

function openLedger(path, { readOnly = false } = {}) {
  const db = new DatabaseSync(path, { readOnly });
  if (!readOnly) db.exec('CREATE TABLE IF NOT EXISTS events (id INTEGER PRIMARY KEY, at TEXT, type TEXT, body TEXT)');
  const select = db.prepare('SELECT id, at, type, body FROM events ORDER BY id');
  return {
    add(type, body) {
      const insert = db.prepare('INSERT INTO events (at, type, body) VALUES (?, ?, ?)');
      return Number(insert.run(new Date().toISOString(), type, JSON.stringify(body)).lastInsertRowid);
    },
    read() {
      return select.all().map(({ body, ...event }) => ({ ...event, body: JSON.parse(body) }));
    },
    close: () => db.close(),
  };
}

// Lock a separate SQLite file so event commits stay durable while a model is running.
function lockRun(path) {
  const db = new DatabaseSync(path + '.lock');
  try {
    db.exec('BEGIN EXCLUSIVE');
  } catch (error) {
    db.close();
    if (error.errcode === 5) throw Error('This inquiry already has a runner');
    throw error;
  }
  return () => db.close();
}

module.exports = { openLedger, lockRun };
