-- Migration 008: Performance Indexes

CREATE INDEX idx_users_org ON users(org_id);
CREATE INDEX idx_projects_org ON projects(org_id);
CREATE INDEX idx_hlwi_project ON hl_work_items(project_id);
CREATE INDEX idx_stories_project ON user_stories(project_id);
CREATE INDEX idx_stories_parent ON user_stories(parent_hl_item_id);
CREATE INDEX idx_docs_project ON documents(project_id);
CREATE INDEX idx_risks_project ON risks(project_id);
CREATE INDEX idx_sprints_project ON sprints(project_id);
CREATE INDEX idx_diagrams_project ON strategy_diagrams(project_id);
