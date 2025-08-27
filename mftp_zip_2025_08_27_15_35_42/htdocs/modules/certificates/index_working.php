<?php
/**
 * CERTOLO - Certificates Module
 * Certificate listing and management
 */

// Check authentication
if (!isset($_SESSION['user_id'])) {
    header('Location: /login');
    exit;
}

// Get user data
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['user_role'];

// Get action from URL
$action = $_GET['action'] ?? 'index';
$id = $_GET['id'] ?? null;

// Route to appropriate action
switch ($action) {
    case 'generate':
        if ($userRole !== ROLE_CERTIFIER) {
            header('HTTP/1.0 403 Forbidden');
            exit('Access denied');
        }
        require_once 'generate.php';
        break;
        
    case 'view':
        require_once 'view.php';
        break;
        
    case 'download':
        require_once 'download.php';
        break;
        
    case 'verify':
        require_once 'verify.php';
        break;
        
    case 'revoke':
        if ($userRole !== ROLE_CERTIFIER) {
            header('HTTP/1.0 403 Forbidden');
            exit('Access denied');
        }
        require_once 'revoke.php';
        break;
        
    default:
        // List certificates
        listCertificates();
        break;
}

function listCertificates() {
    global $userId, $userRole;
    
    try {
        $db = Database::getInstance();
        
        // Get filter parameters
        $status = $_GET['status'] ?? '';
        $search = $_GET['search'] ?? '';
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = ITEMS_PER_PAGE;
        $offset = ($page - 1) * $limit;
        
        // Build query based on user role
        if ($userRole === ROLE_APPLICANT) {
            // Applicants see only their certificates
            $where = "WHERE c.applicant_id = :user_id";
            $params = ['user_id' => $userId];
        } else {
            // Certifiers see certificates they issued
            $where = "WHERE c.certifier_id = :user_id";
            $params = ['user_id' => $userId];
        }
        
        // Add filters
        if ($status) {
            $where .= " AND c.status = :status";
            $params['status'] = $status;
        }
        
        if ($search) {
            $where .= " AND (c.certificate_number LIKE :search OR s.name LIKE :search OR u.company_name LIKE :search)";
            $params['search'] = '%' . $search . '%';
        }
        
        // Get total count for pagination
        $countStmt = $db->query(
            "SELECT COUNT(*) as total 
             FROM certificates c
             JOIN standards s ON c.standard_id = s.id
             JOIN users u ON c.applicant_id = u.id
             $where",
            $params
        );
        $totalCertificates = $countStmt->fetch()['total'];
        $totalPages = ceil($totalCertificates / $limit);
        
        // Get certificates with related data
        $stmt = $db->query(
            "SELECT c.*, 
                    s.name as standard_name, s.type as standard_type,
                    u.company_name as applicant_company,
                    u2.company_name as certifier_company
             FROM certificates c
             JOIN standards s ON c.standard_id = s.id
             JOIN users u ON c.applicant_id = u.id
             JOIN users u2 ON c.certifier_id = u2.id
             $where
             ORDER BY c.issued_at DESC
             LIMIT :limit OFFSET :offset",
            array_merge($params, ['limit' => $limit, 'offset' => $offset])
        );
        
        $certificates = $stmt->fetchAll();
        
        // Set page title
        $pageTitle = 'Certificates';
        
        // Include header
        include INCLUDES_PATH . 'header.php';
        ?>
        
        <div class="page-body">
            <div class="container-xl">
                <div class="row g-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="ti ti-certificate"></i>
                                    <?php echo $userRole === ROLE_CERTIFIER ? 'Issued Certificates' : 'My Certificates'; ?>
                                </h3>
                                <div class="card-actions">
                                    <?php if ($userRole === ROLE_CERTIFIER): ?>
                                        <a href="/applications?status=approved" class="btn btn-primary">
                                            <i class="ti ti-plus"></i> Issue Certificate
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Filters -->
                            <div class="card-body border-bottom">
                                <form method="GET" action="/certificates" class="row g-2">
                                    <div class="col-md-3">
                                        <select name="status" class="form-select">
                                            <option value="">All Statuses</option>
                                            <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                                            <option value="expired" <?php echo $status === 'expired' ? 'selected' : ''; ?>>Expired</option>
                                            <option value="revoked" <?php echo $status === 'revoked' ? 'selected' : ''; ?>>Revoked</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <input type="text" name="search" class="form-control" 
                                               placeholder="Search certificates..." 
                                               value="<?php echo Security::escape($search); ?>">
                                    </div>
                                    <div class="col-md-3">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="ti ti-search"></i> Filter
                                        </button>
                                        <?php if ($status || $search): ?>
                                            <a href="/certificates" class="btn btn-light">Clear</a>
                                        <?php endif; ?>
                                    </div>
                                </form>
                            </div>
                            
                            <!-- Certificates List -->
                            <?php if (!empty($certificates)): ?>
                                <div class="table-responsive">
                                    <table class="table card-table table-vcenter text-nowrap">
                                        <thead>
                                            <tr>
                                                <th>Certificate</th>
                                                <th>Standard</th>
                                                <?php if ($userRole === ROLE_CERTIFIER): ?>
                                                    <th>Company</th>
                                                <?php else: ?>
                                                    <th>Certifier</th>
                                                <?php endif; ?>
                                                <th>Status</th>
                                                <th>Issued</th>
                                                <th>Expires</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($certificates as $cert): ?>
                                                <?php
                                                $statusClass = match($cert['status']) {
                                                    'active' => 'bg-green',
                                                    'expired' => 'bg-gray',
                                                    'revoked' => 'bg-red',
                                                    default => 'bg-secondary'
                                                };
                                                
                                                $expiryDate = new DateTime($cert['expires_at']);
                                                $now = new DateTime();
                                                $isExpiringSoon = $expiryDate <= $now->modify('+30 days');
                                                ?>
                                                <tr>
                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            <div>
                                                                <strong><?php echo Security::escape($cert['certificate_number']); ?></strong>
                                                                <div class="small text-muted">
                                                                    Code: <?php echo Security::escape($cert['verification_code']); ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div>
                                                            <?php echo Security::escape($cert['standard_name']); ?>
                                                            <?php if ($cert['standard_type']): ?>
                                                                <div class="small text-muted"><?php echo Security::escape($cert['standard_type']); ?></div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <?php if ($userRole === ROLE_CERTIFIER): ?>
                                                            <?php echo Security::escape($cert['applicant_company']); ?>
                                                        <?php else: ?>
                                                            <?php echo Security::escape($cert['certifier_company']); ?>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge <?php echo $statusClass; ?>">
                                                            <?php echo ucfirst($cert['status']); ?>
                                                        </span>
                                                        <?php if ($cert['status'] === 'active' && $isExpiringSoon): ?>
                                                            <div class="small text-warning">
                                                                <i class="ti ti-alert-triangle"></i> Expiring Soon
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php echo date('d M Y', strtotime($cert['issued_at'])); ?>
                                                    </td>
                                                    <td>
                                                        <?php echo date('d M Y', strtotime($cert['expires_at'])); ?>
                                                    </td>
                                                    <td>
                                                        <div class="btn-list">
                                                            <a href="/certificates/view/<?php echo $cert['id']; ?>" 
                                                               class="btn btn-sm btn-outline-primary">
                                                                <i class="ti ti-eye"></i>
                                                            </a>
                                                            <?php if ($cert['certificate_file']): ?>
                                                                <a href="/certificates/download/<?php echo $cert['id']; ?>" 
                                                                   class="btn btn-sm btn-outline-success">
                                                                    <i class="ti ti-download"></i>
                                                                </a>
                                                            <?php endif; ?>
                                                            <?php if ($userRole === ROLE_CERTIFIER && $cert['status'] === 'active'): ?>
                                                                <button type="button" 
                                                                        class="btn btn-sm btn-outline-danger"
                                                                        onclick="revokeCertificate(<?php echo $cert['id']; ?>)">
                                                                    <i class="ti ti-ban"></i>
                                                                </button>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Pagination -->
                                <?php if ($totalPages > 1): ?>
                                    <div class="card-footer d-flex align-items-center">
                                        <p class="m-0 text-muted">
                                            Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $limit, $totalCertificates); ?> 
                                            of <?php echo $totalCertificates; ?> certificates
                                        </p>
                                        <ul class="pagination m-0 ms-auto">
                                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                                    <a class="page-link" href="/certificates?page=<?php echo $i; ?>&status=<?php echo $status; ?>&search=<?php echo urlencode($search); ?>">
                                                        <?php echo $i; ?>
                                                    </a>
                                                </li>
                                            <?php endfor; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>
                                
                            <?php else: ?>
                                <div class="card-body text-center py-6">
                                    <div class="empty-icon mb-3">
                                        <i class="ti ti-certificate" style="font-size: 3rem; color: var(--tblr-border-color);"></i>
                                    </div>
                                    <h3 class="empty-title">No certificates found</h3>
                                    <p class="empty-subtitle text-muted">
                                        <?php if ($userRole === ROLE_CERTIFIER): ?>
                                            You haven't issued any certificates yet. Start by reviewing and approving applications.
                                        <?php else: ?>
                                            You don't have any certificates yet. Apply for certification standards to get started.
                                        <?php endif; ?>
                                    </p>
                                    <?php if ($userRole === ROLE_CERTIFIER): ?>
                                        <div class="empty-action">
                                            <a href="/applications?status=approved" class="btn btn-primary">
                                                <i class="ti ti-plus"></i> Issue First Certificate
                                            </a>
                                        </div>
                                    <?php else: ?>
                                        <div class="empty-action">
                                            <a href="/standards" class="btn btn-primary">
                                                <i class="ti ti-search"></i> Browse Standards
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Revoke Certificate Modal -->
        <div class="modal modal-blur fade" id="revokeModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Revoke Certificate</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form id="revokeForm" method="POST">
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Revocation Reason</label>
                                <textarea name="revocation_reason" class="form-control" rows="3" required 
                                          placeholder="Please provide the reason for revoking this certificate..."></textarea>
                            </div>
                            <div class="alert alert-warning">
                                <i class="ti ti-alert-triangle"></i>
                                <strong>Warning:</strong> This action cannot be undone. The certificate will be permanently revoked.
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-link" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger">
                                <i class="ti ti-ban"></i> Revoke Certificate
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <script>
        function revokeCertificate(certificateId) {
            const modal = new bootstrap.Modal(document.getElementById('revokeModal'));
            const form = document.getElementById('revokeForm');
            form.action = '/certificates/revoke/' + certificateId;
            modal.show();
        }
        </script>
        
        <?php
        // Include footer
        include INCLUDES_PATH . 'footer.php';
        
    } catch (Exception $e) {
        error_log('Error in certificates listing: ' . $e->getMessage());
        
        // Set page title
        $pageTitle = 'Certificates - Error';
        
        // Include header
        include INCLUDES_PATH . 'header.php';
        ?>
        
        <div class="page-body">
            <div class="container-xl">
                <div class="alert alert-danger">
                    <i class="ti ti-alert-circle"></i>
                    An error occurred while loading certificates. Please try again later.
                </div>
            </div>
        </div>
        
        <?php
        // Include footer
        include INCLUDES_PATH . 'footer.php';
    }
}