<?php

include_once 'config/config.php';

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

if($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $password = $_POST['password'];

    if ($email === '' || $password === '') {
        http_response_code(422);
        echo 'Please enter both email and password.';
        exit();
    }

    // 查询用户及其关联的角色名称
    $sql = "SELECT * 
            FROM Users u 
            JOIN Roles r ON u.RoleId = r.RoleId 
            WHERE u.Email = :email LIMIT 1";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':email', $email);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if($user){
        if($user['IsActive'] == 0){
            http_response_code(403);
            echo 'Your account is inactive. Please contact support.';
            exit();
        }
        if(password_verify($password, $user['PasswordHash'])){
            // 登录成功，设置 Session
            $_SESSION['user_id'] = $user['UserId'];
            $_SESSION['role'] = $user['RoleName'];

            $profileStmt = $pdo->prepare(
                "SELECT FirstName, ProfilePhotoUrl, UpdateDate
                 FROM UserProfile
                 WHERE UserId = ?
                 LIMIT 1"
            );
            $profileStmt->execute([$user['UserId']]);
            $profile = $profileStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $_SESSION['first_name'] = $profile['FirstName'] ?? 'Member';
            $_SESSION['profile_photo_url'] = $profile['ProfilePhotoUrl'] ?? '';
            $_SESSION['profile_photo_version'] = !empty($profile['UpdateDate'])
                ? strtotime((string) $profile['UpdateDate'])
                : time();

            header("Location: index.php");
            exit();
        } else {
            http_response_code(401);
            echo 'Invalid email or password.';
            exit();
        }
    }else {
            http_response_code(401);
            echo 'User does not exist.';
            exit();
        }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-commerce || Member Login </title>
</head>
<body>
    <h2>Member Login</h2>
    <form action="member_login.php" method="post">
        <div>
            <label for="email">Email:</label>
            <input type="email" id="email" name="email" required>
        </div>
        <br>
        <div>
            <label for="password">Password:</label>
            <input type="password" id="password" name="password" required>
        </div>
        <br>
        <button type="submit">Login</button>
        <a href="forget_password.php">Forgot Password?</a>
    </form>
</body>
</html>