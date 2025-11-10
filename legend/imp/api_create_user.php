<?php
/**
 * API User Creation Script
 * 
 * Run this script to create API users
 * Usage: php api_create_user.php
 */

require_once 'RestAPI.php';

echo "=== API User Creation ===\n\n";

// Get username
echo "Enter username: ";
$username = trim(fgets(STDIN));

if (empty($username)) {
    die("Error: Username cannot be empty\n");
}

// Get password
echo "Enter password: ";
$password = trim(fgets(STDIN));

if (empty($password)) {
    die("Error: Password cannot be empty\n");
}

// Confirm password
echo "Confirm password: ";
$confirmPassword = trim(fgets(STDIN));

if ($password !== $confirmPassword) {
    die("Error: Passwords do not match\n");
}

// Get email (optional)
echo "Enter email (optional): ";
$email = trim(fgets(STDIN));

// Get role
echo "Enter role (user/admin) [user]: ";
$role = trim(fgets(STDIN));
if (empty($role)) {
    $role = 'user';
}

if (!in_array($role, ['user', 'admin'])) {
    die("Error: Role must be 'user' or 'admin'\n");
}

// Create user
try {
    $db = new PDO('sqlite:api_users.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $apiKey = bin2hex(random_bytes(32));
    
    $stmt = $db->prepare("
        INSERT INTO api_users (username, password_hash, api_key, email, role)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$username, $passwordHash, $apiKey, $email, $role]);
    
    echo "\n✅ User created successfully!\n\n";
    echo "Username: $username\n";
    echo "Role: $role\n";
    echo "API Key: $apiKey\n";
    echo "\nKeep your API key safe!\n";
    
} catch (PDOException $e) {
    die("Error: " . $e->getMessage() . "\n");
}
