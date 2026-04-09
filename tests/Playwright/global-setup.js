// global-setup.js — creates a regular (non-admin) test user before the suite runs.
// Uses the same password hash as the seed admin user ("password123").
const mysql = require('mysql2/promise');

const REGULAR_USER_EMAIL = 'pw_regular@test.invalid';
// bcrypt hash of "password123" — same as seed.sql admin user hash
const REGULAR_USER_HASH  = '$2y$12$iu6uq/e8YF48/fBVtgVgvOcavOH1KoGCGLTMfjxRDCy0aZrZgMor6';

async function globalSetup() {
  const conn = await mysql.createConnection({
    host: '127.0.0.1',
    port: 3307,
    user: 'stratflow',
    password: 'stratflow_secret',
    database: 'stratflow',
  });

  // Clean up in case a previous run left stale data
  await conn.execute('DELETE FROM users WHERE email = ?', [REGULAR_USER_EMAIL]);

  // Create regular user in org 1 (ThreePoints Demo from seed.sql)
  await conn.execute(
    'INSERT INTO users (org_id, email, password_hash, full_name, role) VALUES (?, ?, ?, ?, ?)',
    [1, REGULAR_USER_EMAIL, REGULAR_USER_HASH, 'Playwright Regular', 'user']
  );

  await conn.end();
  console.log('[globalSetup] regular test user created');
}

module.exports = globalSetup;
