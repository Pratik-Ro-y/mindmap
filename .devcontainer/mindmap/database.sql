-- Enhanced MindMap Generator Database Schema
CREATE DATABASE mindmap_platform;
USE mindmap_platform;

-- Users table with enhanced features
CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    profile_image VARCHAR(255) DEFAULT NULL,
    subscription_type ENUM('free', 'premium', 'enterprise') DEFAULT 'free',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    is_active BOOLEAN DEFAULT TRUE,
    google_id VARCHAR(255) NULL,
    reset_token VARCHAR(255) NULL,
    reset_token_expires TIMESTAMP NULL
);

-- MindMaps table with enhanced features
CREATE TABLE mindmaps (
    map_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    thumbnail VARCHAR(255) NULL,
    is_public BOOLEAN DEFAULT FALSE,
    is_template BOOLEAN DEFAULT FALSE,
    category_id INT NULL,
    theme VARCHAR(50) DEFAULT 'default',
    canvas_width INT DEFAULT 2000,
    canvas_height INT DEFAULT 1500,
    zoom_level DECIMAL(3,2) DEFAULT 1.00,
    center_x INT DEFAULT 1000,
    center_y INT DEFAULT 750,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_accessed TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    version INT DEFAULT 1,
    is_archived BOOLEAN DEFAULT FALSE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- Categories for organizing mindmaps
CREATE TABLE categories (
    category_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    color VARCHAR(7) DEFAULT '#007bff',
    icon VARCHAR(50) DEFAULT 'folder',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Enhanced Nodes table
CREATE TABLE nodes (
    node_id INT AUTO_INCREMENT PRIMARY KEY,
    map_id INT NOT NULL,
    parent_id INT NULL,
    node_text VARCHAR(500) NOT NULL,
    node_type ENUM('central', 'main', 'sub', 'leaf') DEFAULT 'main',
    color VARCHAR(7) DEFAULT '#007bff',
    background_color VARCHAR(7) DEFAULT '#ffffff',
    text_color VARCHAR(7) DEFAULT '#000000',
    position_x DECIMAL(10,2) NOT NULL,
    position_y DECIMAL(10,2) NOT NULL,
    width INT DEFAULT 150,
    height INT DEFAULT 50,
    font_size INT DEFAULT 14,
    font_weight ENUM('normal', 'bold') DEFAULT 'normal',
    border_radius INT DEFAULT 8,
    border_width INT DEFAULT 2,
    icon VARCHAR(50) NULL,
    image_url VARCHAR(255) NULL,
    priority ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    status ENUM('pending', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
    due_date DATE NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    order_index INT DEFAULT 0,
    is_collapsed BOOLEAN DEFAULT FALSE,
    FOREIGN KEY (map_id) REFERENCES mindmaps(map_id) ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES nodes(node_id) ON DELETE CASCADE
);

-- Connection styles between nodes
CREATE TABLE connections (
    connection_id INT AUTO_INCREMENT PRIMARY KEY,
    from_node_id INT NOT NULL,
    to_node_id INT NOT NULL,
    connection_type ENUM('straight', 'curved', 'bezier') DEFAULT 'curved',
    color VARCHAR(7) DEFAULT '#666666',
    thickness INT DEFAULT 2,
    style ENUM('solid', 'dashed', 'dotted') DEFAULT 'solid',
    label VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (from_node_id) REFERENCES nodes(node_id) ON DELETE CASCADE,
    FOREIGN KEY (to_node_id) REFERENCES nodes(node_id) ON DELETE CASCADE
);

-- Tags system
CREATE TABLE tags (
    tag_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) UNIQUE NOT NULL,
    color VARCHAR(7) DEFAULT '#6c757d',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Many-to-many relationship between nodes and tags
CREATE TABLE node_tags (
    node_id INT NOT NULL,
    tag_id INT NOT NULL,
    PRIMARY KEY (node_id, tag_id),
    FOREIGN KEY (node_id) REFERENCES nodes(node_id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES tags(tag_id) ON DELETE CASCADE
);

-- Collaboration features
CREATE TABLE collaborators (
    collaboration_id INT AUTO_INCREMENT PRIMARY KEY,
    map_id INT NOT NULL,
    user_id INT NOT NULL,
    permission ENUM('view', 'edit', 'admin') DEFAULT 'view',
    invited_by INT NOT NULL,
    invited_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    accepted_at TIMESTAMP NULL,
    status ENUM('pending', 'accepted', 'declined') DEFAULT 'pending',
    FOREIGN KEY (map_id) REFERENCES mindmaps(map_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (invited_by) REFERENCES users(user_id) ON DELETE CASCADE,
    UNIQUE KEY unique_collaboration (map_id, user_id)
);

-- Version history for mindmaps
CREATE TABLE mindmap_versions (
    version_id INT AUTO_INCREMENT PRIMARY KEY,
    map_id INT NOT NULL,
    version_number INT NOT NULL,
    data JSON NOT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    description VARCHAR(255) NULL,
    FOREIGN KEY (map_id) REFERENCES mindmaps(map_id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE CASCADE
);

-- Comments system
CREATE TABLE comments (
    comment_id INT AUTO_INCREMENT PRIMARY KEY,
    node_id INT NOT NULL,
    user_id INT NOT NULL,
    comment_text TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_resolved BOOLEAN DEFAULT FALSE,
    parent_comment_id INT NULL,
    FOREIGN KEY (node_id) REFERENCES nodes(node_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (parent_comment_id) REFERENCES comments(comment_id) ON DELETE CASCADE
);

-- Templates system
CREATE TABLE templates (
    template_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    category VARCHAR(100) NULL,
    data JSON NOT NULL,
    created_by INT NOT NULL,
    is_public BOOLEAN DEFAULT TRUE,
    usage_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE CASCADE
);

-- Activity logs
CREATE TABLE activity_logs (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    map_id INT NULL,
    action VARCHAR(100) NOT NULL,
    details JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (map_id) REFERENCES mindmaps(map_id) ON DELETE SET NULL
);

-- AI suggestions cache
CREATE TABLE ai_suggestions (
    suggestion_id INT AUTO_INCREMENT PRIMARY KEY,
    topic VARCHAR(255) NOT NULL,
    suggestions JSON NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    usage_count INT DEFAULT 0,
    INDEX idx_topic (topic)
);

-- Insert default categories
INSERT INTO categories (name, color, icon) VALUES
('Business', '#007bff', 'briefcase'),
('Education', '#28a745', 'graduation-cap'),
('Personal', '#ffc107', 'user'),
('Project', '#6f42c1', 'folder-open'),
('Creative', '#e91e63', 'palette'),
('Research', '#20c997', 'search');

-- Insert default tags
INSERT INTO tags (name, color) VALUES
('Important', '#dc3545'),
('Urgent', '#fd7e14'),
('Ideas', '#20c997'),
('Tasks', '#6610f2'),
('Goals', '#198754'),
('Notes', '#6c757d');

-- Insert sample templates
INSERT INTO templates (name, description, category, data, created_by) VALUES
('SWOT Analysis', 'Strengths, Weaknesses, Opportunities, Threats analysis template', 'Business', 
'{"nodes": [{"text": "SWOT Analysis", "type": "central"}, {"text": "Strengths", "type": "main"}, {"text": "Weaknesses", "type": "main"}, {"text": "Opportunities", "type": "main"}, {"text": "Threats", "type": "main"}]}', 1),
('Project Planning', 'Basic project planning mind map template', 'Project',
'{"nodes": [{"text": "Project Name", "type": "central"}, {"text": "Objectives", "type": "main"}, {"text": "Resources", "type": "main"}, {"text": "Timeline", "type": "main"}, {"text": "Risks", "type": "main"}]}', 1);