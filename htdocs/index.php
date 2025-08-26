<?php
/**
 * CERTOLO - Main Entry Point
 * Certification Management System
 * 
 * This file handles all routing and module loading for the CERTOLO application.
 * It processes clean URLs and routes them to appropriate modules.
 * 
 * @author CERTOLO Team
 * @version 1.0
 */

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load configuration files
require_once 'config/constants.php';
require_once 'config/database.php';
require_once 'config/security.php';

// Start session with security settings
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 1); // HTTPS only
session_start();

// Get route from URL
$route = isset($_GET['route']) ? $_GET['route'] : '';
$route = trim($route, '/');

// Parse route into segments
$segments = $route ? explode('/', $route) : [];
$module = !empty($segments[0]) ? $segments[0] : 'home';
$action = !empty($segments[1]) ? $segments[1] : 'index';
$id = !empty($segments[2]) ? $segments[2] : null;

// Store action and id in GET for modules to access
$_GET['action'] = $action;
$_GET['id'] = $id;

// Debug routing (remove in production)
// error_log("CERTOLO Route: module=$module, action=$action, id=$id");

// Check if it's a public page or requires authentication
$publicModules = [
    'home', 
    'login', 
    'register', 
    'forgot-password', 
    'reset-password', 
    'verify-email',
    'about',
    'contact',
    'privacy',
    'terms'
];
$isPublic = in_array($module, $publicModules);

// Check authentication for protected pages
if (!$isPublic && !isset($_SESSION['user_id'])) {
    // Redirect to login with return URL
    $returnUrl = urlencode($_SERVER['REQUEST_URI']);
    header("Location: /login?return=$returnUrl");
    exit;
}

// Security check for session validity
if (isset($_SESSION['user_id'])) {
    // Optional: Check if session is still valid
    // You can add additional security checks here
    
    // Set last activity time
    $_SESSION['last_activity'] = time();
}

// Route to appropriate module
switch ($module) {
    case 'home':
    case '':
        // Landing page
        if (file_exists('modules/home/index.php')) {
            require_once 'modules/home/index.php';
        } else {
            header('HTTP/1.0 404 Not Found');
            echo "Home module not found";
        }
        break;
        
    case 'login':
        if (file_exists('modules/auth/login.php')) {
            require_once 'modules/auth/login.php';
        } else {
            header('HTTP/1.0 404 Not Found');
            echo "Login module not found";
        }
        break;
        
    case 'register':
        if (file_exists('modules/auth/register.php')) {
            require_once 'modules/auth/register.php';
        } else {
            header('HTTP/1.0 404 Not Found');
            echo "Register module not found";
        }
        break;
        
    case 'logout':
        if (file_exists('modules/auth/logout.php')) {
            require_once 'modules/auth/logout.php';
        } else {
            // Simple logout fallback
            session_destroy();
            header('Location: /login');
            exit;
        }
        break;
        
    case 'forgot-password':
        if (file_exists('modules/auth/forgot-password.php')) {
            require_once 'modules/auth/forgot-password.php';
        } else {
            header('HTTP/1.0 404 Not Found');
            echo "Forgot password module not found";
        }
        break;
        
    case 'reset-password':
        if (file_exists('modules/auth/reset-password.php')) {
            require_once 'modules/auth/reset-password.php';
        } else {
            header('HTTP/1.0 404 Not Found');
            echo "Reset password module not found";
        }
        break;
        
    case 'verify-email':
        if (file_exists('modules/auth/verify-email.php')) {
            require_once 'modules/auth/verify-email.php';
        } else {
            header('HTTP/1.0 404 Not Found');
            echo "Email verification module not found";
        }
        break;
        
    case 'dashboard':
        // Check if user is logged in
        if (!isset($_SESSION['user_id'])) {
            header('Location: /login');
            exit;
        }
        
        if (file_exists('modules/dashboard/index.php')) {
            require_once 'modules/dashboard/index.php';
        } else {
            header('HTTP/1.0 404 Not Found');
            echo "Dashboard module not found";
        }
        break;
        
    case 'standards':
        // Check if user is logged in
        if (!isset($_SESSION['user_id'])) {
            header('Location: /login');
            exit;
        }
        
        if (file_exists('modules/standards/index.php')) {
            require_once 'modules/standards/index.php';
        } else {
            header('HTTP/1.0 404 Not Found');
            echo "Standards module not found";
        }
        break;
        
    case 'applications':
        // Check if user is logged in
        if (!isset($_SESSION['user_id'])) {
            header('Location: /login');
            exit;
        }
        
        if (file_exists('modules/applications/index.php')) {
            require_once 'modules/applications/index.php';
        } else {
            header('HTTP/1.0 404 Not Found');
            echo "Applications module not found";
        }
        break;
        
    case 'certificates':
        // Check if user is logged in
        if (!isset($_SESSION['user_id'])) {
            header('Location: /login');
            exit;
        }
        
        if (file_exists('modules/certificates/index.php')) {
            require_once 'modules/certificates/index.php';
        } else {
            header('HTTP/1.0 404 Not Found');
            echo "Certificates module not found";
        }
        break;
        
    case 'customers':
        // Only for certifiers
        if (!isset($_SESSION['user_id'])) {
            header('Location: /login');
            exit;
        }
        
        if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== ROLE_CERTIFIER) {
            header('HTTP/1.0 403 Forbidden');
            if (file_exists('error/403.php')) {
                require_once 'error/403.php';
            } else {
                echo "Access denied. Only certifiers can access this module.";
            }
            exit;
        }
        
        if (file_exists('modules/customers/index.php')) {
            require_once 'modules/customers/index.php';
        } else {
            header('HTTP/1.0 404 Not Found');
            echo "Customers module not found. Please create modules/customers/index.php";
        }
        break;
        
    case 'reviews':
        // Only for certifiers
        if (!isset($_SESSION['user_id'])) {
            header('Location: /login');
            exit;
        }
        
        if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== ROLE_CERTIFIER) {
            header('HTTP/1.0 403 Forbidden');
            if (file_exists('error/403.php')) {
                require_once 'error/403.php';
            } else {
                echo "Access denied. Only certifiers can access the reviews module.";
            }
            exit;
        }
        
        if (file_exists('modules/reviews/index.php')) {
            require_once 'modules/reviews/index.php';
        } else {
            header('HTTP/1.0 404 Not Found');
            echo "Reviews module not found. Please create modules/reviews/index.php";
        }
        break;
        
    case 'profile':
        // Check if user is logged in
        if (!isset($_SESSION['user_id'])) {
            header('Location: /login');
            exit;
        }
        
        if (file_exists('modules/profile/index.php')) {
            require_once 'modules/profile/index.php';
        } else {
            header('HTTP/1.0 404 Not Found');
            echo "Profile module not found";
        }
        break;
        
    case 'admin':
        // Check if user is logged in
        if (!isset($_SESSION['user_id'])) {
            header('Location: /login');
            exit;
        }
        
        // Check if user is admin (implement admin check)
        if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
            header('HTTP/1.0 403 Forbidden');
            if (file_exists('error/403.php')) {
                require_once 'error/403.php';
            } else {
                echo "Access denied. Admin privileges required.";
            }
            exit;
        }
        
        if (file_exists('modules/admin/index.php')) {
            require_once 'modules/admin/index.php';
        } else {
            header('HTTP/1.0 404 Not Found');
            echo "Admin module not found";
        }
        break;
        
    case 'api':
        // API endpoints (optional)
        if (file_exists('api/index.php')) {
            require_once 'api/index.php';
        } else {
            header('HTTP/1.0 404 Not Found');
            header('Content-Type: application/json');
            echo json_encode(['error' => 'API not implemented']);
        }
        break;
        
    case 'about':
        // Static about page
        if (file_exists('modules/pages/about.php')) {
            require_once 'modules/pages/about.php';
        } else {
            // Simple about page fallback
            echo "<!DOCTYPE html><html><head><title>About - CERTOLO</title></head><body>";
            echo "<h1>About CERTOLO</h1>";
            echo "<p>CERTOLO is a comprehensive certification management system.</p>";
            echo "<a href='/'>Back to Home</a>";
            echo "</body></html>";
        }
        break;
        
    case 'contact':
        // Static contact page
        if (file_exists('modules/pages/contact.php')) {
            require_once 'modules/pages/contact.php';
        } else {
            // Simple contact page fallback
            echo "<!DOCTYPE html><html><head><title>Contact - CERTOLO</title></head><body>";
            echo "<h1>Contact Us</h1>";
            echo "<p>Email: support@certit.ee</p>";
            echo "<a href='/'>Back to Home</a>";
            echo "</body></html>";
        }
        break;
        
    case 'privacy':
        // Privacy policy page
        if (file_exists('modules/pages/privacy.php')) {
            require_once 'modules/pages/privacy.php';
        } else {
            echo "<!DOCTYPE html><html><head><title>Privacy Policy - CERTOLO</title></head><body>";
            echo "<h1>Privacy Policy</h1>";
            echo "<p>Privacy policy content goes here.</p>";
            echo "<a href='/'>Back to Home</a>";
            echo "</body></html>";
        }
        break;
        
    case 'terms':
        // Terms of service page
        if (file_exists('modules/pages/terms.php')) {
            require_once 'modules/pages/terms.php';
        } else {
            echo "<!DOCTYPE html><html><head><title>Terms of Service - CERTOLO</title></head><body>";
            echo "<h1>Terms of Service</h1>";
            echo "<p>Terms of service content goes here.</p>";
            echo "<a href='/'>Back to Home</a>";
            echo "</body></html>";
        }
        break;
        
    case 'health':
        // Health check endpoint for monitoring
        header('Content-Type: application/json');
        try {
            // Check database connection
            require_once 'config/database.php';
            $db = Database::getInstance();
            $stmt = $db->query("SELECT 1");
            
            echo json_encode([
                'status' => 'healthy',
                'timestamp' => date('Y-m-d H:i:s'),
                'database' => 'connected',
                'version' => '1.0'
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'status' => 'unhealthy',
                'timestamp' => date('Y-m-d H:i:s'),
                'error' => $e->getMessage()
            ]);
        }
        break;
        
    default:
        // 404 Not Found
        header('HTTP/1.0 404 Not Found');
        
        if (file_exists('error/404.php')) {
            require_once 'error/404.php';
        } else {
            // Simple 404 page fallback
            echo "<!DOCTYPE html>";
            echo "<html><head><title>Page Not Found - CERTOLO</title></head><body>";
            echo "<div style='text-align: center; margin-top: 100px; font-family: Arial;'>";
            echo "<h1>404 - Page Not Found</h1>";
            echo "<p>The page you are looking for does not exist.</p>";
            echo "<p>Module: <strong>" . htmlspecialchars($module) . "</strong></p>";
            echo "<a href='/' style='color: #2e7d32;'>← Back to Home</a>";
            echo "</div>";
            echo "</body></html>";
        }
        break;
}

// Optional: Log route access for analytics
if (function_exists('error_log')) {
    $logData = [
        'timestamp' => date('Y-m-d H:i:s'),
        'module' => $module,
        'action' => $action,
        'user_id' => $_SESSION['user_id'] ?? 'guest',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ];
    error_log('CERTOLO Access: ' . json_encode($logData));
}
?>