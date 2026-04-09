// Shared constants for Playwright globalSetup and globalTeardown
module.exports = {
  REGULAR_USER_EMAIL: 'pw_regular@test.invalid',
  REGULAR_USER_HASH: '$2y$12$iu6uq/e8YF48/fBVtgVgvOcavOH1KoGCGLTMfjxRDCy0aZrZgMor6', // bcrypt of "password123" — keep in sync with database/seed.sql
  ADMIN_EMAIL: 'admin@stratflow.test',
  ADMIN_PASS: 'password123',
  REGULAR_EMAIL: 'pw_regular@test.invalid',
  REGULAR_PASS: 'password123',
  DB_CONFIG: {
    host: '127.0.0.1',
    port: 3307,
    user: 'stratflow',
    password: 'stratflow_secret',
    database: 'stratflow',
  },
};
