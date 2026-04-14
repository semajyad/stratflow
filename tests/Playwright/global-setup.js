// global-setup.js — creates a regular (non-admin) test user before the suite runs.
const mysql = require('mysql2/promise');
const { REGULAR_USER_EMAIL, REGULAR_USER_HASH, DB_CONFIG } = require('./test-constants');

async function globalSetup() {
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
    console.log('[globalSetup] regular test user created');
  } catch (err) {
    throw new Error(`[globalSetup] DB setup failed — is Docker running on port 3307? ${err.message}`);
  } finally {
    if (conn) await conn.end();
  }
}

module.exports = globalSetup;
