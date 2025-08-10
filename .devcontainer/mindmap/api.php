<?php
require_once 'config.php';
require_once 'auth.php';

/**
 * MindMap Management Class
 */
class MindMapAPI {
    private $conn;
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    /**
     * Create new mindmap
     */
    public function createMindMap($user_id, $data) {
        // Check user's mindmap limit based on subscription
        $this->checkMindMapLimit($user_id);
        
        $query = "INSERT INTO mindmaps 
                  SET user_id=:user_id, title=:title, description=:description, 
                      category_id=:category_id, theme=:theme, is_public=:is_public";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(":user_id", $user_id);
        $stmt->bindParam(":title", $data['title']);
        $stmt->bindParam(":description", $data['description'] ?? '');
        $stmt->bindParam(":category_id", $data['category_id'] ?? null);
        $stmt->bindParam(":theme", $data['theme'] ?? 'default');
        $stmt->bindParam(":is_public", $data['is_public'] ?? false, PDO::PARAM_BOOL);
        
        if ($stmt->execute()) {
            $map_id = $this->conn->lastInsertId();
            
            // Create initial central node if provided
            if (isset($data['central_node'])) {
                $this->createNode($map_id, [
                    'node_text' => $data['central_node'],
                    'node_type' => 'central',
                    'position_x' => 1000,
                    'position_y' => 750,
                    'color' => '#007bff'
                ]);
            }
            
            logActivity($user_id, 'mindmap_created', $map_id);
           return $map_id;
    }
    
    throw new Exception("Failed to create mindmap");
}
    
    /**
     * Check if user is owner
     */
    private function isOwner($map_id, $user_id) {
        $query = "SELECT 1 FROM mindmaps WHERE map_id = :map_id AND user_id = :user_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":map_id", $map_id);
        $stmt->bindParam(":user_id", $user_id);
        $stmt->execute();
        
        return $stmt->fetch() !== false;
    }
    
    /**
     * Check user's mindmap limit
     */
    private function checkMindMapLimit($user_id) {
        // Get user's subscription type
        $query = "SELECT subscription_type FROM users WHERE user_id = :user_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":user_id", $user_id);
        $stmt->execute();
        
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            throw new Exception("User not found");
        }
        
        // Get current mindmap count
        $query = "SELECT COUNT(*) as count FROM mindmaps WHERE user_id = :user_id AND is_archived = 0";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":user_id", $user_id);
        $stmt->execute();
        
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        $limits = [
            'free' => MAX_MINDMAPS_FREE,
            'premium' => MAX_MINDMAPS_PREMIUM,
            'enterprise' => MAX_MINDMAPS_ENTERPRISE
        ];
        
        $limit = $limits[$user['subscription_type']];
        
        if ($limit != -1 && $count >= $limit) {
            throw new Exception("Mindmap limit reached for {$user['subscription_type']} subscription");
        }
    }
    
    /**
     * Update last accessed timestamp
     */
    private function updateLastAccessed($map_id) {
        $query = "UPDATE mindmaps SET last_accessed = NOW() WHERE map_id = :map_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":map_id", $map_id);
        $stmt->execute();
    }
    
    /**
     * Duplicate mindmap
     */
    public function duplicateMindMap($map_id, $user_id, $new_title) {
        if (!$this->hasAccessToMindMap($map_id, $user_id)) {
            throw new Exception("Access denied to this mindmap");
        }
        
        // Check user's mindmap limit
        $this->checkMindMapLimit($user_id);
        
        // Get original mindmap
        $query = "SELECT * FROM mindmaps WHERE map_id = :map_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":map_id", $map_id);
        $stmt->execute();
        
        $original = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$original) {
            throw new Exception("Original mindmap not found");
        }
        
        // Create new mindmap
        $query = "INSERT INTO mindmaps 
                  SET user_id=:user_id, title=:title, description=:description,
                      category_id=:category_id, theme=:theme, canvas_width=:canvas_width,
                      canvas_height=:canvas_height, zoom_level=:zoom_level,
                      center_x=:center_x, center_y=:center_y";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":user_id", $user_id);
        $stmt->bindParam(":title", $new_title);
        $stmt->bindParam(":description", $original['description']);
        $stmt->bindParam(":category_id", $original['category_id']);
        $stmt->bindParam(":theme", $original['theme']);
        $stmt->bindParam(":canvas_width", $original['canvas_width']);
        $stmt->bindParam(":canvas_height", $original['canvas_height']);
        $stmt->bindParam(":zoom_level", $original['zoom_level']);
        $stmt->bindParam(":center_x", $original['center_x']);
        $stmt->bindParam(":center_y", $original['center_y']);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to duplicate mindmap");
        }
        
        $new_map_id = $this->conn->lastInsertId();
        
        // Copy nodes
        $this->duplicateNodes($map_id, $new_map_id);
        
        logActivity($user_id, 'mindmap_duplicated', $new_map_id);
        
        return $new_map_id;
    }
    
    /**
     * Duplicate nodes for a mindmap
     */
    private function duplicateNodes($original_map_id, $new_map_id) {
        $node_mapping = [];
        
        // Get all nodes from original mindmap
        $query = "SELECT * FROM nodes WHERE map_id = :map_id ORDER BY node_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":map_id", $original_map_id);
        $stmt->execute();
        
        $nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Create new nodes
        foreach ($nodes as $node) {
            $query = "INSERT INTO nodes 
                      SET map_id=:map_id, parent_id=:parent_id, node_text=:node_text,
                          node_type=:node_type, color=:color, background_color=:background_color,
                          text_color=:text_color, position_x=:position_x, position_y=:position_y,
                          width=:width, height=:height, font_size=:font_size, font_weight=:font_weight,
                          icon=:icon, priority=:priority, status=:status, due_date=:due_date,
                          notes=:notes, order_index=:order_index";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":map_id", $new_map_id);
            $stmt->bindParam(":parent_id", null); // Will update later
            $stmt->bindParam(":node_text", $node['node_text']);
            $stmt->bindParam(":node_type", $node['node_type']);
            $stmt->bindParam(":color", $node['color']);
            $stmt->bindParam(":background_color", $node['background_color']);
            $stmt->bindParam(":text_color", $node['text_color']);
            $stmt->bindParam(":position_x", $node['position_x']);
            $stmt->bindParam(":position_y", $node['position_y']);
            $stmt->bindParam(":width", $node['width']);
            $stmt->bindParam(":height", $node['height']);
            $stmt->bindParam(":font_size", $node['font_size']);
            $stmt->bindParam(":font_weight", $node['font_weight']);
            $stmt->bindParam(":icon", $node['icon']);
            $stmt->bindParam(":priority", $node['priority']);
            $stmt->bindParam(":status", $node['status']);
            $stmt->bindParam(":due_date", $node['due_date']);
            $stmt->bindParam(":notes", $node['notes']);
            $stmt->bindParam(":order_index", $node['order_index']);
            
            if ($stmt->execute()) {
                $new_node_id = $this->conn->lastInsertId();
                $node_mapping[$node['node_id']] = $new_node_id;
            }
        }
        
        // Update parent_id relationships
        foreach ($nodes as $node) {
            if ($node['parent_id'] && isset($node_mapping[$node['parent_id']])) {
                $query = "UPDATE nodes SET parent_id = :parent_id WHERE node_id = :node_id";
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(":parent_id", $node_mapping[$node['parent_id']]);
                $stmt->bindParam(":node_id", $node_mapping[$node['node_id']]);
                $stmt->execute();
            }
        }
    }
    
    /**
     * Export mindmap data
     */
    public function exportMindMap($map_id, $user_id, $format = 'json') {
        if (!$this->hasAccessToMindMap($map_id, $user_id)) {
            throw new Exception("Access denied to this mindmap");
        }
        
        $mindmap = $this->getMindMap($map_id, $user_id);
        
        switch ($format) {
            case 'json':
                return json_encode($mindmap, JSON_PRETTY_PRINT);
                
            case 'xml':
                return $this->convertToXML($mindmap);
                
            default:
                throw new Exception("Unsupported export format");
        }
    }
    
    /**
     * Convert mindmap to XML
     */
    private function convertToXML($mindmap) {
        $xml = new SimpleXMLElement('<mindmap></mindmap>');
        
        $xml->addAttribute('id', $mindmap['map_id']);
        $xml->addAttribute('title', $mindmap['title']);
        $xml->addAttribute('created', $mindmap['created_at']);
        
        $nodes_element = $xml->addChild('nodes');
        
        foreach ($mindmap['nodes'] as $node) {
            $node_element = $nodes_element->addChild('node');
            $node_element->addAttribute('id', $node['node_id']);
            $node_element->addAttribute('parent_id', $node['parent_id'] ?? '');
            $node_element->addAttribute('type', $node['node_type']);
            $node_element->addChild('text', htmlspecialchars($node['node_text']));
            $node_element->addChild('x', $node['position_x']);
            $node_element->addChild('y', $node['position_y']);
            $node_element->addChild('color', $node['color']);
        }
        
        return $xml->asXML();
    }
}

// API Endpoints
setCORSHeaders();

$database = new Database();
$db = $database->getConnection();
$mindmapAPI = new MindMapAPI($db);

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents("php://input"), true);

try {
    $user = requireAuth();
    
    switch ($method) {
        case 'POST':
            switch ($action) {
                case 'create':
                    if (!isset($input['title'])) {
                        sendResponse(false, 'Title is required', null, 400);
                    }
                    
                    $map_id = $mindmapAPI->createMindMap($user['user_id'], $input);
                    sendResponse(true, 'Mindmap created successfully', ['map_id' => $map_id]);
                    break;
                    
                case 'duplicate':
                    $map_id = $_GET['map_id'] ?? null;
                    if (!$map_id || !isset($input['title'])) {
                        sendResponse(false, 'Map ID and title are required', null, 400);
                    }
                    
                    $new_map_id = $mindmapAPI->duplicateMindMap($map_id, $user['user_id'], $input['title']);
                    sendResponse(true, 'Mindmap duplicated successfully', ['map_id' => $new_map_id]);
                    break;
                    
                case 'create-node':
                    $map_id = $_GET['map_id'] ?? null;
                    if (!$map_id || !isset($input['node_text'], $input['position_x'], $input['position_y'])) {
                        sendResponse(false, 'Missing required node data', null, 400);
                    }
                    
                    $node_id = $mindmapAPI->createNode($map_id, $input);
                    sendResponse(true, 'Node created successfully', ['node_id' => $node_id]);
                    break;
                    
                default:
                    sendResponse(false, 'Invalid action', null, 400);
            }
            break;
            
        case 'GET':
            switch ($action) {
                case 'get':
                    $map_id = $_GET['map_id'] ?? null;
                    if (!$map_id) {
                        sendResponse(false, 'Map ID is required', null, 400);
                    }
                    
                    $mindmap = $mindmapAPI->getMindMap($map_id, $user['user_id']);
                    sendResponse(true, 'Mindmap retrieved successfully', $mindmap);
                    break;
                    
                case 'list':
                    $filters = [
                        'category_id' => $_GET['category_id'] ?? null,
                        'is_archived' => isset($_GET['archived']) ? (bool)$_GET['archived'] : false,
                        'search' => $_GET['search'] ?? null
                    ];
                    
                    $mindmaps = $mindmapAPI->getUserMindMaps($user['user_id'], $filters);
                    sendResponse(true, 'Mindmaps retrieved successfully', $mindmaps);
                    break;
                    
                case 'export':
                    $map_id = $_GET['map_id'] ?? null;
                    $format = $_GET['format'] ?? 'json';
                    
                    if (!$map_id) {
                        sendResponse(false, 'Map ID is required', null, 400);
                    }
                    
                    $exported_data = $mindmapAPI->exportMindMap($map_id, $user['user_id'], $format);
                    
                    if ($format === 'xml') {
                        header('Content-Type: application/xml');
                    } else {
                        header('Content-Type: application/json');
                    }
                    
                    echo $exported_data;
                    exit;
                    
                default:
                    sendResponse(false, 'Invalid action', null, 400);
            }
            break;
            
        case 'PUT':
            switch ($action) {
                case 'update':
                    $map_id = $_GET['map_id'] ?? null;
                    if (!$map_id || empty($input)) {
                        sendResponse(false, 'Map ID and data are required', null, 400);
                    }
                    
                    $mindmapAPI->updateMindMap($map_id, $user['user_id'], $input);
                    sendResponse(true, 'Mindmap updated successfully');
                    break;
                    
                case 'update-node':
                    $node_id = $_GET['node_id'] ?? null;
                    if (!$node_id || empty($input)) {
                        sendResponse(false, 'Node ID and data are required', null, 400);
                    }
                    
                    $mindmapAPI->updateNode($node_id, $input);
                    sendResponse(true, 'Node updated successfully');
                    break;
                    
                case 'archive':
                    $map_id = $_GET['map_id'] ?? null;
                    if (!$map_id) {
                        sendResponse(false, 'Map ID is required', null, 400);
                    }
                    
                    $archive = isset($input['archive']) ? $input['archive'] : true;
                    $mindmapAPI->archiveMindMap($map_id, $user['user_id'], $archive);
                    
                    $message = $archive ? 'Mindmap archived successfully' : 'Mindmap unarchived successfully';
                    sendResponse(true, $message);
                    break;
                    
                default:
                    sendResponse(false, 'Invalid action', null, 400);
            }
            break;
            
        case 'DELETE':
            switch ($action) {
                case 'delete':
                    $map_id = $_GET['map_id'] ?? null;
                    if (!$map_id) {
                        sendResponse(false, 'Map ID is required', null, 400);
                    }
                    
                    $mindmapAPI->deleteMindMap($map_id, $user['user_id']);
                    sendResponse(true, 'Mindmap deleted successfully');
                    break;
                    
                case 'delete-node':
                    $node_id = $_GET['node_id'] ?? null;
                    if (!$node_id) {
                        sendResponse(false, 'Node ID is required', null, 400);
                    }
                    
                    $mindmapAPI->deleteNode($node_id);
                    sendResponse(true, 'Node deleted successfully');
                    break;
                    
                default:
                    sendResponse(false, 'Invalid action', null, 400);
            }
            break;
            
        default:
            sendResponse(false, 'Method not allowed', null, 405);
    }
    
} catch (Exception $e) {
    sendResponse(false, $e->getMessage(), null, 400);
}
?>map_id;
        }
        
        throw new Exception("Failed to create mindmap");
    }
    
    /**
     * Get mindmap with all nodes
     */
    public function getMindMap($map_id, $user_id) {
        // Check if user has access to this mindmap
        if (!$this->hasAccessToMindMap($map_id, $user_id)) {
            throw new Exception("Access denied to this mindmap");
        }
        
        // Get mindmap details
        $query = "SELECT m.*, c.name as category_name, c.color as category_color,
                         u.username as owner_username
                  FROM mindmaps m
                  LEFT JOIN categories c ON m.category_id = c.category_id
                  LEFT JOIN users u ON m.user_id = u.user_id
                  WHERE m.map_id = :map_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":map_id", $map_id);
        $stmt->execute();
        
        $mindmap = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$mindmap) {
            throw new Exception("Mindmap not found");
        }
        
        // Get all nodes
        $mindmap['nodes'] = $this->getNodes($map_id);
        
        // Get connections
        $mindmap['connections'] = $this->getConnections($map_id);
        
        // Get collaborators
        $mindmap['collaborators'] = $this->getCollaborators($map_id);
        
        // Update last accessed
        $this->updateLastAccessed($map_id);
        
        logActivity($user_id, 'mindmap_viewed', $map_id);
        
        return $mindmap;
    }
    
    /**
     * Update mindmap
     */
    public function updateMindMap($map_id, $user_id, $data) {
        if (!$this->hasEditAccess($map_id, $user_id)) {
            throw new Exception("No edit permission for this mindmap");
        }
        
        $allowed_fields = ['title', 'description', 'category_id', 'theme', 'is_public', 
                          'canvas_width', 'canvas_height', 'zoom_level', 'center_x', 'center_y'];
        
        $update_fields = [];
        $params = [];
        
        foreach ($data as $field => $value) {
            if (in_array($field, $allowed_fields)) {
                $update_fields[] = "$field = :$field";
                $params[":$field"] = $value;
            }
        }
        
        if (empty($update_fields)) {
            throw new Exception("No valid fields to update");
        }
        
        $update_fields[] = "updated_at = NOW()";
        $update_fields[] = "version = version + 1";
        
        $query = "UPDATE mindmaps SET " . implode(', ', $update_fields) . " 
                  WHERE map_id = :map_id";
        
        $params[':map_id'] = $map_id;
        $stmt = $this->conn->prepare($query);
        
        if ($stmt->execute($params)) {
            logActivity($user_id, 'mindmap_updated', $map_id, $data);
            return true;
        }
        
        throw new Exception("Failed to update mindmap");
    }
    
    /**
     * Delete mindmap
     */
    public function deleteMindMap($map_id, $user_id) {
        if (!$this->isOwner($map_id, $user_id)) {
            throw new Exception("Only owner can delete mindmap");
        }
        
        $query = "DELETE FROM mindmaps WHERE map_id = :map_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":map_id", $map_id);
        
        if ($stmt->execute()) {
            logActivity($user_id, 'mindmap_deleted', $map_id);
            return true;
        }
        
        throw new Exception("Failed to delete mindmap");
    }
    
    /**
     * Archive/Unarchive mindmap
     */
    public function archiveMindMap($map_id, $user_id, $archive = true) {
        if (!$this->hasEditAccess($map_id, $user_id)) {
            throw new Exception("No edit permission for this mindmap");
        }
        
        $query = "UPDATE mindmaps SET is_archived = :archive WHERE map_id = :map_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":archive", $archive, PDO::PARAM_BOOL);
        $stmt->bindParam(":map_id", $map_id);
        
        if ($stmt->execute()) {
            $action = $archive ? 'mindmap_archived' : 'mindmap_unarchived';
            logActivity($user_id, $action, $map_id);
            return true;
        }
        
        throw new Exception("Failed to archive/unarchive mindmap");
    }
    
    /**
     * Get user's mindmaps
     */
    public function getUserMindMaps($user_id, $filters = []) {
        $where_conditions = ["(m.user_id = :user_id OR c.user_id = :user_id)"];
        $params = [':user_id' => $user_id];
        
        if (isset($filters['category_id'])) {
            $where_conditions[] = "m.category_id = :category_id";
            $params[':category_id'] = $filters['category_id'];
        }
        
        if (isset($filters['is_archived'])) {
            $where_conditions[] = "m.is_archived = :is_archived";
            $params[':is_archived'] = $filters['is_archived'];
        }
        
        if (isset($filters['search'])) {
            $where_conditions[] = "(m.title LIKE :search OR m.description LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        
        $query = "SELECT DISTINCT m.map_id, m.title, m.description, m.created_at, m.updated_at, 
                         m.last_accessed, m.is_public, m.is_archived, m.theme,
                         cat.name as category_name, cat.color as category_color,
                         u.username as owner_username,
                         (SELECT COUNT(*) FROM nodes WHERE map_id = m.map_id) as node_count,
                         CASE 
                            WHEN m.user_id = :user_id THEN 'owner'
                            WHEN c.permission = 'admin' THEN 'admin'
                            WHEN c.permission = 'edit' THEN 'edit'
                            ELSE 'view'
                         END as permission
                  FROM mindmaps m
                  LEFT JOIN categories cat ON m.category_id = cat.category_id
                  LEFT JOIN users u ON m.user_id = u.user_id
                  LEFT JOIN collaborators c ON m.map_id = c.map_id AND c.user_id = :user_id AND c.status = 'accepted'
                  WHERE " . implode(' AND ', $where_conditions) . "
                  ORDER BY m.updated_at DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Create new node
     */
    public function createNode($map_id, $data) {
        $query = "INSERT INTO nodes 
                  SET map_id=:map_id, parent_id=:parent_id, node_text=:node_text,
                      node_type=:node_type, color=:color, background_color=:background_color,
                      text_color=:text_color, position_x=:position_x, position_y=:position_y,
                      width=:width, height=:height, font_size=:font_size, icon=:icon,
                      priority=:priority, status=:status, due_date=:due_date, notes=:notes";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(":map_id", $map_id);
        $stmt->bindParam(":parent_id", $data['parent_id'] ?? null);
        $stmt->bindParam(":node_text", $data['node_text']);
        $stmt->bindParam(":node_type", $data['node_type'] ?? 'main');
        $stmt->bindParam(":color", $data['color'] ?? '#007bff');
        $stmt->bindParam(":background_color", $data['background_color'] ?? '#ffffff');
        $stmt->bindParam(":text_color", $data['text_color'] ?? '#000000');
        $stmt->bindParam(":position_x", $data['position_x']);
        $stmt->bindParam(":position_y", $data['position_y']);
        $stmt->bindParam(":width", $data['width'] ?? 150);
        $stmt->bindParam(":height", $data['height'] ?? 50);
        $stmt->bindParam(":font_size", $data['font_size'] ?? 14);
        $stmt->bindParam(":icon", $data['icon'] ?? null);
        $stmt->bindParam(":priority", $data['priority'] ?? 'medium');
        $stmt->bindParam(":status", $data['status'] ?? 'pending');
        $stmt->bindParam(":due_date", $data['due_date'] ?? null);
        $stmt->bindParam(":notes", $data['notes'] ?? null);
        
        if ($stmt->execute()) {
            $node_id = $this->conn->lastInsertId();
            
            // Handle tags
            if (isset($data['tags']) && is_array($data['tags'])) {
                $this->updateNodeTags($node_id, $data['tags']);
            }
            
            return $node_id;
        }
        
        throw new Exception("Failed to create node");
    }
    
    /**
     * Update node
     */
    public function updateNode($node_id, $data) {
        $allowed_fields = ['node_text', 'node_type', 'color', 'background_color', 'text_color',
                          'position_x', 'position_y', 'width', 'height', 'font_size', 'font_weight',
                          'icon', 'image_url', 'priority', 'status', 'due_date', 'notes', 'is_collapsed'];
        
        $update_fields = [];
        $params = [];
        
        foreach ($data as $field => $value) {
            if (in_array($field, $allowed_fields)) {
                $update_fields[] = "$field = :$field";
                $params[":$field"] = $value;
            }
        }
        
        if (empty($update_fields)) {
            throw new Exception("No valid fields to update");
        }
        
        $update_fields[] = "updated_at = NOW()";
        
        $query = "UPDATE nodes SET " . implode(', ', $update_fields) . " 
                  WHERE node_id = :node_id";
        
        $params[':node_id'] = $node_id;
        $stmt = $this->conn->prepare($query);
        
        if ($stmt->execute($params)) {
            // Handle tags update
            if (isset($data['tags']) && is_array($data['tags'])) {
                $this->updateNodeTags($node_id, $data['tags']);
            }
            
            return true;
        }
        
        throw new Exception("Failed to update node");
    }
    
    /**
     * Delete node
     */
    public function deleteNode($node_id) {
        $query = "DELETE FROM nodes WHERE node_id = :node_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":node_id", $node_id);
        
        if ($stmt->execute()) {
            return true;
        }
        
        throw new Exception("Failed to delete node");
    }
    
    /**
     * Get all nodes for a mindmap
     */
    private function getNodes($map_id) {
        $query = "SELECT n.*, 
                         GROUP_CONCAT(t.name) as tags,
                         GROUP_CONCAT(t.color) as tag_colors
                  FROM nodes n
                  LEFT JOIN node_tags nt ON n.node_id = nt.node_id
                  LEFT JOIN tags t ON nt.tag_id = t.tag_id
                  WHERE n.map_id = :map_id
                  GROUP BY n.node_id
                  ORDER BY n.order_index, n.created_at";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":map_id", $map_id);
        $stmt->execute();
        
        $nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Process tags
        foreach ($nodes as &$node) {
            if ($node['tags']) {
                $node['tags'] = explode(',', $node['tags']);
                $node['tag_colors'] = explode(',', $node['tag_colors']);
            } else {
                $node['tags'] = [];
                $node['tag_colors'] = [];
            }
        }
        
        return $nodes;
    }
    
    /**
     * Get connections for a mindmap
     */
    private function getConnections($map_id) {
        $query = "SELECT c.* FROM connections c
                  JOIN nodes n1 ON c.from_node_id = n1.node_id
                  JOIN nodes n2 ON c.to_node_id = n2.node_id
                  WHERE n1.map_id = :map_id AND n2.map_id = :map_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":map_id", $map_id);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get collaborators for a mindmap
     */
    private function getCollaborators($map_id) {
        $query = "SELECT c.*, u.username, u.email, u.profile_image
                  FROM collaborators c
                  JOIN users u ON c.user_id = u.user_id
                  WHERE c.map_id = :map_id AND c.status = 'accepted'";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":map_id", $map_id);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Update node tags
     */
    private function updateNodeTags($node_id, $tags) {
        // Remove existing tags
        $query = "DELETE FROM node_tags WHERE node_id = :node_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":node_id", $node_id);
        $stmt->execute();
        
        // Add new tags
        foreach ($tags as $tag_name) {
            $tag_id = $this->getOrCreateTag($tag_name);
            
            $query = "INSERT INTO node_tags (node_id, tag_id) VALUES (:node_id, :tag_id)";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":node_id", $node_id);
            $stmt->bindParam(":tag_id", $tag_id);
            $stmt->execute();
        }
    }
    
    /**
     * Get or create tag
     */
    private function getOrCreateTag($tag_name) {
        // Check if tag exists
        $query = "SELECT tag_id FROM tags WHERE name = :name";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":name", $tag_name);
        $stmt->execute();
        
        $tag = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($tag) {
            return $tag['tag_id'];
        }
        
        // Create new tag
        $query = "INSERT INTO tags (name) VALUES (:name)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":name", $tag_name);
        $stmt->execute();
        
        return $this->conn->lastInsertId();
    }
    
    /**
     * Check if user has access to mindmap
     */
    private function hasAccessToMindMap($map_id, $user_id) {
        $query = "SELECT 1 FROM mindmaps m
                  LEFT JOIN collaborators c ON m.map_id = c.map_id AND c.user_id = :user_id
                  WHERE m.map_id = :map_id 
                  AND (m.user_id = :user_id OR m.is_public = 1 OR c.status = 'accepted')";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":map_id", $map_id);
        $stmt->bindParam(":user_id", $user_id);
        $stmt->execute();
        
        return $stmt->fetch() !== false;
    }
    
    /**
     * Check if user has edit access
     */
    private function hasEditAccess($map_id, $user_id) {
        $query = "SELECT 1 FROM mindmaps m
                  LEFT JOIN collaborators c ON m.map_id = c.map_id AND c.user_id = :user_id
                  WHERE m.map_id = :map_id 
                  AND (m.user_id = :user_id OR c.permission IN ('edit', 'admin'))";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":map_id", $map_id);
        $stmt->bindParam(":user_id", $user_id);
        $stmt->execute();
        
        return $