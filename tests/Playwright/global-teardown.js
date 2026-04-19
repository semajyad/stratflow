const mysql = require('mysql2/promise');
const { REGULAR_USER_EMAIL, DB_CONFIG } = require('./test-constants');

async function globalTeardown() {
  const baseUrl = process.env.BASE_URL || 'http://localhost:8890';
  let hostname;
  try {
    hostname = new URL(baseUrl).hostname;
  } catch (err) {
    console.error(`[globalTeardown] Invalid BASE_URL: ${baseUrl}`);
    return;
  }
  const isLocal = hostname === 'localhost' || hostname === '127.0.0.1' || hostname === '::1';
  if (!isLocal) {
    console.log('[globalTeardown] staging URL detected - skipping local DB teardown');
    return;
  }

  let conn;
  try {
    conn = await mysql.createConnection(DB_CONFIG);
    await conn.execute('DELETE FROM users WHERE email = ?', [REGULAR_USER_EMAIL]);
    // login_attempts tracks by ip_address — clear all to prevent rate-limiter buildup across runs
    await conn.execute('DELETE FROM login_attempts');
    console.log('[globalTeardown] regular test user and login_attempts cleared');
  } catch (err) {
    console.error(`[globalTeardown] DB teardown failed: ${err.message}`);
    // Don't throw — test results are already recorded; teardown failure shouldn't mask them
  } finally {
    if (conn) await conn.end();
  }
}

module.exports = globalTeardown;
