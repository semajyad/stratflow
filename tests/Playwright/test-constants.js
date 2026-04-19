// Shared constants for Playwright globalSetup and globalTeardown
const BASE_URL = process.env.BASE_URL || 'http://localhost:8890';

function isLocalBaseUrl(baseUrl = BASE_URL) {
  try {
    const hostname = new URL(baseUrl).hostname;
    return hostname === 'localhost' || hostname === '127.0.0.1' || hostname === '::1';
  } catch (_) {
    return false;
  }
}

function canUseDb(baseUrl = BASE_URL) {
  return process.env.E2E_DB_ACCESS === 'true' || isLocalBaseUrl(baseUrl);
}

module.exports = {
  BASE_URL,
  isLocalBaseUrl,
  canUseDb,
  REGULAR_USER_EMAIL: 'pw_regular@test.invalid',
  REGULAR_USER_HASH: '$2y$12$iu6uq/e8YF48/fBVtgVgvOcavOH1KoGCGLTMfjxRDCy0aZrZgMor6', // bcrypt of "password123" — keep in sync with database/seed.sql
  ADMIN_EMAIL: process.env.E2E_ADMIN_EMAIL || process.env.E2E_EMAIL || 'admin@stratflow.test',
  ADMIN_PASS: process.env.E2E_ADMIN_PASSWORD || process.env.E2E_PASSWORD || 'password123',
  REGULAR_EMAIL: process.env.E2E_REGULAR_EMAIL || process.env.E2E_EMAIL || 'pw_regular@test.invalid',
  REGULAR_PASS: process.env.E2E_REGULAR_PASSWORD || process.env.E2E_PASSWORD || 'password123',
  DB_CONFIG: {
    host: process.env.DB_HOST || '127.0.0.1',
    port: parseInt(process.env.DB_PORT || '3307', 10),
    user: process.env.DB_USERNAME || 'stratflow',
    password: process.env.DB_PASSWORD || 'stratflow_secret',
    database: process.env.DB_DATABASE || 'stratflow',
  },
};
