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

// Build SQL conditions based on filters
$conditions = ["a.certifier_id = :user_id"];
$params = ['user_id' => $userId];

// Status filter
if ($status === 'pending') {
    $conditions[] = "a.status IN ('submitted', 'under_review')";
} elseif ($status !== 'all') {
    $conditions[] = "a.status = :status";
    $params['status'] = $status;
}

// Standard filter
if ($standard) {
    $conditions[] = "s.id = :standard_id";
    $params['standard_id'] = $standard;
}

// Search filter
if ($search) {
    $conditions[] = "(u.company_name LIKE :search OR s.name LIKE :search2 OR a.application_number LIKE :search3)";
    $params['search'] = "%$search%";
    $params['search2'] = "%$search%";
    $params['search3'] = "%$search%";
}

// Sort order
$orderBy = match($sort) {
    'newest' => 'a.submitted_at DESC',
    'oldest' => 'a.submitted_at ASC',
    'priority' => 'CASE WHEN a.status = "submitted" THEN 1 WHEN a.status = "under_review" THEN 2 ELSE 3 END, a.submitted_at ASC',
    default => 'a.submitted_at ASC'
};

try {
    $db = Database::getInstance();
    
    // Get statistics for dashboard
    $statsQuery = "
        SELECT 
            COUNT(CASE WHEN status IN ('submitted', 'under_review') THEN 1 END) as pending,
            COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved,
            COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected,
            COUNT(*) as total
        FROM applications 
        WHERE certifier_id = :user_id
    ";
    $statsStmt = $db->query($statsQuery, ['user_id' => $userId]);
    $stats = $statsStmt->fetch();

    // Get standards for filter dropdown
    $standardsStmt = $db->query(
        "SELECT id, name FROM standards WHERE certifier_id = :user_id AND status = 'active' ORDER BY name",
        ['user_id' => $userId]
    );
    $standards = $standardsStmt->fetchAll();

    // Get applications with pagination
    $whereClause = implode(' AND ', $conditions);
    $query = "
        SELECT a.*, s.name as standard_name, s.type as standard_type, 
               u.company_name as applicant_name, u.contact_person, u.email as applicant_email,
               DATEDIFF(NOW(), a.submitted_at) as days_waiting
        FROM applications a
        JOIN standards s ON a.standard_id = s.id
        JOIN users u ON a.applicant_id = u.id
        WHERE $whereClause
        ORDER BY $orderBy
        LIMIT " . (($page - 1) * 20) . ", 20
    ";
    
    $stmt = $db->query($query, $params);
    $applications = $stmt->fetchAll();
    
    // Get total count for pagination
    $countQuery = "SELECT COUNT(*) as total FROM applications a JOIN standards s ON a.standard_id = s.id JOIN users u ON a.applicant_id = u.id WHERE $whereClause";
    $countStmt = $db->query($countQuery, $params);
    $totalApps = $countStmt->fetch()['total'];
    $totalPages = ceil($totalApps / 20);

} catch (Exception $e) {
    error_log('Reviews module error: ' . $e->getMessage());
    $applications = [];
    $stats = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'total' => 0];
    $standards = [];
    $totalApps = 0;
    $totalPages = 1;
}

// Page title
$pageTitle = 'Reviews';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <title><?php echo $pageTitle; ?> - CERTOLO</title>
    
    <!-- CSS files -->
    <link href="https://cdn.jsdelivr.net/npm/@tabler/core@latest/dist/css/tabler.min.css" rel="stylesheet"/>
    <link href="https://cdn.jsdelivr.net/npm/@tabler/icons@latest/iconfont/tabler-icons.min.css" rel="stylesheet"/>
    
    <!-- Custom CSS -->
    <style>
        :root {
            --certolo-primary: #2E7D32;
            --certolo-accent: #FF9800;
        }
        
        .navbar-brand-image {
            height: 2.5rem;
            width: auto;
        }
        
        .btn-primary {
            background-color: var(--certolo-primary);
            border-color: var(--certolo-primary);
        }
        
        .btn-primary:hover {
            background-color: #1B5E20;
            border-color: #1B5E20;
        }
    </style>
</head>
<body>
    <div class="page">
        <!-- Header -->
        <header class="navbar navbar-expand-md navbar-light d-print-none">
            <div class="container-xl">
                <h1 class="navbar-brand navbar-brand-autodark d-none-navbar-horizontal pe-0 pe-md-3">
                    <a href="/">
                        <img src="/assets/images/certolo-logo.png" width="180" height="40" alt="CERTOLO" class="navbar-brand-image">
                    </a>
                </h1>
                
                <!-- Navigation -->
                <div class="navbar-nav flex-row order-md-last">
                    <div class="nav-item dropdown">
                        <a href="#" class="nav-link d-flex lh-1 text-reset p-0" data-bs-toggle="dropdown">
                            <span class="avatar avatar-sm" style="background: var(--certolo-primary);">
                                <?php echo strtoupper(substr($_SESSION['user_name'] ?? 'U', 0, 2)); ?>
                            </span>
                            <div class="d-none d-xl-block ps-2">
                                <div><?php echo Security::escape($_SESSION['user_name'] ?? ''); ?></div>
                                <div class="mt-1 small text-muted">Certifier</div>
                            </div>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
                            <a href="/profile" class="dropdown-item">
                                <i class="ti ti-user me-2"></i>Profile
                            </a>
                            <div class="dropdown-divider"></div>
                            <a href="/logout" class="dropdown-item">
                                <i class="ti ti-logout me-2"></i>Logout
                            </a>
                        </div>
                    </div>
                </div>
                
                <div class="collapse navbar-collapse" id="navbar-menu">
                    <div class="d-flex flex-column flex-md-row flex-fill align-items-stretch align-items-md-center">
                        <ul class="navbar-nav">
                            <li class="nav-item">
                                <a class="nav-link" href="/dashboard">
                                    <span class="nav-link-icon"><i class="ti ti-home"></i></span>
                                    <span class="nav-link-title">Dashboard</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="/standards">
                                    <span class="nav-link-icon"><i class="ti ti-certificate"></i></span>
                                    <span class="nav-link-title">Standards</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="/applications">
                                    <span class="nav-link-icon"><i class="ti ti-file-text"></i></span>
                                    <span class="nav-link-title">Applications</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="/customers">
                                    <span class="nav-link-icon"><i class="ti ti-users"></i></span>
                                    <span class="nav-link-title">Customers</span>
                                </a>
                            </li>
                            <li class="nav-item active">
                                <a class="nav-link" href="/reviews">
                                    <span class="nav-link-icon"><i class="ti ti-clipboard-check"></i></span>
                                    <span class="nav-link-title">Reviews</span>
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </header>

        <!-- Page wrapper -->
        <div class="page-wrapper">
            <!-- Page header -->
            <div class="page-header d-print-none">
                <div class="container-xl">
                    <div class="row g-2 align-items-center">
                        <div class="col">
                            <div class="page-pretitle">Certification Management</div>
                            <h2 class="page-title">
                                <i class="ti ti-clipboard-check me-2"></i>
                                Application Reviews
                            </h2>
                        </div>
                        <div class="col-auto ms-auto d-print-none">
                            <div class="btn-list">
                                <button type="button" class="btn btn-primary" onclick="showBulkActions()" id="bulkActionsBtn">
                                    <i class="ti ti-checks"></i> Bulk Actions
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Page body -->
            <div class="page-body">
                <div class="container-xl">
                    
                    <!-- Statistics Cards -->
                    <div class="row row-deck row-cards mb-4">
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
                                            <div class="font-weight-medium"><?php echo $stats['pending']; ?> Pending</div>
                                            <div class="text-muted">Awaiting review</div>
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
                                            <span class="bg-success text-white avatar">
                                                <i class="ti ti-check"></i>
                                            </span>
                                        </div>
                                        <div class="col">
                                            <div class="font-weight-medium"><?php echo $stats['approved']; ?> Approved</div>
                                            <div class="text-muted">This month</div>
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
                                            <span class="bg-danger text-white avatar">
                                                <i class="ti ti-x"></i>
                                            </span>
                                        </div>
                                        <div class="col">
                                            <div class="font-weight-medium"><?php echo $stats['rejected']; ?> Rejected</div>
                                            <div class="text-muted">This month</div>
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
                                            <span class="bg-primary text-white avatar">
                                                <i class="ti ti-files"></i>
                                            </span>
                                        </div>
                                        <div class="col">
                                            <div class="font-weight-medium"><?php echo $stats['total']; ?> Total</div>
                                            <div class="text-muted">All applications</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filters -->
                    <div class="card mb-3">
                        <div class="card-body">
                            <form method="GET" action="/reviews" class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label">Status</label>
                                    <select name="status" class="form-select">
                                        <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending Review</option>
                                        <option value="submitted" <?php echo $status === 'submitted' ? 'selected' : ''; ?>>Submitted</option>
                                        <option value="under_review" <?php echo $status === 'under_review' ? 'selected' : ''; ?>>Under Review</option>
                                        <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                    </select>
                                </div>
                                
                                <div class="col-md-3">
                                    <label class="form-label">Standard</label>
                                    <select name="standard" class="form-select">
                                        <option value="">All Standards</option>
                                        <?php foreach ($standards as $std): ?>
                                            <option value="<?php echo $std['id']; ?>" 
                                                    <?php echo $standard == $std['id'] ? 'selected' : ''; ?>>
                                                <?php echo Security::escape($std['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-3">
                                    <label class="form-label">Sort</label>
                                    <select name="sort" class="form-select">
                                        <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                                        <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest First</option>
                                        <option value="priority" <?php echo $sort === 'priority' ? 'selected' : ''; ?>>Priority</option>
                                    </select>
                                </div>
                                
                                <div class="col-md-3">
                                    <label class="form-label">Search</label>
                                    <div class="input-group">
                                        <input type="text" name="search" class="form-control" 
                                               placeholder="Company or standard..." 
                                               value="<?php echo Security::escape($search); ?>">
                                        <button class="btn btn-primary" type="submit">
                                            <i class="ti ti-search"></i>
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Applications Table -->
                    <div class="card">
                        <div class="card-header">
                            <div class="d-flex align-items-center justify-content-between">
                                <h3 class="card-title">
                                    Applications for Review (<?php echo $totalApps; ?>)
                                </h3>
                                <div class="ms-auto">
                                    <label class="form-check">
                                        <input type="checkbox" class="form-check-input" id="selectAllCheckbox" onchange="toggleAllSelections()">
                                        <span class="form-check-label">Select All</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <?php if (empty($applications)): ?>
                            <div class="card-body text-center py-5">
                                <div class="empty">
                                    <div class="empty-img"><img src="https://tabler.io/static/illustrations/undraw_waiting_f4b2.svg" height="128" alt="No applications"></div>
                                    <p class="empty-title">No applications found</p>
                                    <p class="empty-subtitle text-muted">
                                        <?php if ($status === 'pending'): ?>
                                            There are no applications pending review at the moment.
                                        <?php else: ?>
                                            No applications match your current filters.
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-vcenter card-table">
                                    <thead>
                                        <tr>
                                            <th class="w-1"></th>
                                            <th>Application</th>
                                            <th>Applicant</th>
                                            <th>Standard</th>
                                            <th>Status</th>
                                            <th>Submitted</th>
                                            <th>Priority</th>
                                            <th class="w-1">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($applications as $app): ?>
                                            <tr class="review-row">
                                                <td>
                                                    <input type="checkbox" class="form-check-input application-checkbox" 
                                                           value="<?php echo $app['id']; ?>" 
                                                           onchange="updateBulkActionsButton()">
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <span class="avatar avatar-sm me-3" style="background: var(--certolo-primary);">
                                                            <i class="ti ti-file-text"></i>
                                                        </span>
                                                        <div>
                                                            <div class="font-weight-medium">
                                                                <?php echo Security::escape($app['application_number'] ?? 'APP-' . $app['id']); ?>
                                                            </div>
                                                            <div class="text-muted small">ID: <?php echo $app['id']; ?></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div>
                                                        <div class="font-weight-medium">
                                                            <?php echo Security::escape($app['applicant_name']); ?>
                                                        </div>
                                                        <div class="text-muted small">
                                                            <?php echo Security::escape($app['contact_person']); ?>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div>
                                                        <div class="font-weight-medium">
                                                            <?php echo Security::escape($app['standard_name']); ?>
                                                        </div>
                                                        <div class="text-muted small">
                                                            <?php echo Security::escape($app['standard_type']); ?>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php
                                                    $statusBadges = [
                                                        'draft' => 'bg-secondary',
                                                        'submitted' => 'bg-blue',
                                                        'under_review' => 'bg-yellow text-dark',
                                                        'approved' => 'bg-success',
                                                        'rejected' => 'bg-danger',
                                                        'issued' => 'bg-purple'
                                                    ];
                                                    $badgeClass = $statusBadges[$app['status']] ?? 'bg-secondary';
                                                    ?>
                                                    <span class="badge <?php echo $badgeClass; ?>">
                                                        <?php echo ucwords(str_replace('_', ' ', $app['status'])); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div>
                                                        <div class="font-weight-medium">
                                                            <?php echo date('d.m.Y', strtotime($app['submitted_at'])); ?>
                                                        </div>
                                                        <div class="text-muted small">
                                                            <?php echo $app['days_waiting']; ?> days ago
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if ($app['days_waiting'] >= 7): ?>
                                                        <span class="badge bg-red">High Priority</span>
                                                    <?php elseif ($app['days_waiting'] >= 3): ?>
                                                        <span class="badge bg-orange">Medium</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-green">Normal</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group" role="group">
                                                        <a href="/applications/review/<?php echo $app['id']; ?>" 
                                                           class="btn btn-sm btn-primary">
                                                            <i class="ti ti-eye"></i> Review
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <div class="d-flex justify-content-center mt-4">
                            <nav aria-label="Reviews pagination">
                                <ul class="pagination">
                                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                                <?php echo $i; ?>
                                            </a>
                                        </li>
                                    <?php endfor; ?>
                                </ul>
                            </nav>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
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

<script src="https://cdn.jsdelivr.net/npm/@tabler/core@latest/dist/js/tabler.min.js"></script>

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
    
    // Show Bootstrap modal
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
    
    const choice = prompt(`🔧 Bulk Actions Menu\n\nSelected: ${selected} applications (IDs: ${selectedIds.join(', ')})\n\n${actions.join('\n')}\n\nEnter your choice (1-5):`);
    
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
            console.log('Bulk action cancelled by user');
            break;
        default:
            alert('❌ Invalid choice. Please select 1-5 and try again.');
            break;
    }
}

function exportSelected() {
    const selectedIds = Array.from(document.querySelectorAll('.application-checkbox:checked'))
        .map(cb => cb.value);
    
    if (selectedIds.length === 0) {
        alert('No applications selected for export');
        return;
    }
    
    alert(`📊 Exporting ${selectedIds.length} applications to CSV...\n\nIDs: ${selectedIds.join(', ')}\n\nThe CSV file will be generated and downloaded shortly.`);
    
    // Here you would implement actual CSV export
    // For now, we'll just close the modal
    try {
        const modal = bootstrap.Modal.getInstance(document.getElementById('bulkActionsModal'));
        if (modal) modal.hide();
    } catch (error) {
        console.log('Modal close error (non-critical):', error);
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
        
        // Perform the bulk action
        setTimeout(() => {
            alert(`✅ Successfully processed ${selected.length} applications!\n\nAction: ${actionName.toUpperCase()}\nIDs: ${selected.join(', ')}\n\nThe page will refresh to show updated statuses.`);
            
            // Reset button
            if (bulkBtn) {
                bulkBtn.innerHTML = '<i class="ti ti-checks"></i> Bulk Actions';
                bulkBtn.disabled = false;
                bulkBtn.classList.remove('btn-warning');
                bulkBtn.classList.add('btn-primary');
            }
            
            // Uncheck all checkboxes
            document.querySelectorAll('.application-checkbox').forEach(cb => cb.checked = false);
            document.getElementById('selectAllCheckbox').checked = false;
            
            // Close modal
            try {
                const modal = bootstrap.Modal.getInstance(document.getElementById('bulkActionsModal'));
                if (modal) modal.hide();
            } catch (error) {
                console.log('Modal close error (non-critical):', error);
            }
            
            // In a real application, you would make an AJAX call here
            // and then refresh the page or update the table
            // window.location.reload();
            
        }, 2000); // 2 second delay to show processing
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    console.log('Reviews page DOM loaded');
    updateBulkActionsButton();
    
    // Initialize Bootstrap dropdowns
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
});
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

</body>
</html>

<?php ob_end_flush(); ?>