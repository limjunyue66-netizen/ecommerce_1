<?php 

include_once 'config/config.php';

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $email = trim((string)($_POST['email'] ?? ''));
    $password = $_POST['pass'];
    $confirmPassword = $_POST['confirm_pass'];
    $firstName = trim((string)($_POST['first_name'] ?? ''));
    $lastName = trim((string)($_POST['last_name'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));

    $targetDir = __DIR__ . "/uploads/avatars/";
    $publicAvatarDir = public_url('uploads/avatars/');
    $defaultAvatar = public_url('asset/image/default_avatar.svg');
    $profilePhotoUrl = $defaultAvatar;

    if(isset($_FILES['avatar']) && $_FILES['avatar']['error']=== UPLOAD_ERR_OK){
        $fileTmpPath = $_FILES['avatar']['tmp_name'];
        $fileName = $_FILES['avatar']['name'];
        $fileExtension = strtolower(pathinfo($fileName,PATHINFO_EXTENSION));

        $allowedExt = ['jpg','jpeg','png','gif'];
        if(in_array($fileExtension, $allowedExt)){
            //Use UUID to generate a unique file name to avoid conflicts
            $newFileName = vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex(random_bytes(16)),4)) . '.' . $fileExtension;
            $destPath = $targetDir . $newFileName;

            if(move_uploaded_file($fileTmpPath, $destPath)){
                $profilePhotoUrl = $publicAvatarDir . $newFileName;
            } else {
                echo json_encode(["error" => "Failed to upload avatar."]);
                exit();
            }
        }
    }

    try{
        if($password !== $confirmPassword){
            echo json_encode(["error" => "Passwords do not match."]);
            exit();
        }

        $pdo->beginTransaction();

        $userId = vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex(random_bytes(16)),4));

        $passwordHash = password_hash($confirmPassword,PASSWORD_DEFAULT);

        $roleStmt = $pdo->prepare("SELECT RoleId FROM Roles WHERE `RoleName` =  'Member' LIMIT 1");
        $roleStmt->execute();
        $role = $roleStmt->fetchColumn();

        $insUser = $pdo->prepare("INSERT INTO Users (`UserId`,`RoleId`,`Email`,`PasswordHash`,`CreatedDate`)VALUES (?,?,?,?,?)");
        $insUser->execute([$userId, $role, $email, $passwordHash, date('Y-m-d H:i:s')]);

        $profileId = vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex(random_bytes(16)),4));
        $insProfile = $pdo->prepare("INSERT INTO UserProfile (`ProfileId`,`UserId`,`FirstName`,`LastName`,`PhoneNumber`,`ProfilePhotoUrl`,`CreateDate`)VALUES (?,?,?,?,?,?,?)");
        $insProfile->execute([$profileId, $userId, $firstName, $lastName, $phone, $profilePhotoUrl, date('Y-m-d H:i:s')]);

        $pdo->commit();
        echo json_encode(["message" => "Registration successful. You can now log in."]);
        exit();
    }catch(Exception $e){
        $pdo->rollBack();
        echo json_encode(["error" => "An error occurred during registration. Please try again."]);
        exit();
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-Commerce || Register</title>
</head>
<body>
    <h2>Create New Account</h2>
    <div>
        <form action="member_register.php" method="post" enctype="multipart/form-data">
            <div>
                <input type="email" placeholder="Enter your email" name="email" id="email" required>
            </div>
            <div>
                <input type="password" placeholder="Enter your password" name="pass" id="pass" required>
            </div>
            <div>
                <input type="password" placeholder="Confirm your password" name="confirm_pass" id="confirm_pass" required>
            </div>
            <hr>
            <div>
                <input type="text" placeholder="Enter your First Name" name="first_name" id="first_name" required><br>
                <input type="text" placeholder="Enter your Last Name" name="last_name" id="last_name" required><br>
                <input type="text" placeholder="Enter your Phone Number" name="phone" id="phone" required><br>
                <input type="file" name="avatar" id="avatar" accept="image/*"><br>
                <br>
            </div>
            <div>
                <button type="submit" name="register">Register</button>
            </div>
        </form>
    </div>
</body>
</html>