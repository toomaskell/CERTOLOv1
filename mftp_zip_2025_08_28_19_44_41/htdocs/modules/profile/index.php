<?php
/**
 * CERTOLO - Profile Module
 * User profile management and settings
 */

// Check authentication
if (!isset($_SESSION['user_id'])) {
    header('Location: /login');
    exit;
}

// Get user data
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['user_role'];

// Get action from URL (profile or settings)
$action = $_GET['action'] ?? 'index';

try {
    $db = Database::getInstance();
    
    // Get current user data
    $stmt = $db->query(
        "SELECT * FROM users WHERE id = :user_id",
        ['user_id' => $userId]
    );
    $userData = $stmt->fetch();
    
    if (!$userData) {
        throw new Exception('User not found');
    }
    
} catch (Exception $e) {
    error_log('Profile module error: ' . $e->getMessage());
    $_SESSION['error_message'] = 'Unable to load profile data. Please try again.';
    header('Location: /dashboard');
    exit;
}

// Handle different actions
switch ($action) {
    case 'settings':
        handleSettings();
        break;
    default:
        showProfile();
        break;
}

function showProfile() {
    global $userData, $userRole;
    
    // Set page title
    $pageTitle = 'My Profile';
    
    // Include header
    include INCLUDES_PATH . 'header.php';
    ?>
    
    <div class="page-header d-print-none">
        <div class="container-xl">
            <div class="row g-2 align-items-center">
                <div class="col">
                    <div class="page-pretitle">Account</div>
                    <h2 class="page-title">
                        <i class="ti ti-user me-2"></i>
                        My Profile
                    </h2>
                </div>
                <div class="col-auto ms-auto d-print-none">
                    <div class="btn-list">
                        <a href="/profile/settings" class="btn btn-primary">
                            <i class="ti ti-settings"></i> Edit Profile & Settings
                        </a>
                        <a href="/dashboard" class="btn">
                            <i class="ti ti-arrow-left"></i> Back to Dashboard
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="page-body">
        <div class="container-xl">
            <div class="row">
                <!-- Profile Summary -->
                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-body text-center">
                            <div class="avatar avatar-xl mb-3" style="background-color: var(--certolo-medium); color: white;">
                                <?php echo strtoupper(substr($userData['contact_person'], 0, 2)); ?>
                            </div>
                            <h3 class="m-0 mb-1"><?php echo Security::escape($userData['contact_person']); ?></h3>
                            <div class="text-muted"><?php echo ucfirst($userData['role']); ?></div>
                            <div class="text-muted mt-1"><?php echo Security::escape($userData['company_name']); ?></div>
                            
                            <div class="mt-3">
                                <span class="badge <?php echo $userData['status'] === 'active' ? 'bg-success' : 'bg-secondary'; ?>">
                                    <?php echo ucfirst($userData['status']); ?>
                                </span>
                                <?php if ($userData['email_verified_at']): ?>
                                    <span class="badge bg-blue">Email Verified</span>
                                <?php else: ?>
                                    <span class="badge bg-orange">Email Not Verified</span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="mt-3 text-muted small">
                                <div><strong>Member since:</strong></div>
                                <div><?php echo date('F j, Y', strtotime($userData['created_at'])); ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Quick Stats -->
                    <div class="card mt-3">
                        <div class="card-header">
                            <h3 class="card-title">Quick Stats</h3>
                        </div>
                        <div class="card-body">
                            <?php
                            // Get some basic stats
                            try {
                                if ($userRole === ROLE_APPLICANT) {
                                    $stmt = $db->query(
                                        "SELECT 
                                            COUNT(*) as total_applications,
                                            COUNT(CASE WHEN status = 'issued' THEN 1 END) as certificates
                                         FROM applications WHERE applicant_id = :user_id",
                                        ['user_id' => $userId]
                                    );
                                    $stats = $stmt->fetch();
                                    ?>
                                    <div class="row">
                                        <div class="col-6">
                                            <div class="text-center">
                                                <div class="h2 m-0"><?php echo $stats['total_applications']; ?></div>
                                                <div class="text-muted">Applications</div>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="text-center">
                                                <div class="h2 m-0"><?php echo $stats['certificates']; ?></div>
                                                <div class="text-muted">Certificates</div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php
                                } else {
                                    $stmt = $db->query(
                                        "SELECT 
                                            COUNT(DISTINCT s.id) as standards,
                                            COUNT(DISTINCT a.id) as applications
                                         FROM standards s
                                         LEFT JOIN applications a ON s.id = a.standard_id
                                         WHERE s.certifier_id = :user_id",
                                        ['user_id' => $userId]
                                    );
                                    $stats = $stmt->fetch();
                                    ?>
                                    <div class="row">
                                        <div class="col-6">
                                            <div class="text-center">
                                                <div class="h2 m-0"><?php echo $stats['standards']; ?></div>
                                                <div class="text-muted">Standards</div>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="text-center">
                                                <div class="h2 m-0"><?php echo $stats['applications']; ?></div>
                                                <div class="text-muted">Applications</div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php
                                }
                            } catch (Exception $e) {
                                echo '<div class="text-muted">Stats unavailable</div>';
                            }
                            ?>
                        </div>
                    </div>
                </div>
                
                <!-- Profile Information (Read-only) -->
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Profile Information</h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3"><strong>Contact Person:</strong></div>
                                <div class="col-md-9"><?php echo Security::escape($userData['contact_person']); ?></div>
                            </div>
                            <hr>
                            <div class="row">
                                <div class="col-md-3"><strong>Email:</strong></div>
                                <div class="col-md-9">
                                    <?php echo Security::escape($userData['email']); ?>
                                    <?php if (!$userData['email_verified_at']): ?>
                                        <span class="badge bg-warning ms-2">Not Verified</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <hr>
                            <div class="row">
                                <div class="col-md-3"><strong>Company:</strong></div>
                                <div class="col-md-9"><?php echo Security::escape($userData['company_name']); ?></div>
                            </div>
                            <hr>
                            <div class="row">
                                <div class="col-md-3"><strong>Phone:</strong></div>
                                <div class="col-md-9"><?php echo Security::escape($userData['phone']) ?: '<em class="text-muted">Not provided</em>'; ?></div>
                            </div>
                            <hr>
                            <div class="row">
                                <div class="col-md-3"><strong>Address:</strong></div>
                                <div class="col-md-9"><?php echo Security::escape($userData['address']) ?: '<em class="text-muted">Not provided</em>'; ?></div>
                            </div>
                            <hr>
                            <div class="row">
                                <div class="col-md-3"><strong>City:</strong></div>
                                <div class="col-md-9"><?php echo Security::escape($userData['city']) ?: '<em class="text-muted">Not provided</em>'; ?></div>
                            </div>
                            <hr>
                            <div class="row">
                                <div class="col-md-3"><strong>Country:</strong></div>
                                <div class="col-md-9"><?php echo Security::escape($userData['country']) ?: '<em class="text-muted">Not provided</em>'; ?></div>
                            </div>
                            <hr>
                            <div class="row">
                                <div class="col-md-3"><strong>Account Type:</strong></div>
                                <div class="col-md-9">
                                    <span class="badge bg-primary"><?php echo ucfirst($userData['role']); ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer text-end">
                            <a href="/profile/settings" class="btn btn-primary">
                                <i class="ti ti-edit"></i> Edit Information
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php
    // Include footer
    include INCLUDES_PATH . 'footer.php';
}

function handleSettings() {
    global $userData, $userRole;
    
    $errors = [];
    $success = '';
    
    // Handle profile update
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
        $errors = updateProfile();
        if (empty($errors)) {
            $success = 'Profile information updated successfully!';
            // Refresh user data
            global $db, $userId;
            $stmt = $db->query("SELECT * FROM users WHERE id = :user_id", ['user_id' => $userId]);
            $userData = $stmt->fetch();
        }
    }
    
    // Handle password change
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
        $errors = changePassword();
        if (empty($errors)) {
            $success = 'Password changed successfully!';
        }
    }
    
    // Set page title
    $pageTitle = 'Account Settings';
    
    // Include header
    include INCLUDES_PATH . 'header.php';
    ?>
    
    <div class="page-header d-print-none">
        <div class="container-xl">
            <div class="row g-2 align-items-center">
                <div class="col">
                    <div class="page-pretitle">Account</div>
                    <h2 class="page-title">
                        <i class="ti ti-settings me-2"></i>
                        Account Settings
                    </h2>
                </div>
                <div class="col-auto ms-auto d-print-none">
                    <div class="btn-list">
                        <a href="/profile" class="btn">
                            <i class="ti ti-user"></i> View Profile
                        </a>
                        <a href="/dashboard" class="btn btn-primary">
                            <i class="ti ti-arrow-left"></i> Back to Dashboard
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="page-body">
        <div class="container-xl">
            
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo Security::escape($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="ti ti-check me-2"></i>
                    <?php echo Security::escape($success); ?>
                </div>
            <?php endif; ?>
            
            <div class="row">
                <!-- Personal & Company Information -->
                <div class="col-lg-8">
                    <form method="POST" action="/profile/settings">
                        <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                        <input type="hidden" name="update_profile" value="1">
                        
                        <!-- Personal Information -->
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="ti ti-user me-2"></i>
                                    Personal Information
                                </h3>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label required">Contact Person / Full Name</label>
                                            <input type="text" class="form-control" name="contact_person" 
                                                   value="<?php echo Security::escape($userData['contact_person']); ?>" required>
                                            <small class="form-hint">Your full name as the main contact</small>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label required">Email Address</label>
                                            <input type="email" class="form-control" name="email" 
                                                   value="<?php echo Security::escape($userData['email']); ?>" required>
                                            <?php if (!$userData['email_verified_at']): ?>
                                                <small class="form-hint text-warning">
                                                    <i class="ti ti-alert-triangle"></i> Email not verified
                                                </small>
                                            <?php else: ?>
                                                <small class="form-hint text-success">
                                                    <i class="ti ti-check"></i> Email verified
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Phone Number</label>
                                            <input type="text" class="form-control" name="phone" 
                                                   value="<?php echo Security::escape($userData['phone']); ?>"
                                                   placeholder="+372 555 1234">
                                            <small class="form-hint">Include country code for international numbers</small>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Account Type</label>
                                            <input type="text" class="form-control" 
                                                   value="<?php echo ucfirst($userData['role']); ?>" disabled>
                                            <small class="form-hint">Account type cannot be changed</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Company Information -->
                        <div class="card mt-3">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="ti ti-building me-2"></i>
                                    Company Information
                                </h3>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-12">
                                        <div class="mb-3">
                                            <label class="form-label required">Company / Organization Name</label>
                                            <input type="text" class="form-control" name="company_name" 
                                                   value="<?php echo Security::escape($userData['company_name']); ?>" required>
                                            <small class="form-hint">Official registered company name</small>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Business Address</label>
                                    <textarea class="form-control" name="address" rows="3" 
                                              placeholder="Street address, building, suite/unit"><?php echo Security::escape($userData['address']); ?></textarea>
                                    <small class="form-hint">Complete business address including street, building, suite/unit</small>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">City</label>
                                            <input type="text" class="form-control" name="city" 
                                                   value="<?php echo Security::escape($userData['city']); ?>"
                                                   placeholder="Tallinn">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Country</label>
                                            <input type="text" class="form-control" name="country" 
                                                   value="<?php echo Security::escape($userData['country']); ?>"
                                                   placeholder="Estonia">
                                        </div>
                                    </div>
                                </div>
                                
                                <?php if ($userRole === ROLE_CERTIFIER): ?>
                                <hr>
                                <div class="mb-3">
                                    <label class="form-label">Certification Capabilities</label>
                                    <textarea class="form-control" name="certification_capabilities" rows="3"
                                              placeholder="Describe your certification expertise, standards you can certify, industry focus areas..."><?php 
                                        if ($userData['certification_capabilities']) {
                                            $capabilities = json_decode($userData['certification_capabilities'], true);
                                            echo Security::escape(is_array($capabilities) ? implode("\n", $capabilities) : $userData['certification_capabilities']);
                                        }
                                    ?></textarea>
                                    <small class="form-hint">Describe your certification expertise and capabilities (for certifiers only)</small>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Submit Button -->
                        <div class="card mt-3">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <p class="text-muted mb-0">
                                            <i class="ti ti-info-circle me-1"></i>
                                            Last updated: <?php echo date('d.m.Y H:i', strtotime($userData['updated_at'])); ?>
                                        </p>
                                    </div>
                                    <div class="col-md-6 text-end">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="ti ti-device-floppy"></i> Save All Changes
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                
                <!-- Password & Security -->
                <div class="col-lg-4">
                    <!-- Change Password -->
                    <form method="POST" action="/profile/settings">
                        <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                        <input type="hidden" name="change_password" value="1">
                        
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="ti ti-key me-2"></i>
                                    Change Password
                                </h3>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label required">Current Password</label>
                                    <input type="password" class="form-control" name="current_password" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label required">New Password</label>
                                    <input type="password" class="form-control" name="new_password" required>
                                    <small class="form-hint">
                                        Must contain:
                                        <br>• At least 8 characters
                                        <br>• Uppercase & lowercase letters  
                                        <br>• Numbers & special characters
                                    </small>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label required">Confirm New Password</label>
                                    <input type="password" class="form-control" name="confirm_password" required>
                                </div>
                            </div>
                            <div class="card-footer text-end">
                                <button type="submit" class="btn btn-warning">
                                    <i class="ti ti-key"></i> Change Password
                                </button>
                            </div>
                        </div>
                    </form>
                    
                    <!-- Account Status -->
                    <div class="card mt-3">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="ti ti-shield-check me-2"></i>
                                Account Status
                            </h3>
                        </div>
                        <div class="card-body">
                            <div class="row mb-2">
                                <div class="col-6"><strong>Status:</strong></div>
                                <div class="col-6">
                                    <span class="badge <?php echo $userData['status'] === 'active' ? 'bg-success' : 'bg-secondary'; ?>">
                                        <?php echo ucfirst($userData['status']); ?>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="row mb-2">
                                <div class="col-6"><strong>Email:</strong></div>
                                <div class="col-6">
                                    <?php if ($userData['email_verified_at']): ?>
                                        <span class="badge bg-success">Verified</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning">Unverified</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="row mb-2">
                                <div class="col-6"><strong>Created:</strong></div>
                                <div class="col-6 text-muted small">
                                    <?php echo date('d.m.Y', strtotime($userData['created_at'])); ?>
                                </div>
                            </div>
                            
                            <?php if (!$userData['email_verified_at']): ?>
                            <hr>
                            <div class="text-center">
                                <a href="/verify-email/resend" class="btn btn-sm btn-outline-primary">
                                    <i class="ti ti-mail"></i> Resend Verification Email
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Danger Zone -->
                    <div class="card mt-3 border-danger">
                        <div class="card-header border-danger">
                            <h3 class="card-title text-danger">
                                <i class="ti ti-alert-triangle me-2"></i>
                                Danger Zone
                            </h3>
                        </div>
                        <div class="card-body">
                            <p class="text-muted small">
                                Account deactivation will disable your access but preserve your data. 
                                Contact support to reactivate your account.
                            </p>
                            <button class="btn btn-outline-danger btn-sm" disabled>
                                <i class="ti ti-user-x"></i> Deactivate Account
                            </button>
                            <br><small class="text-muted">Contact support for account deactivation</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php
    // Include footer
    include INCLUDES_PATH . 'footer.php';
}

function updateProfile() {
    global $db, $userId, $userData;
    
    $errors = [];
    
    // Verify CSRF token
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token. Please refresh and try again.';
        return $errors;
    }
    
    // Collect and validate form data
    $formData = [
        'contact_person' => trim($_POST['contact_person'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'company_name' => trim($_POST['company_name'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
        'address' => trim($_POST['address'] ?? ''),
        'city' => trim($_POST['city'] ?? ''),
        'country' => trim($_POST['country'] ?? '')
    ];
    
    // Handle certification capabilities for certifiers
    if ($_SESSION['user_role'] === ROLE_CERTIFIER) {
        $capabilities = trim($_POST['certification_capabilities'] ?? '');
        $formData['certification_capabilities'] = $capabilities ? json_encode(explode("\n", $capabilities)) : null;
    }
    
    // Validate required fields
    if (empty($formData['contact_person'])) {
        $errors[] = 'Contact person / full name is required';
    }
    
    if (empty($formData['email'])) {
        $errors[] = 'Email address is required';
    } elseif (!Security::validateEmail($formData['email'])) {
        $errors[] = 'Please enter a valid email address';
    }
    
    if (empty($formData['company_name'])) {
        $errors[] = 'Company / organization name is required';
    }
    
    // Check if email is already used by another user
    if ($formData['email'] !== $userData['email']) {
        $stmt = $db->query(
            "SELECT id FROM users WHERE email = :email AND id != :user_id",
            ['email' => $formData['email'], 'user_id' => $userId]
        );
        if ($stmt->fetch()) {
            $errors[] = 'Email address is already in use by another account';
        }
    }
    
    // Update profile if no errors
    if (empty($errors)) {
        try {
            $updateFields = [
                'contact_person = :contact_person',
                'email = :email',
                'company_name = :company_name',
                'phone = :phone',
                'address = :address',
                'city = :city',
                'country = :country',
                'updated_at = NOW()'
            ];
            
            $updateParams = [
                'contact_person' => $formData['contact_person'],
                'email' => $formData['email'],
                'company_name' => $formData['company_name'],
                'phone' => $formData['phone'],
                'address' => $formData['address'],
                'city' => $formData['city'],
                'country' => $formData['country'],
                'user_id' => $userId
            ];
            
            // Add certification capabilities for certifiers
            if (isset($formData['certification_capabilities'])) {
                $updateFields[] = 'certification_capabilities = :certification_capabilities';
                $updateParams['certification_capabilities'] = $formData['certification_capabilities'];
            }
            
            $sql = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = :user_id";
            $db->query($sql, $updateParams);
            
            // Update session data
            $_SESSION['user_name'] = $formData['contact_person'];
            $_SESSION['company_name'] = $formData['company_name'];
            
            // If email changed, mark as unverified
            if ($formData['email'] !== $userData['email']) {
                $db->query(
                    "UPDATE users SET email_verified_at = NULL WHERE id = :user_id",
                    ['user_id' => $userId]
                );
            }
            
        } catch (Exception $e) {
            error_log('Profile update error: ' . $e->getMessage());
            $errors[] = 'Failed to update profile information. Please try again.';
        }
    }
    
    return $errors;
}

function changePassword() {
    global $db, $userId, $userData;
    
    $errors = [];
    
    // Verify CSRF token
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token. Please refresh and try again.';
        return $errors;
    }
    
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    // Validate current password
    if (empty($currentPassword)) {
        $errors[] = 'Current password is required';
    } elseif (!Security::verifyPassword($currentPassword, $userData['password'])) {
        $errors[] = 'Current password is incorrect';
    }
    
    // Validate new password
    if (empty($newPassword)) {
        $errors[] = 'New password is required';
    } else {
        $passwordErrors = Security::validatePasswordStrength($newPassword);
        $errors = array_merge($errors, $passwordErrors);
    }
    
    // Confirm password match
    if ($newPassword !== $confirmPassword) {
        $errors[] = 'Password confirmation does not match';
    }
    
    // Change password if no errors
    if (empty($errors)) {
        try {
            $hashedPassword = Security::hashPassword($newPassword);
            
            $db->query(
                "UPDATE users SET password = :password, updated_at = NOW() WHERE id = :user_id",
                ['password' => $hashedPassword, 'user_id' => $userId]
            );
            
        } catch (Exception $e) {
            error_log('Password change error: ' . $e->getMessage());
            $errors[] = 'Failed to change password. Please try again.';
        }
    }
    
    return $errors;
}
?>