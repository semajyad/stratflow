const mysql = require('mysql2/promise');
const { REGULAR_USER_EMAIL, DB_CONFIG, ADMIN_EMAIL } = require('./test-constants');

async function globalTeardown() {
  let conn;
  try {
    conn = await mysql.createConnection(DB_CONFIG);
    await conn.execute('DELETE FROM users WHERE email = ?', [REGULAR_USER_EMAIL]);
    console.log('[globalTeardown] regular test user removed');
    await conn.execute("DELETE FROM login_attempts WHERE identifier = ?", ['pw_regular@test.invalid']);
    await conn.execute("DELETE FROM login_attempts WHERE identifier = ?", [ADMIN_EMAIL]);
    console.log('[globalTeardown] login_attempts cleared for test users');
  } catch (err) {
    console.error(`[globalTeardown] DB teardown failed: ${err.message}`);
    // Don't throw — test results are already recorded; teardown failure shouldn't mask them
  } finally {
    if (conn) await conn.end();
  }
}

module.exports = globalTeardown;
