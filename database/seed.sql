-- Test organisation
INSERT INTO organisations (id, name, stripe_customer_id, is_active) VALUES
(1, 'ThreePoints Demo', 'cus_test_123', 1);

-- Test user: admin@stratflow.test / password123
INSERT INTO users (id, org_id, email, password_hash, full_name, role, account_type) VALUES
(1, 1, 'admin@stratflow.test', '$2y$12$iu6uq/e8YF48/fBVtgVgvOcavOH1KoGCGLTMfjxRDCy0aZrZgMor6', 'Admin User', 'org_admin', 'org_admin');

-- Test subscription
INSERT INTO subscriptions (org_id, stripe_subscription_id, plan_type, status, started_at) VALUES
(1, 'sub_test_123', 'product', 'active', NOW());

-- Test project
INSERT INTO projects (id, org_id, name, status, created_by) VALUES
(1, 1, 'Demo Strategy Project', 'active', 1);
