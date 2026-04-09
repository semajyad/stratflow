-- Migration 019: Xero Integration
-- Extends integrations.provider ENUM to include github, gitlab, and xero.
-- Creates xero_invoices table for local invoice caching.

-- Extend the provider enum to include git providers and Xero
ALTER TABLE integrations
    MODIFY COLUMN provider ENUM('jira','azure_devops','github','gitlab','xero') NOT NULL;

-- Local cache of Xero invoices so the invoices page is fast without
-- making an API call on every page load. Refreshed on webhook or manual sync.
CREATE TABLE IF NOT EXISTS xero_invoices (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org_id INT UNSIGNED NOT NULL,
    xero_invoice_id VARCHAR(36) NOT NULL COMMENT 'Xero UUID',
    invoice_number VARCHAR(100) NULL,
    contact_name VARCHAR(255) NULL,
    status ENUM('DRAFT','SUBMITTED','AUTHORISED','PAID','VOIDED','DELETED') NOT NULL DEFAULT 'DRAFT',
    currency_code VARCHAR(10) NOT NULL DEFAULT 'NZD',
    amount_due DECIMAL(12,2) NOT NULL DEFAULT 0,
    amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
    total DECIMAL(12,2) NOT NULL DEFAULT 0,
    invoice_date DATE NULL,
    due_date DATE NULL,
    reference VARCHAR(255) NULL COMMENT 'StratFlow subscription or project ref',
    xero_url VARCHAR(512) NULL COMMENT 'Link to invoice in Xero',
    synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_xero_invoice (org_id, xero_invoice_id),
    KEY idx_org_status (org_id, status),
    FOREIGN KEY (org_id) REFERENCES organisations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
