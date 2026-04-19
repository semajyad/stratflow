// global-setup.js — creates a regular (non-admin) test user before the suite runs.
const mysql = require('mysql2/promise');
const { REGULAR_USER_EMAIL, REGULAR_USER_HASH, DB_CONFIG, isLocalBaseUrl } = require('./test-constants');

async function globalSetup() {
  const baseUrl = process.env.BASE_URL || 'http://localhost:8890';
  try {
    new URL(baseUrl);
  } catch (err) {
    throw new Error(`[globalSetup] Invalid BASE_URL: ${baseUrl}`);
  }
  const isLocal = isLocalBaseUrl(baseUrl);
  if (!isLocal) {
    console.log('[globalSetup] staging URL detected — skipping local DB setup');
    return;
  }

  let conn;
  try {
    conn = await mysql.createConnection(DB_CONFIG);
    // Clear login_attempts so cross-browser runs don't trigger rate-limiting
    await conn.execute('DELETE FROM login_attempts');
    await conn.execute('DELETE FROM users WHERE email = ?', [REGULAR_USER_EMAIL]);
    await conn.execute(
      'INSERT INTO users (org_id, email, password_hash, full_name, role) VALUES (?, ?, ?, ?, ?)',
      [1, REGULAR_USER_EMAIL, REGULAR_USER_HASH, 'Playwright Regular', 'user']
    );
    // Enable evaluation board for test org so UI smoke tests can verify feature elements
    await conn.execute('UPDATE subscriptions SET has_evaluation_board = 1 WHERE org_id = 1');
    console.log('[globalSetup] regular test user created');
  } catch (err) {
    throw new Error(`[globalSetup] DB setup failed — is Docker running on port 3307? ${err.message}`);
  } finally {
    if (conn) await conn.end();
  }
}

module.exports = globalSetup;
