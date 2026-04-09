const mysql = require('mysql2/promise');

async function globalTeardown() {
  const conn = await mysql.createConnection({
    host: '127.0.0.1',
    port: 3307,
    user: 'stratflow',
    password: 'stratflow_secret',
    database: 'stratflow',
  });

  await conn.execute('DELETE FROM users WHERE email = ?', ['pw_regular@test.invalid']);
  await conn.end();
  console.log('[globalTeardown] regular test user removed');
}

module.exports = globalTeardown;
