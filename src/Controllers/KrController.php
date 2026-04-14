<?php

declare(strict_types=1);

namespace StratFlow\Controllers;

use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\Request;
use StratFlow\Core\Response;
use StratFlow\Models\KeyResult;

class KrController
{
    protected Request $request;
    protected Response $response;
    protected Auth $auth;
    protected Database $db;
    protected array $config;
    public function __construct(Request $request, Response $response, Auth $auth, Database $db, array $config)
    {
        $this->request  = $request;
        $this->response = $response;
        $this->auth     = $auth;
        $this->db       = $db;
        $this->config   = $config;
    }

    private const VALID_STATUSES = ['not_started', 'on_track', 'at_risk', 'off_track', 'achieved'];
// ===========================
    // ACTIONS
    // ===========================

    /**
     * POST /app/key-results
     * Create a new KR for a work item. Verifies work item belongs to session org.
     */
    public function store(): void
    {
        header('Content-Type: application/json');
        $orgId      = (int) $this->auth->user()['org_id'];
        $workItemId = (int) $this->request->post('hl_work_item_id', 0);
        if ($workItemId === 0) {
            http_response_code(400);
            echo json_encode(['error' => 'High Level Work Item ID required']);
            return;
        }

        if (!$this->workItemBelongsToOrg($workItemId, $orgId)) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            return;
        }

        $title = trim((string) $this->request->post('title', ''));
        if ($title === '') {
            http_response_code(400);
            echo json_encode(['error' => 'title required']);
            return;
        }

        $statusRaw = $this->request->post('status', 'not_started');
        if (is_array($statusRaw) || !is_scalar($statusRaw)) {
            $statusRaw = 'not_started';
        }

        $id = KeyResult::create($this->db, [
            'org_id'             => $orgId,
            'hl_work_item_id'    => $workItemId,
            'title'              => $title,
            'metric_description' => trim((string) $this->request->post('metric_description', '')) ?: null,
            'baseline_value'     => $this->request->post('baseline_value', ''),
            'target_value'       => $this->request->post('target_value', ''),
            'current_value'      => $this->request->post('current_value', ''),
            'unit'               => trim((string) $this->request->post('unit', '')) ?: null,
            'status'             => $this->sanitiseStatus((string) $statusRaw),
            'display_order'      => (int) $this->request->post('display_order', 0),
        ]);
        echo json_encode(['ok' => true, 'id' => $id]);
    }

    /**
     * POST /app/key-results/{id}
     * Update an existing KR. Only fields present in the request body are changed.
     */
    public function update(int $id): void
    {
        header('Content-Type: application/json');
        $orgId = (int) $this->auth->user()['org_id'];
        $kr = KeyResult::findById($this->db, $id, $orgId);
        if ($kr === null) {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            return;
        }

        $data = [];
        foreach (
            ['title', 'metric_description', 'baseline_value', 'target_value',
                  'current_value', 'unit', 'status', 'display_order'] as $field
        ) {
            $val = $this->request->post($field);
            if ($val !== null) {
                $data[$field] = $val;
            }
        }

        if (isset($data['status'])) {
            $statusRaw = $data['status'];
            if (is_array($statusRaw) || !is_scalar($statusRaw)) {
                $statusRaw = 'not_started';
            }
            $data['status'] = $this->sanitiseStatus((string) $statusRaw);
        }

        KeyResult::update($this->db, $id, $orgId, $data);
        echo json_encode(['ok' => true]);
    }

    /**
     * POST /app/key-results/{id}/delete
     * Delete a KR. Returns 404 if not found or belongs to another org.
     */
    public function delete(int $id): void
    {
        header('Content-Type: application/json');
        $orgId = (int) $this->auth->user()['org_id'];
        $kr = KeyResult::findById($this->db, $id, $orgId);
        if ($kr === null) {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            return;
        }

        KeyResult::delete($this->db, $id, $orgId);
        echo json_encode(['ok' => true]);
    }

    // ===========================
    // PRIVATE HELPERS
    // ===========================

    /**
     * Validate a status string against the allowed enum values.
     *
     * Returns the input unchanged if it is valid, or 'not_started' as the
     * safe default for any unrecognised value.
     *
     * @param string $raw User-supplied status value
     * @return string     One of the five valid status strings
     */
    private function sanitiseStatus(string $raw): string
    {
        return in_array($raw, self::VALID_STATUSES, true) ? $raw : 'not_started';
    }

    private function workItemBelongsToOrg(int $workItemId, int $orgId): bool
    {
        $row = $this->db->query("SELECT p.org_id
               FROM hl_work_items hwi
               JOIN projects p ON hwi.project_id = p.id
              WHERE hwi.id = :id LIMIT 1", [':id' => $workItemId])->fetch();
        return $row !== false && (int) $row['org_id'] === $orgId;
    }
}
