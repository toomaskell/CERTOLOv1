<?php
/**
 * CERTOLO - Reviews Module
 * Application review management for certifiers
 */

ob_start();

// Auth check - only certifiers
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'certifier') {
    echo "<script>window.location.href='/login';</script>";
    exit;
}

$userId = $_SESSION['user_id'];

// Get filter parameters
$status = $_GET['status'] ?? 'pending';
$standard = $_GET['standard'] ?? '';
$sort = $_GET['sort'] ?? 'oldest';
$search = $_GET['search'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

// Set page title
$pageTitle = 'Reviews - Application Management';

// Include header
if (file_exists('includes/header.php')) {
    include 'includes/header.php';
} else {
    echo '<!DOCTYPE html><html><head><title>CERTOLO - Reviews</title>
    <link href="https://cdn.jsdelivr.net/npm/@tabler/core@latest/dist/css/tabler.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@tabler/icons@latest/iconfont/tabler-icons.min.css" rel="stylesheet">
    </head><body>';
}
?>

<div class="page-header d-print-none">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col">
                <h2 class="page-title">
                    <i class="ti ti-clipboard-check me-2"></i>
                    Reviews
                </h2>
                <div class="text-muted mt-1">
                    Manage application reviews and certification decisions
                </div>
            </div>
                <div class="col-auto ms-auto d-print-none">
                    <div class="btn-list">
                        <!-- Simple Filter Buttons (Backup if dropdown fails) -->
                        <div class="btn-group d-none d-md-flex" role="group">
                            <a href="?status=pending" class="btn btn-sm <?php echo $status === 'pending' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                                <i class="ti ti-clock"></i> Pending
                            </a>
                            <a href="?status=submitted" class="btn btn-sm <?php echo $status === 'submitted' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                                <i class="ti ti-send"></i> Submitted
                            </a>
                            <a href="?status=under_review" class="btn btn-sm <?php echo $status === 'under_review' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                                <i class="ti ti-eye"></i> Reviewing
                            </a>
                            <a href="?status=all" class="btn btn-sm <?php echo $status === 'all' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                                <i class="ti ti-list"></i> All
                            </a>
                        </div>
                        
                        <!-- Dropdown Filter (Mobile + Fallback) -->
                        <div class="dropdown d-md-none">
                            <button class="btn btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" id="filterDropdown">
                                <i class="ti ti-filter"></i> 
                                <?php 
                                echo match($status) {
                                    'pending' => 'Pending Review',
                                    'submitted' => 'Just Submitted', 
                                    'under_review' => 'Under Review',
                                    'all' => 'All Applications',
                                    default => 'Pending Review'
                                };
                                ?>
                            </button>
                            <div class="dropdown-menu">
                                <a class="dropdown-item <?php echo $status === 'pending' ? 'active' : ''; ?>" href="?status=pending">
                                    <i class="ti ti-clock text-yellow"></i> Pending Review
                                </a>
                                <a class="dropdown-item <?php echo $status === 'submitted' ? 'active' : ''; ?>" href="?status=submitted">
                                    <i class="ti ti-send text-blue"></i> Just Submitted
                                </a>
                                <a class="dropdown-item <?php echo $status === 'under_review' ? 'active' : ''; ?>" href="?status=under_review">
                                    <i class="ti ti-eye text-orange"></i> Under Review
                                </a>
                                <a class="dropdown-item <?php echo $status === 'all' ? 'active' : ''; ?>" href="?status=all">
                                    <i class="ti ti-list"></i> All Applications
                                </a>
                            </div>
                        </div>
                        
                        <button class="btn btn-primary" onclick="showBulkActions()" id="bulkActionsBtn">
                            <i class="ti ti-checks"></i> Bulk Actions
                        </button>
                    </div>
                </div>
        </div>
    </div>
</div>

<div class="page-body">
    <div class="container-xl">
        
        <!-- Filter Debug (temporary) -->
        <div class="alert alert-info">
            <strong>🔍 Current Filter:</strong> <?php echo $status; ?> 
            | <strong>Applications Found:</strong> <?php echo $totalApps ?? 0; ?>
            | <strong>URL:</strong> <?php echo $_SERVER['REQUEST_URI']; ?>
        </div>
        
        <?php
        try {
            require_once 'config/database.php';
            $db = Database::getInstance();
            
            // Get review statistics
            $statsQuery = "SELECT 
                COUNT(CASE WHEN status = 'submitted' THEN 1 END) as submitted,
                COUNT(CASE WHEN status = 'under_review' THEN 1 END) as under_review,
                COUNT(CASE WHEN status IN ('submitted', 'under_review') THEN 1 END) as pending_total,
                COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved,
                COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected,
                COUNT(*) as total_applications,
                AVG(CASE WHEN reviewed_at IS NOT NULL AND submitted_at IS NOT NULL 
                    THEN TIMESTAMPDIFF(HOUR, submitted_at, reviewed_at) END) as avg_review_hours
                FROM applications 
                WHERE certifier_id = $userId";
            
            $statsStmt = $db->query($statsQuery);
            $stats = $statsStmt->fetch();
            
            // Get standards for filtering
            $standardsStmt = $db->query("SELECT id, name FROM standards WHERE certifier_id = $userId ORDER BY name");
            $standards = $standardsStmt->fetchAll();
        ?>
        
        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-sm-6 col-lg-3">
                <div class="card card-sm">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-auto">
                                <span class="bg-yellow text-white avatar">
                                    <i class="ti ti-clock"></i>
                                </span>
                            </div>
                            <div class="col">
                                <div class="font-weight-medium"><?php echo $stats['pending_total']; ?> Pending</div>
                                <div class="text-muted">Need your attention</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card card-sm">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-auto">
                                <span class="bg-green text-white avatar">
                                    <i class="ti ti-check"></i>
                                </span>
                            </div>
                            <div class="col">
                                <div class="font-weight-medium"><?php echo $stats['approved']; ?> Approved</div>
                                <div class="text-muted">Ready for certificates</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card card-sm">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-auto">
                                <span class="bg-red text-white avatar">
                                    <i class="ti ti-x"></i>
                                </span>
                            </div>
                            <div class="col">
                                <div class="font-weight-medium"><?php echo $stats['rejected']; ?> Rejected</div>
                                <div class="text-muted">Did not meet criteria</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card card-sm">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-auto">
                                <span class="bg-blue text-white avatar">
                                    <i class="ti ti-clock-hour-4"></i>
                                </span>
                            </div>
                            <div class="col">
                                <div class="font-weight-medium">
                                    <?php echo $stats['avg_review_hours'] ? round($stats['avg_review_hours'], 1) . 'h' : 'N/A'; ?>
                                </div>
                                <div class="text-muted">Avg review time</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Filters and Search -->
        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <input type="hidden" name="status" value="<?php echo htmlspecialchars($status); ?>">
                    
                    <div class="col-md-4">
                        <label class="form-label">Search Applications</label>
                        <div class="input-icon">
                            <span class="input-icon-addon">
                                <i class="ti ti-search"></i>
                            </span>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                                   class="form-control" placeholder="Company name or application number...">
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Standard</label>
                        <select name="standard" class="form-select">
                            <option value="">All Standards</option>
                            <?php foreach ($standards as $std): ?>
                                <option value="<?php echo $std['id']; ?>" <?php echo $standard == $std['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($std['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Sort By</label>
                        <select name="sort" class="form-select">
                            <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                            <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest First</option>
                            <option value="priority" <?php echo $sort === 'priority' ? 'selected' : ''; ?>>Priority</option>
                            <option value="company" <?php echo $sort === 'company' ? 'selected' : ''; ?>>Company A-Z</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <div>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="ti ti-filter"></i> Filter
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <?php
        // Build applications query
        $whereConditions = ["a.certifier_id = $userId"];
        
        // Status filter
        if ($status === 'pending') {
            $whereConditions[] = "a.status IN ('submitted', 'under_review')";
        } elseif ($status !== 'all') {
            $statusClean = addslashes($status);
            $whereConditions[] = "a.status = '$statusClean'";
        }
        
        // Standard filter
        if ($standard) {
            $standardClean = addslashes($standard);
            $whereConditions[] = "a.standard_id = '$standardClean'";
        }
        
        // Search filter
        if ($search) {
            $searchClean = addslashes($search);
            $whereConditions[] = "(u.company_name LIKE '%$searchClean%' OR a.application_number LIKE '%$searchClean%')";
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        
        // Sort order
        $orderClause = match($sort) {
            'newest' => 'a.submitted_at DESC',
            'priority' => 'FIELD(a.status, "submitted", "under_review", "approved", "rejected"), a.submitted_at ASC',
            'company' => 'u.company_name ASC',
            'oldest' => 'a.submitted_at ASC',
            default => 'a.submitted_at ASC'
        };
        
        // Get applications
        $sql = "SELECT a.*, u.company_name, u.contact_person, u.email, s.name as standard_name, s.type as standard_type
                FROM applications a
                JOIN users u ON a.applicant_id = u.id  
                JOIN standards s ON a.standard_id = s.id
                WHERE $whereClause
                ORDER BY $orderClause
                LIMIT $perPage OFFSET $offset";
        
        $stmt = $db->query($sql);
        $applications = $stmt->fetchAll();
        
        // Get total count
        $countSql = "SELECT COUNT(*) FROM applications a
                     JOIN users u ON a.applicant_id = u.id  
                     JOIN standards s ON a.standard_id = s.id
                     WHERE $whereClause";
        $totalApps = $db->query($countSql)->fetchColumn();
        $totalPages = ceil($totalApps / $perPage);
        ?>
        
        <!-- Applications List -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="ti ti-file-text me-2"></i>
                    <?php 
                    $statusTitle = match($status) {
                        'pending' => 'Pending Review',
                        'submitted' => 'Just Submitted',
                        'under_review' => 'Under Review',
                        'all' => 'All Applications',
                        default => ucfirst($status) . ' Applications'
                    };
                    echo "$statusTitle ($totalApps)";
                    ?>
                </h3>
                <div class="card-actions">
                    <?php if (!empty($applications)): ?>
                        <button class="btn btn-sm btn-outline-primary" onclick="selectAll()">
                            <i class="ti ti-select-all"></i> Select All
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if (empty($applications)): ?>
                <div class="card-body text-center py-5">
                    <div class="text-muted mb-3">
                        <i class="ti ti-inbox" style="font-size: 3rem;"></i>
                    </div>
                    <h3>No applications found</h3>
                    <p class="text-muted">
                        <?php if ($status === 'pending'): ?>
                            No applications are currently waiting for review.
                        <?php elseif ($search): ?>
                            No applications match your search criteria.
                        <?php else: ?>
                            No applications found with the selected filters.
                        <?php endif; ?>
                    </p>
                    <?php if ($search || $standard): ?>
                        <a href="/reviews" class="btn btn-primary">Clear Filters</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table card-table table-vcenter">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="selectAllCheckbox" onchange="toggleAllSelections()"></th>
                                <th>Application</th>
                                <th>Company</th>
                                <th>Standard</th>
                                <th>Status</th>
                                <th>Submitted</th>
                                <th>Priority</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($applications as $app): 
                                $daysSinceSubmitted = $app['submitted_at'] ? 
                                    ceil((time() - strtotime($app['submitted_at'])) / (60 * 60 * 24)) : 0;
                                
                                $priority = 'normal';
                                if ($daysSinceSubmitted > 7) $priority = 'high';
                                elseif ($daysSinceSubmitted > 3) $priority = 'medium';
                                
                                $priorityColors = [
                                    'normal' => 'text-muted',
                                    'medium' => 'text-warning', 
                                    'high' => 'text-danger'
                                ];
                            ?>
                                <tr class="review-row">
                                    <td>
                                        <input type="checkbox" class="form-check-input application-checkbox" 
                                               value="<?php echo $app['id']; ?>">
                                    </td>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($app['application_number']); ?></strong>
                                            <div class="small text-muted">ID: <?php echo $app['id']; ?></div>
                                        </div>
                                    </td>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($app['company_name']); ?></strong>
                                            <div class="small text-muted"><?php echo htmlspecialchars($app['contact_person']); ?></div>
                                        </div>
                                    </td>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($app['standard_name']); ?></strong>
                                            <div class="small text-muted"><?php echo htmlspecialchars($app['standard_type']); ?></div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php 
                                        $badgeClass = match($app['status']) {
                                            'submitted' => 'bg-blue',
                                            'under_review' => 'bg-yellow text-dark',
                                            'approved' => 'bg-green',
                                            'rejected' => 'bg-red',
                                            'issued' => 'bg-purple',
                                            default => 'bg-secondary'
                                        };
                                        ?>
                                        <span class="badge <?php echo $badgeClass; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $app['status'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div><?php echo $app['submitted_at'] ? date('M j, Y', strtotime($app['submitted_at'])) : 'Not submitted'; ?></div>
                                        <div class="small text-muted">
                                            <?php echo $app['submitted_at'] ? date('g:i A', strtotime($app['submitted_at'])) : ''; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="<?php echo $priorityColors[$priority]; ?>">
                                            <i class="ti ti-flag-filled"></i>
                                            <?php echo ucfirst($priority); ?>
                                            <?php if ($daysSinceSubmitted > 0): ?>
                                                <div class="small"><?php echo $daysSinceSubmitted; ?> days</div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="btn-list">
                                            <a href="/applications/view/<?php echo $app['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                <i class="ti ti-eye"></i> View
                                            </a>
                                            <?php if (in_array($app['status'], ['submitted', 'under_review'])): ?>
                                                <a href="/applications/review/<?php echo $app['id']; ?>" class="btn btn-sm btn-primary">
                                                    <i class="ti ti-clipboard-check"></i> Review
                                                </a>
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
                            Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $perPage, $totalApps); ?> of <?php echo $totalApps; ?> applications
                        </p>
                        <ul class="pagination m-0 ms-auto">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo ($page - 1); ?>&status=<?php echo urlencode($status); ?>&standard=<?php echo urlencode($standard); ?>&sort=<?php echo urlencode($sort); ?>&search=<?php echo urlencode($search); ?>">
                                        <i class="ti ti-chevron-left"></i> prev
                                    </a>
                                </li>
                            <?php endif; ?>
                            
                            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>&status=<?php echo urlencode($status); ?>&standard=<?php echo urlencode($standard); ?>&sort=<?php echo urlencode($sort); ?>&search=<?php echo urlencode($search); ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                            
                            <?php if ($page < $totalPages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo ($page + 1); ?>&status=<?php echo urlencode($status); ?>&standard=<?php echo urlencode($standard); ?>&sort=<?php echo urlencode($sort); ?>&search=<?php echo urlencode($search); ?>">
                                        next <i class="ti ti-chevron-right"></i>
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        
        <?php
        } catch (Exception $e) {
            ?>
            <div class="alert alert-danger">
                <strong>Error:</strong> <?php echo htmlspecialchars($e->getMessage()); ?>
            </div>
            <?php
        }
        ?>
    </div>
</div>

<!-- Bulk Actions Modal -->
<div class="modal fade" id="bulkActionsModal" tabindex="-1" aria-labelledby="bulkActionsModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="bulkActionsModalLabel">
                    <i class="ti ti-checks me-2"></i>Bulk Actions
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <strong><i class="ti ti-info-circle me-1"></i>Selected:</strong> 
                    <span id="selectedCount">0</span> applications
                </div>
                
                <p class="mb-3">Choose an action to apply to the selected applications:</p>
                
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-outline-primary" onclick="bulkAction('under_review')">
                        <i class="ti ti-eye text-orange me-2"></i> Mark as Under Review
                    </button>
                    <button type="button" class="btn btn-outline-success" onclick="bulkAction('approve')">
                        <i class="ti ti-check text-green me-2"></i> Bulk Approve Selected
                    </button>
                    <button type="button" class="btn btn-outline-danger" onclick="bulkAction('reject')">
                        <i class="ti ti-x text-red me-2"></i> Bulk Reject Selected
                    </button>
                    <button type="button" class="btn btn-outline-info" onclick="exportSelected()">
                        <i class="ti ti-download text-blue me-2"></i> Export Selected to CSV
                    </button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="ti ti-x me-1"></i>Cancel
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Fallback: Simple Bulk Actions (if modal fails) -->
<div id="bulkActionsFallback" style="display: none;">
    <div style="position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); 
                background: white; border: 2px solid #ccc; padding: 20px; z-index: 9999; 
                box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
        <h3>Bulk Actions</h3>
        <p>Select an action for <span id="fallbackCount">0</span> applications:</p>
        <div style="margin: 10px 0;">
            <button onclick="bulkAction('under_review')" style="margin: 5px;">Under Review</button>
            <button onclick="bulkAction('approve')" style="margin: 5px;">Approve</button>
            <button onclick="bulkAction('reject')" style="margin: 5px;">Reject</button>
            <button onclick="exportSelected()" style="margin: 5px;">Export CSV</button>
            <button onclick="closeFallback()" style="margin: 5px;">Cancel</button>
        </div>
    </div>
</div>

<script>
// Bulk actions functionality
function showBulkActions() {
    console.log('showBulkActions called');
    
    const selected = document.querySelectorAll('.application-checkbox:checked').length;
    console.log('Selected applications:', selected);
    
    if (selected === 0) {
        alert('Please select applications first by checking the boxes in the table');
        return;
    }
    
    document.getElementById('selectedCount').textContent = selected;
    
    // Try to show Bootstrap modal
    try {
        const modalElement = document.getElementById('bulkActionsModal');
        if (modalElement && typeof bootstrap !== 'undefined') {
            const modal = new bootstrap.Modal(modalElement);
            modal.show();
            console.log('Bootstrap modal shown');
        } else {
            // Fallback: Simple confirm dialog
            showBulkActionsFallback(selected);
        }
    } catch (error) {
        console.error('Modal error:', error);
        showBulkActionsFallback(selected);
    }
}

function showBulkActionsFallback(selected) {
    const selectedIds = Array.from(document.querySelectorAll('.application-checkbox:checked'))
        .map(cb => cb.value);
    
    const actions = [
        '1. Mark as Under Review',
        '2. Bulk Approve Selected',
        '3. Bulk Reject Selected', 
        '4. Export Selected to CSV',
        '5. Cancel'
    ];
    
    const choice = prompt(`Bulk Actions for ${selected} applications:\n\n${actions.join('\n')}\n\nEnter choice (1-5):`);
    
    switch(choice) {
        case '1':
            bulkAction('under_review');
            break;
        case '2':
            bulkAction('approve');
            break;
        case '3':
            bulkAction('reject');
            break;
        case '4':
            exportSelected();
            break;
        case '5':
        case null:
            console.log('Bulk action cancelled');
            break;
        default:
            alert('Invalid choice. Please try again.');
    }
}

function toggleAllSelections() {
    const selectAll = document.getElementById('selectAllCheckbox');
    const checkboxes = document.querySelectorAll('.application-checkbox');
    
    checkboxes.forEach(cb => {
        cb.checked = selectAll.checked;
    });
    
    updateBulkActionsButton();
}

function selectAll() {
    document.getElementById('selectAllCheckbox').checked = true;
    toggleAllSelections();
}

function updateBulkActionsButton() {
    const selected = document.querySelectorAll('.application-checkbox:checked').length;
    const bulkBtn = document.querySelector('[onclick="showBulkActions()"]');
    
    if (selected > 0) {
        bulkBtn.innerHTML = `<i class="ti ti-checks"></i> Bulk Actions (${selected})`;
        bulkBtn.classList.remove('btn-primary');
        bulkBtn.classList.add('btn-warning');
    } else {
        bulkBtn.innerHTML = '<i class="ti ti-checks"></i> Bulk Actions';
        bulkBtn.classList.remove('btn-warning');
        bulkBtn.classList.add('btn-primary');
    }
}

function bulkAction(action) {
    const selected = Array.from(document.querySelectorAll('.application-checkbox:checked'))
        .map(cb => cb.value);
    
    if (selected.length === 0) {
        alert('No applications selected');
        return;
    }
    
    const actionNames = {
        'under_review': 'mark as under review',
        'approve': 'approve',
        'reject': 'reject'
    };
    
    const actionName = actionNames[action] || action;
    
    if (confirm(`Are you sure you want to ${actionName} ${selected.length} applications?\n\nSelected IDs: ${selected.join(', ')}`)) {
        // Show loading state
        const bulkBtn = document.getElementById('bulkActionsBtn');
        if (bulkBtn) {
            bulkBtn.innerHTML = '<i class="ti ti-loader-2"></i> Processing...';
            bulkBtn.disabled = true;
        }
        
        // Simulate the bulk action (replace with actual AJAX call)
        setTimeout(() => {
            alert(`✅ Bulk ${actionName} completed!\n\n${selected.length} applications processed.\n\nIDs: ${selected.join(', ')}\n\n(This is a simulation - actual functionality will be implemented next.)`);
            
            // Reset bulk actions
            if (bulkBtn) {
                bulkBtn.innerHTML = '<i class="ti ti-checks"></i> Bulk Actions';
                bulkBtn.disabled = false;
            }
            
            // Clear selections
            document.querySelectorAll('.application-checkbox').forEach(cb => cb.checked = false);
            document.getElementById('selectAllCheckbox').checked = false;
            updateBulkActionsButton();
            
            // Close modal if it exists
            try {
                const modal = bootstrap.Modal.getInstance(document.getElementById('bulkActionsModal'));
                if (modal) modal.hide();
            } catch (error) {
                console.log('Modal close error (non-critical):', error);
            }
            
            // Optional: Refresh page to show updated data
            // window.location.reload();
            
        }, 1500); // Simulate processing time
    }
}

function exportSelected() {
    const selected = Array.from(document.querySelectorAll('.application-checkbox:checked'))
        .map(cb => cb.value);
    
    if (selected.length === 0) {
        alert('No applications selected');
        return;
    }
    
    // Simulate CSV export
    const csvData = `Application ID,Company Name,Standard,Status,Submitted Date\n` +
        selected.map(id => `${id},"Sample Company","Sample Standard","submitted","2024-01-01"`).join('\n');
    
    // Create download link
    const blob = new Blob([csvData], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `applications_${selected.length}_${new Date().toISOString().split('T')[0]}.csv`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
    
    alert(`📊 CSV Export completed!\n\n${selected.length} applications exported.\n\nFile downloaded: applications_${selected.length}_${new Date().toISOString().split('T')[0]}.csv`);
    
    // Close modal
    try {
        const modal = bootstrap.Modal.getInstance(document.getElementById('bulkActionsModal'));
        if (modal) modal.hide();
    } catch (error) {
        console.log('Modal close error (non-critical):', error);
    }
}

// Update bulk actions button when checkboxes change
document.addEventListener('change', function(e) {
    if (e.target.classList.contains('application-checkbox')) {
        updateBulkActionsButton();
    }
});

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    console.log('Reviews page DOM loaded');
    updateBulkActionsButton();
    
    // Test bulk actions button
    const bulkBtn = document.getElementById('bulkActionsBtn');
    if (bulkBtn) {
        console.log('Bulk actions button found');
        
        // Add direct click handler as backup
        bulkBtn.addEventListener('click', function(e) {
            console.log('Bulk button clicked directly');
            e.preventDefault();
            showBulkActions();
        });
    } else {
        console.error('Bulk actions button not found!');
    }
    
    // Initialize Bootstrap dropdowns manually (fallback)
    try {
        if (typeof bootstrap !== 'undefined') {
            const dropdowns = document.querySelectorAll('[data-bs-toggle="dropdown"]');
            dropdowns.forEach(function(dropdown) {
                new bootstrap.Dropdown(dropdown);
            });
            console.log('Bootstrap dropdowns initialized');
        } else {
            console.warn('Bootstrap not loaded, dropdowns may not work');
        }
    } catch (error) {
        console.error('Dropdown initialization error:', error);
    }
    
    // Add click handlers for filter buttons as backup
    const filterButtons = document.querySelectorAll('.btn-group a[href*="status="]');
    filterButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            console.log('Filter clicked:', this.href);
            // Let the default link behavior work
        });
    });
    
    // Add test bulk actions button
    addTestBulkButton();
});

// Add a test button for debugging
function addTestBulkButton() {
    const container = document.querySelector('.page-header .btn-list');
    if (container) {
        const testBtn = document.createElement('button');
        testBtn.className = 'btn btn-outline-secondary';
        testBtn.innerHTML = '<i class="ti ti-bug"></i> Test Bulk';
        testBtn.onclick = function() {
            alert('Test bulk button works!\n\nThis confirms JavaScript is running.');
            console.log('Test button clicked - JS working');
            
            // Try to call the main function
            try {
                showBulkActions();
            } catch (error) {
                console.error('showBulkActions error:', error);
                alert('Error in showBulkActions: ' + error.message);
            }
        };
        container.appendChild(testBtn);
        console.log('Test bulk button added');
    }
}

function closeFallback() {
    document.getElementById('bulkActionsFallback').style.display = 'none';
}
</script>

<style>
.avatar {
    width: 2.5rem;
    height: 2.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    font-weight: 600;
}

.review-row:hover {
    background-color: rgba(32, 107, 196, 0.05);
}

.bg-yellow.text-dark {
    background-color: #ffc107 !important;
    color: #000 !important;
}

.bg-purple {
    background-color: #6f42c1 !important;
}

.text-warning {
    color: #ffc107 !important;
}

.card-sm .card-body {
    padding: 1rem;
}

.font-weight-medium {
    font-weight: 500;
}
</style>

<?php
if (file_exists('includes/footer.php')) {
    include 'includes/footer.php';
} else {
    echo "</body></html>";
}

ob_end_flush();
?>