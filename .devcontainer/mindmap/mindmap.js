/**
 * MindMap Platform - Frontend JavaScript
 */

class MindMapPlatform {
    constructor() {
        this.apiBase = 'http://localhost/mindmap/api/';
        this.authToken = localStorage.getItem('authToken');
        this.currentUser = null;
        this.currentMindMap = null;
        this.diagram = null;
        this.selectedNode = null;
        this.categories = [];
        this.mindmaps = [];
        
        this.init();
    }
    
    async init() {
        try {
            this.setupEventListeners();
            this.initializeGoJS();
            
            if (this.authToken) {
                await this.loadUserProfile();
                await this.loadCategories();
                await this.loadUserMindMaps();
                this.showAuthenticatedUI();
            } else {
                this.showUnauthenticatedUI();
            }
        } catch (error) {
            console.error('Initialization failed:', error);
            this.showToast('Failed to initialize application', 'error');
        }
    }
    
    setupEventListeners() {
        // Authentication
        document.getElementById('loginBtn').addEventListener('click', () => this.showModal('loginModal'));
        document.getElementById('signupBtn').addEventListener('click', () => this.showModal('signupModal'));
        document.getElementById('logoutBtn').addEventListener('click', () => this.logout());
        document.getElementById('loginForm').addEventListener('submit', (e) => this.handleLogin(e));
        document.getElementById('signupForm').addEventListener('submit', (e) => this.handleSignup(e));
        
        // MindMap operations
        document.getElementById('newMindMapBtn').addEventListener('click', () => this.showModal('newMindMapModal'));
        document.getElementById('getStartedBtn').addEventListener('click', () => this.showModal('newMindMapModal'));
        document.getElementById('newMindMapForm').addEventListener('submit', (e) => this.handleCreateMindMap(e));
        
        // Tools
        document.getElementById('addNodeBtn').addEventListener('click', () => this.addNode());
        document.getElementById('autoLayoutBtn').addEventListener('click', () => this.autoLayout());
        document.getElementById('zoomInBtn').addEventListener('click', () => this.zoomIn());
        document.getElementById('zoomOutBtn').addEventListener('click', () => this.zoomOut());
        document.getElementById('fitToScreenBtn').addEventListener('click', () => this.fitToScreen());
        document.getElementById('saveBtn').addEventListener('click', () => this.saveMindMap());
        document.getElementById('exportBtn').addEventListener('click', () => this.exportMindMap());
        document.getElementById('shareBtn').addEventListener('click', () => this.shareMindMap());
        
        // Properties panel
        document.getElementById('applyPropertiesBtn').addEventListener('click', () => this.applyNodeProperties());
        document.getElementById('cancelPropertiesBtn').addEventListener('click', () => this.hidePropertiesPanel());
        
        // Search and filter
        document.getElementById('searchInput').addEventListener('input', (e) => this.filterMindMaps(e.target.value));
        document.getElementById('categoryFilter').addEventListener('change', (e) => this.filterByCategory(e.target.value));
        
        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => this.handleKeyboardShortcuts(e));
        
        // Window resize
        window.addEventListener('resize', () => this.resizeDiagram());
    }
    
    initializeGoJS() {
        const $ = go.GraphObject.make;
        
        this.diagram = $(go.Diagram, 'mindmapCanvas', {
            initialContentAlignment: go.Spot.Center,
            'undoManager.isEnabled': true,
            'toolManager.hoverDelay': 100,
            allowDrop: true,
            allowClipboard: true,
            'animationManager.isEnabled': true,
            layout: $(go.TreeLayout, {
                angle: 0,
                arrangement: go.TreeLayout.ArrangementVertical,
                treeStyle: go.TreeLayout.StyleLastParents,
                alternateAngle: 90,
                alternateAlignment: go.TreeLayout.AlignmentBus
            })
        });
        
        // Define node template
        this.diagram.nodeTemplate = $(go.Node, 'Auto',
            {
                locationSpot: go.Spot.Center,
                selectionChanged: (node) => this.onNodeSelectionChanged(node)
            },
            new go.Binding('location', 'loc', go.Point.parse).makeTwoWay(go.Point.stringify),
            
            // Node shape
            $(go.Shape, 'RoundedRectangle', {
                strokeWidth: 2,
                stroke: '#666',
                portId: '',
                cursor: 'pointer',
                fromLinkable: true,
                toLinkable: true,
                fromSpot: go.Spot.AllSides,
                toSpot: go.Spot.AllSides
            },
                new go.Binding('fill', 'backgroundColor'),
                new go.Binding('stroke', 'color')
            ),
            
            // Node content panel
            $(go.Panel, 'Horizontal',
                // Icon
                $(go.TextBlock, {
                    margin: new go.Margin(0, 5, 0, 8),
                    font: '16px FontAwesome'
                },
                    new go.Binding('text', 'icon').makeTwoWay(),
                    new go.Binding('visible', 'icon', (icon) => icon !== null && icon !== '')
                ),
                
                // Text
                $(go.TextBlock, {
                    margin: 8,
                    maxSize: new go.Size(200, NaN),
                    wrap: go.TextBlock.WrapFit,
                    textAlign: 'center',
                    editable: true,
                    cursor: 'pointer'
                },
                    new go.Binding('text').makeTwoWay(),
                    new go.Binding('stroke', 'textColor'),
                    new go.Binding('font', 'fontSize', (size) => `bold ${size}px sans-serif`)
                )
            ),
            
            // Context menu
            {
                contextMenu: this.createContextMenu()
            }
        );
        
        // Define link template
        this.diagram.linkTemplate = $(go.Link, {
                routing: go.Link.Orthogonal,
                corner: 10,
                selectable: false
            },
            $(go.Shape, {
                strokeWidth: 2,
                stroke: '#666'
            },
                new go.Binding('stroke', 'color')
            )
        );
        
        // Selection and modification events
        this.diagram.addDiagramListener('SelectionChanged', () => this.onSelectionChanged());
        this.diagram.addDiagramListener('Modified', () => this.onDiagramModified());
        
        // Double-click to edit
        this.diagram.addDiagramListener('ObjectDoubleClicked', (e) => {
            const part = e.subject.part;
            if (part instanceof go.Node) {
                this.editNode(part);
            }
        });
        
        this.hideWelcomeScreen();
    }
    
    createContextMenu() {
        const $ = go.GraphObject.make;
        
        return $('ContextMenu',
            $('ContextMenuButton',
                $(go.TextBlock, 'Add Child Node'),
                { click: (e, obj) => this.addChildNode(obj.part.adornedPart) }
            ),
            $('ContextMenuButton',
                $(go.TextBlock, 'Edit Properties'),
                { click: (e, obj) => this.editNode(obj.part.adornedPart) }
            ),
            $('ContextMenuButton',
                $(go.TextBlock, 'Change Color'),
                { click: (e, obj) => this.changeNodeColor(obj.part.adornedPart) }
            ),
            $('ContextMenuButton',
                $(go.TextBlock, 'Delete Node'),
                { click: (e, obj) => this.deleteNode(obj.part.adornedPart) }
            )
        );
    }
    
    // Authentication Methods
    async handleLogin(e) {
        e.preventDefault();
        
        const username = document.getElementById('loginUsername').value;
        const password = document.getElementById('loginPassword').value;
        
        try {
            const response = await this.apiCall('auth.php?action=login', 'POST', {
                username,
                password
            });
            
            if (response.success) {
                this.authToken = response.data.token;
                this.currentUser = response.data.user;
                localStorage.setItem('authToken', this.authToken);
                
                this.closeModal('loginModal');
                this.showAuthenticatedUI();
                this.showToast('Login successful!', 'success');
                
                await this.loadCategories();
                await this.loadUserMindMaps();
            } else {
                this.showToast(response.message, 'error');
            }
        } catch (error) {
            this.showToast('Login failed. Please try again.', 'error');
        }
    }
    
    async handleSignup(e) {
        e.preventDefault();
        
        const username = document.getElementById('signupUsername').value;
        const email = document.getElementById('signupEmail').value;
        const password = document.getElementById('signupPassword').value;
        const confirmPassword = document.getElementById('signupConfirmPassword').value;
        
        if (password !== confirmPassword) {
            this.showToast('Passwords do not match', 'error');
            return;
        }
        
        try {
            const response = await this.apiCall('auth.php?action=register', 'POST', {
                username,
                email,
                password
            });
            
            if (response.success) {
                this.closeModal('signupModal');
                this.showToast('Account created successfully! Please login.', 'success');
                this.showModal('loginModal');
            } else {
                this.showToast(response.message, 'error');
            }
        } catch (error) {
            this.showToast('Registration failed. Please try again.', 'error');
        }
    }
    
    logout() {
        this.authToken = null;
        this.currentUser = null;
        this.currentMindMap = null;
        localStorage.removeItem('authToken');
        
        this.showUnauthenticatedUI();
        this.clearCanvas();
        this.showToast('Logged out successfully', 'info');
    }
    
    async loadUserProfile() {
        try {
            const response = await this.apiCall('auth.php?action=profile', 'GET');
            if (response.success) {
                this.currentUser = response.data;
            }
        } catch (error) {
            console.error('Failed to load user profile:', error);
        }
    }
    
    // MindMap CRUD Operations
    async handleCreateMindMap(e) {
        e.preventDefault();
        
        const title = document.getElementById('mindmapTitle').value;
        const description = document.getElementById('mindmapDescription').value;
        const category_id = document.getElementById('mindmapCategory').value || null;
        const central_node = document.getElementById('centralNodeText').value;
        const theme = document.getElementById('mindmapTheme').value;
        const is_public = document.getElementById('mindmapPublic').checked;
        
        try {
            const response = await this.apiCall('mindmap_api.php?action=create', 'POST', {
                title,
                description,
                category_id,
                central_node,
                theme,
                is_public
            });
            
            if (response.success) {
                this.closeModal('newMindMapModal');
                this.showToast('MindMap created successfully!', 'success');
                
                await this.loadUserMindMaps();
                await this.loadMindMap(response.data.map_id);
                
                // Clear form
                document.getElementById('newMindMapForm').reset();
            } else {
                this.showToast(response.message, 'error');
            }
        } catch (error) {
            this.showToast('Failed to create mindmap. Please try again.', 'error');
        }
    }
    
    async loadMindMap(mapId) {
        try {
            const response = await this.apiCall(`mindmap_api.php?action=get&map_id=${mapId}`, 'GET');
            
            if (response.success) {
                this.currentMindMap = response.data;
                this.displayMindMap(response.data);
                this.updateCanvasTitle(response.data.title);
                this.hideWelcomeScreen();
                this.showToast('MindMap loaded successfully', 'success');
            } else {
                this.showToast(response.message, 'error');
            }
        } catch (error) {
            this.showToast('Failed to load mindmap', 'error');
        }
    }
    
    async loadUserMindMaps() {
        try {
            const response = await this.apiCall('mindmap_api.php?action=list', 'GET');
            
            if (response.success) {
                this.mindmaps = response.data;
                this.displayMindMapList(response.data);
            }
        } catch (error) {
            console.error('Failed to load mindmaps:', error);
        }
    }
    
    async saveMindMap() {
        if (!this.currentMindMap) {
            this.showToast('No mindmap to save', 'warning');
            return;
        }
        
        try {
            // Get current diagram state
            const diagramData = this.diagram.model.toJson();
            const parsedData = JSON.parse(diagramData);
            
            // Save nodes
            for (const nodeData of parsedData.nodeDataArray) {
                if (nodeData.isNew) {
                    // Create new node
                    await this.createNode(nodeData);
                    delete nodeData.isNew;
                } else if (nodeData.isModified) {
                    // Update existing node
                    await this.updateNode(nodeData);
                    delete nodeData.isModified;
                }
            }
            
            // Update mindmap metadata
            const updateData = {
                canvas_width: this.diagram.documentBounds.width,
                canvas_height: this.diagram.documentBounds.height,
                zoom_level: this.diagram.scale,
                center_x: this.diagram.position.x,
                center_y: this.diagram.position.y
            };
            
            await this.apiCall(`mindmap_api.php?action=update&map_id=${this.currentMindMap.map_id}`, 'PUT', updateData);
            
            this.showToast('MindMap saved successfully!', 'success');
        } catch (error) {
            this.showToast('Failed to save mindmap', 'error');
        }
    }
    
    async createNode(nodeData) {
        try {
            const response = await this.apiCall(`mindmap_api.php?action=create-node&map_id=${this.currentMindMap.map_id}`, 'POST', {
                node_text: nodeData.text,
                node_type: nodeData.nodeType || 'main',
                color: nodeData.color || '#007bff',
                background_color: nodeData.backgroundColor || '#ffffff',
                text_color: nodeData.textColor || '#000000',
                position_x: nodeData.loc ? parseFloat(nodeData.loc.split(' ')[0]) : 0,
                position_y: nodeData.loc ? parseFloat(nodeData.loc.split(' ')[1]) : 0,
                width: nodeData.width || 150,
                height: nodeData.height || 50,
                font_size: nodeData.fontSize || 14,
                icon: nodeData.icon || null,
                priority: nodeData.priority || 'medium',
                status: nodeData.status || 'pending',
                tags: nodeData.tags || [],
                notes: nodeData.notes || null
            });
            
            if (response.success) {
                nodeData.key = response.data.node_id;
                return response.data.node_id;
            }
        } catch (error) {
            console.error('Failed to create node:', error);
            throw error;
        }
    }
    
    async updateNode(nodeData) {
        try {
            await this.apiCall(`mindmap_api.php?action=update-node&node_id=${nodeData.key}`, 'PUT', {
                node_text: nodeData.text,
                color: nodeData.color,
                background_color: nodeData.backgroundColor,
                text_color: nodeData.textColor,
                position_x: nodeData.loc ? parseFloat(nodeData.loc.split(' ')[0]) : 0,
                position_y: nodeData.loc ? parseFloat(nodeData.loc.split(' ')[1]) : 0,
                font_size: nodeData.fontSize,
                icon: nodeData.icon,
                priority: nodeData.priority,
                status: nodeData.status,
                tags: nodeData.tags,
                notes: nodeData.notes
            });
        } catch (error) {
            console.error('Failed to update node:', error);
            throw error;
        }
    }
    
    // Canvas Operations
    displayMindMap(mindmapData) {
        const nodeDataArray = [];
        const linkDataArray = [];
        
        // Convert nodes to GoJS format
        mindmapData.nodes.forEach(node => {
            nodeDataArray.push({
                key: node.node_id,
                text: node.node_text,
                color: node.color,
                backgroundColor: node.background_color,
                textColor: node.text_color,
                fontSize: node.font_size,
                icon: node.icon,
                nodeType: node.node_type,
                priority: node.priority,
                status: node.status,
                tags: node.tags,
                notes: node.notes,
                loc: `${node.position_x} ${node.position_y}`
            });
            
            // Create links for parent-child relationships
            if (node.parent_id) {
                linkDataArray.push({
                    from: node.parent_id,
                    to: node.node_id
                });
            }
        });
        
        this.diagram.model = new go.TreeModel(nodeDataArray);
        this.diagram.model.linkDataArray = linkDataArray;
        
        // Set canvas properties
        if (mindmapData.zoom_level) {
            this.diagram.scale = mindmapData.zoom_level;
        }
        if (mindmapData.center_x && mindmapData.center_y) {
            this.diagram.position = new go.Point(mindmapData.center_x, mindmapData.center_y);
        }
        
        this.fitToScreen();
    }
    
    addNode() {
        if (!this.currentMindMap) {
            this.showToast('Please select or create a mindmap first', 'warning');
            return;
        }
        
        const centerPoint = this.diagram.viewportBounds.center;
        const newNodeData = {
            key: 'temp_' + Date.now(),
            text: 'New Node',
            color: '#007bff',
            backgroundColor: '#ffffff',
            textColor: '#000000',
            fontSize: 14,
            nodeType: 'main',
            priority: 'medium',
            status: 'pending',
            tags: [],
            loc: `${centerPoint.x} ${centerPoint.y}`,
            isNew: true
        };
        
        this.diagram.model.addNodeData(newNodeData);
        this.diagram.select(this.diagram.findNodeForKey(newNodeData.key));
    }
    
    addChildNode(parentNode) {
        if (!parentNode || !this.currentMindMap) return;
        
        const parentData = parentNode.data;
        const parentPoint = parentNode.location;
        
        const newNodeData = {
            key: 'temp_' + Date.now(),
            text: 'Child Node',
            color: parentData.color,
            backgroundColor: '#ffffff',
            textColor: '#000000',
            fontSize: Math.max(parentData.fontSize - 2, 10),
            nodeType: 'sub',
            priority: 'medium',
            status: 'pending',
            tags: [],
            loc: `${parentPoint.x + 200} ${parentPoint.y + 50}`,
            isNew: true
        };
        
        this.diagram.model.addNodeData(newNodeData);
        this.diagram.model.addLinkData({
            from: parentData.key,
            to: newNodeData.key
        });
        
        this.diagram.select(this.diagram.findNodeForKey(newNodeData.key));
    }
    
    deleteNode(node) {
        if (!node) return;
        
        if (confirm('Are you sure you want to delete this node and all its children?')) {
            this.diagram.model.removeNodeData(node.data);
        }
    }
    
    editNode(node) {
        if (!node) return;
        
        this.selectedNode = node;
        this.populatePropertiesPanel(node.data);
        this.showPropertiesPanel();
    }
    
    // Properties Panel Methods
    populatePropertiesPanel(nodeData) {
        document.getElementById('nodeText').value = nodeData.text || '';
        document.getElementById('nodeColor').value = nodeData.color || '#007bff';
        document.getElementById('nodeBackgroundColor').value = nodeData.backgroundColor || '#ffffff';
        document.getElementById('nodeFontSize').value = nodeData.fontSize || 14;
        document.getElementById('nodeIcon').value = nodeData.icon || '';
        document.getElementById('nodePriority').value = nodeData.priority || 'medium';
        document.getElementById('nodeStatus').value = nodeData.status || 'pending';
        document.getElementById('nodeTags').value = Array.isArray(nodeData.tags) ? nodeData.tags.join(', ') : '';
        document.getElementById('nodeNotes').value = nodeData.notes || '';
    }
    
    applyNodeProperties() {
        if (!this.selectedNode) return;
        
        const updatedData = {
            text: document.getElementById('nodeText').value,
            color: document.getElementById('nodeColor').value,
            backgroundColor: document.getElementById('nodeBackgroundColor').value,
            fontSize: parseInt(document.getElementById('nodeFontSize').value),
            icon: document.getElementById('nodeIcon').value,
            priority: document.getElementById('nodePriority').value,
            status: document.getElementById('nodeStatus').value,
            tags: document.getElementById('nodeTags').value.split(',').map(tag => tag.trim()).filter(tag => tag),
            notes: document.getElementById('nodeNotes').value,
            isModified: true
        };
        
        this.diagram.model.setDataProperty(this.selectedNode.data, 'text', updatedData.text);
        this.diagram.model.setDataProperty(this.selectedNode.data, 'color', updatedData.color);
        this.diagram.model.setDataProperty(this.selectedNode.data, 'backgroundColor', updatedData.backgroundColor);
        this.diagram.model.setDataProperty(this.selectedNode.data, 'fontSize', updatedData.fontSize);
        this.diagram.model.setDataProperty(this.selectedNode.data, 'icon', updatedData.icon);
        this.diagram.model.setDataProperty(this.selectedNode.data, 'priority', updatedData.priority);
        this.diagram.model.setDataProperty(this.selectedNode.data, 'status', updatedData.status);
        this.diagram.model.setDataProperty(this.selectedNode.data, 'tags', updatedData.tags);
        this.diagram.model.setDataProperty(this.selectedNode.data, 'notes', updatedData.notes);
        this.diagram.model.setDataProperty(this.selectedNode.data, 'isModified', true);
        
        this.hidePropertiesPanel();
        this.showToast('Node properties updated', 'success');
    }
    
    showPropertiesPanel() {
        document.getElementById('propertiesPanel').classList.add('active');
    }
    
    hidePropertiesPanel() {
        document.getElementById('propertiesPanel').classList.remove('active');
        this.selectedNode = null;
    }
    
    // Canvas Control Methods
    autoLayout() {
        this.diagram.layoutDiagram(true);
        this.showToast('Auto layout applied', 'info');
    }
    
    zoomIn() {
        this.diagram.commandHandler.increaseZoom();
    }
    
    zoomOut() {
        this.diagram.commandHandler.decreaseZoom();
    }
    
    fitToScreen() {
        this.diagram.zoomToFit();
    }
    
    resizeDiagram() {
        if (this.diagram) {
            this.diagram.requestUpdate();
        }
    }
    
    // Export Methods
    async exportMindMap() {
        if (!this.currentMindMap) {
            this.showToast('No mindmap to export', 'warning');
            return;
        }
        
        const format = await this.showExportDialog();
        if (!format) return;
        
        try {
            if (format === 'png' || format === 'pdf') {
                await this.exportAsImage(format);
            } else {
                const response = await this.apiCall(`mindmap_api.php?action=export&map_id=${this.currentMindMap.map_id}&format=${format}`, 'GET');
                this.downloadFile(response, `mindmap_${this.currentMindMap.map_id}.${format}`);
            }
            
            this.showToast('Export completed successfully', 'success');
        } catch (error) {
            this.showToast('Export failed', 'error');
        }
    }
    
    async exportAsImage(format) {
        const canvas = await html2canvas(document.getElementById('mindmapCanvas'));
        
        if (format === 'png') {
            // Download as PNG
            const link = document.createElement('a');
            link.download = `mindmap_${this.currentMindMap.map_id}.png`;
            link.href = canvas.toDataURL();
            link.click();
        } else if (format === 'pdf') {
            // Convert to PDF using jsPDF
            const { jsPDF } = window.jspdf;
            const pdf = new jsPDF('landscape');
            const imgData = canvas.toDataURL('image/png');
            
            const imgWidth = 297;
            const pageHeight = 210;
            const imgHeight = (canvas.height * imgWidth) / canvas.width;
            let heightLeft = imgHeight;
            
            let position = 0;
            
            pdf.addImage(imgData, 'PNG', 0, position, imgWidth, imgHeight);
            heightLeft -= pageHeight;
            
            while (heightLeft >= 0) {
                position = heightLeft - imgHeight;
                pdf.addPage();
                pdf.addImage(imgData, 'PNG', 0, position, imgWidth, imgHeight);
                heightLeft -= pageHeight;
            }
            
            pdf.save(`mindmap_${this.currentMindMap.map_id}.pdf`);
        }
    }
    
    showExportDialog() {
        return new Promise((resolve) => {
            const formats = ['png', 'pdf', 'json', 'xml'];
            const format = prompt('Choose export format:\n' + formats.join(', '));
            
            if (formats.includes(format)) {
                resolve(format);
            } else {
                resolve(null);
            }
        });
    }
    
    downloadFile(data, filename) {
        const blob = new Blob([data], { type: 'application/octet-stream' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    }
    
    // Utility Methods
    async loadCategories() {
        try {
            // Mock categories for now - in real app, load from API
            this.categories = [
                { category_id: 1, name: 'Business', color: '#007bff' },
                { category_id: 2, name: 'Education', color: '#28a745' },
                { category_id: 3, name: 'Personal', color: '#ffc107' },
                { category_id: 4, name: 'Project', color: '#6f42c1' },
                { category_id: 5, name: 'Creative', color: '#e91e63' },
                { category_id: 6, name: 'Research', color: '#20c997' }
            ];
            
            this.populateCategorySelects();
        } catch (error) {
            console.error('Failed to load categories:', error);
        }
    }
    
    populateCategorySelects() {
        const selects = ['categoryFilter', 'mindmapCategory'];
        
        selects.forEach(selectId => {
            const select = document.getElementById(selectId);
            if (!select) return;
            
            // Clear existing options (except first one for filter)
            if (selectId === 'categoryFilter') {
                select.innerHTML = '<option value="">All Categories</option>';
            } else {
                select.innerHTML = '<option value="">Select Category</option>';
            }
            
            // Add category options
            this.categories.forEach(category => {
                const option = document.createElement('option');
                option.value = category.category_id;
                option.textContent = category.name;
                select.appendChild(option);
            });
        });
    }
    
    displayMindMapList(mindmaps) {
        const listContainer = document.getElementById('mindmapList');
        listContainer.innerHTML = '';
        
        if (mindmaps.length === 0) {
            listContainer.innerHTML = '<li style="text-align: center; color: #666; padding: 1rem;">No mindmaps found</li>';
            return;
        }
        
        mindmaps.forEach(mindmap => {
            const listItem = document.createElement('li');
            listItem.className = 'mindmap-item';
            listItem.onclick = () => this.loadMindMap(mindmap.map_id);
            
            listItem.innerHTML = `
                <div class="mindmap-title">${mindmap.title}</div>
                <div class="mindmap-meta">
                    <i class="fas fa-calendar"></i> ${new Date(mindmap.updated_at).toLocaleDateString()}
                    <br>
                    <i class="fas fa-project-diagram"></i> ${mindmap.node_count} nodes
                    ${mindmap.category_name ? `<br><i class="fas fa-tag"></i> ${mindmap.category_name}` : ''}
                </div>
            `;
            
            listContainer.appendChild(listItem);
        });
    }
    
    filterMindMaps(searchTerm) {
        const filtered = this.mindmaps.filter(mindmap => 
            mindmap.title.toLowerCase().includes(searchTerm.toLowerCase()) ||
            (mindmap.description && mindmap.description.toLowerCase().includes(searchTerm.toLowerCase()))
        );
        this.displayMindMapList(filtered);
    }
    
    filterByCategory(categoryId) {
        let filtered = this.mindmaps;
        
        if (categoryId) {
            filtered = this.mindmaps.filter(mindmap => 
                mindmap.category_id == categoryId
            );
        }
        
        this.displayMindMapList(filtered);
    }
    
    // Event Handlers
    onNodeSelectionChanged(node) {
        if (node.isSelected) {
            this.selectedNode = node;
        }
    }
    
    onSelectionChanged() {
        const selection = this.diagram.selection;
        if (selection.count === 1 && selection.first() instanceof go.Node) {
            this.selectedNode = selection.first();
        } else {
            this.selectedNode = null;
            this.hidePropertiesPanel();
        }
    }
    
    onDiagramModified() {
        // Mark diagram as modified for saving
        if (this.currentMindMap) {
            this.currentMindMap.isModified = true;
        }
    }
    
    handleKeyboardShortcuts(e) {
        if (e.ctrlKey || e.metaKey) {
            switch (e.key) {
                case 's':
                    e.preventDefault();
                    this.saveMindMap();
                    break;
                case 'n':
                    e.preventDefault();
                    this.addNode();
                    break;
                case 'z':
                    e.preventDefault();
                    if (this.diagram) {
                        this.diagram.commandHandler.undo();
                    }
                    break;
                case 'y':
                    e.preventDefault();
                    if (this.diagram) {
                        this.diagram.commandHandler.redo();
                    }
                    break;
            }
        }
        
        if (e.key === 'Delete' && this.selectedNode) {
            this.deleteNode(this.selectedNode);
        }
        
        if (e.key === 'Escape') {
            this.hidePropertiesPanel();
        }
    }
    
    // UI State Management
    showAuthenticatedUI() {
        document.querySelector('.user-menu').style.display = 'flex';
        document.getElementById('loginBtn').style.display = 'none';
        document.getElementById('signupBtn').style.display = 'none';
        document.getElementById('sidebar').classList.remove('hidden');
    }
    
    showUnauthenticatedUI() {
        document.querySelector('.user-menu').style.display = 'none';
        document.getElementById('loginBtn').style.display = 'inline-flex';
        document.getElementById('signupBtn').style.display = 'inline-flex';
        document.getElementById('welcomeScreen').style.display = 'flex';
    }
    
    hideWelcomeScreen() {
        document.getElementById('welcomeScreen').style.display = 'none';
    }
    
    showWelcomeScreen() {
        document.getElementById('welcomeScreen').style.display = 'flex';
        this.clearCanvas();
    }
    
    clearCanvas() {
        if (this.diagram) {
            this.diagram.model = new go.GraphLinksModel();
        }
        this.updateCanvasTitle('Welcome to MindMap Platform');
    }
    
    updateCanvasTitle(title) {
        document.getElementById('canvasTitle').textContent = title;
    }
    
    // Modal Management
    showModal(modalId) {
        document.getElementById(modalId).classList.add('active');
    }
    
    closeModal(modalId) {
        document.getElementById(modalId).classList.remove('active');
    }
    
    // Toast Notifications
    showToast(message, type = 'info') {
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.innerHTML = `
            <i class="fas fa-${this.getToastIcon(type)}"></i>
            <span>${message}</span>
        `;
        
        document.getElementById('toastContainer').appendChild(toast);
        
        setTimeout(() => {
            toast.remove();
        }, 4000);
    }
    
    getToastIcon(type) {
        switch (type) {
            case 'success': return 'check-circle';
            case 'error': return 'exclamation-circle';
            case 'warning': return 'exclamation-triangle';
            case 'info': return 'info-circle';
            default: return 'info-circle';
        }
    }
    
    // API Communication
    async apiCall(endpoint, method = 'GET', data = null) {
        const url = this.apiBase + endpoint;
        const options = {
            method,
            headers: {
                'Content-Type': 'application/json',
            }
        };
        
        if (this.authToken) {
            options.headers['Authorization'] = `Bearer ${this.authToken}`;
        }
        
        if (data && (method === 'POST' || method === 'PUT')) {
            options.body = JSON.stringify(data);
        }
        
        const response = await fetch(url, options);
        
        if (!response.ok) {
            throw new Error(`API call failed: ${response.status}`);
        }
        
        return await response.json();
    }
}

// Initialize the application when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    window.mindmapPlatform = new MindMapPlatform();
});

// Global functions for modal management (called from HTML)
function showModal(modalId) {
    window.mindmapPlatform.showModal(modalId);
}

function closeModal(modalId) {
    window.mindmapPlatform.closeModal(modalId);
}