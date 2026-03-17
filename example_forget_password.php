<?php
// Fix include path without changing folder structure
$configPath = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php');
if (!$configPath) {
    die('Error: config.php not found. Please check the config folder.');
}
include_once $configPath;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/vendor/autoload.php';

if ($_SERVER['REQUEST_METHOD'] === "POST") {
    $email = trim($_POST['email']);

    $sql = "SELECT * FROM Users u 
            JOIN Roles r ON u.RoleId = r.RoleId 
            WHERE u.Email = :email 
            AND r.RoleName = 'Member' LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if ($user) {
        $token = bin2hex(random_bytes(16));
        $expiry_time = date("Y-m-d H:i:s", strtotime("+5 minutes"));

        $update_sql = "UPDATE Users SET ResetToken = :token,
                       ResetTokenExpiry = :expiry WHERE Email = :email";
        $update = $pdo->prepare($update_sql);
        $update->execute([
            ':token' => $token,
            ':expiry' => $expiry_time,
            ':email' => $user['Email']
        ]);

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'limjunyue66@gmail.com';
            $mail->Password = 'wgprzehwupecxnjl';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;

            $mail->setFrom('limjunyue66@gmail.com','E-commerce');
            $mail->addAddress($email);

            $resetLink = $base_url . "/reset_password.php?token=" . $token;

            $mail->isHTML(true);
            $mail->Subject = 'Password Reset Request';
            $mail->Body = "Click the link to reset your password: <a href='$resetLink'>$resetLink</a><br>The link will expire in 5 minutes.";
            $mail->AltBody = "The link will expire in 5 minutes: $resetLink";

            $mail->send();
            echo json_encode(["message" => "Password reset link has been sent to your email."]);

        } catch (Exception $e) {
            echo json_encode(["message" => "Failed to send email. Please try again later."]);
        }
    } else {
        http_response_code(404);
        echo json_encode(["message" => "Email not found."]);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-commerce || Forget Password</title>
</head>
<body>
    <h1>Forget Password</h1>
    <form action="example_forget_password.php" method="post">
        <label for="email">Enter your registered email:</label>
        <input type="email" id="email" name="email" required>
        <br><br>
        <button type="submit">Send Reset Link</button>
    </form>
</body>
</html>