<?php

require_once 'config/config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: member_login.php");
    exit();
}

$userId = $_SESSION['user_id'];

function hasAddressColumn(PDO $pdo, string $columnName): bool
{
    static $columnCache = [];

    if (array_key_exists($columnName, $columnCache)) {
        return $columnCache[$columnName];
    }

    $stmt = $pdo->prepare(
        "SELECT 1
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'Addresses'
           AND COLUMN_NAME = ?
         LIMIT 1"
    );
    $stmt->execute([$columnName]);
    $columnCache[$columnName] = (bool) $stmt->fetchColumn();

    return $columnCache[$columnName];
}

function supportsDetailedAddressColumns(PDO $pdo): bool
{
    static $supportsDetailed = null;

    if ($supportsDetailed !== null) {
        return $supportsDetailed;
    }

    $requiredColumns = ['AddressLine1', 'AddressLine2', 'States', 'City', 'Postcode'];
    foreach ($requiredColumns as $columnName) {
        if (!hasAddressColumn($pdo, $columnName)) {
            $supportsDetailed = false;
            return $supportsDetailed;
        }
    }

    $supportsDetailed = true;
    return $supportsDetailed;
}

// ─── AJAX POST handlers ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    // 1. Update Profile
    if ($action === 'update_profile') {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName  = trim($_POST['last_name']  ?? '');
        $phone     = trim($_POST['phone']      ?? '');

        $photoUrl = null;
        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $fileExt    = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
            $allowedExt = ['jpg', 'jpeg', 'png', 'gif'];
            if (!in_array($fileExt, $allowedExt)) {
                echo json_encode(['error' => 'Invalid file type.']);
                exit();
            }
            $newName  = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4)) . '.' . $fileExt;
            $destPath = __DIR__ . '/uploads/avatars/' . $newName;
            if (!move_uploaded_file($_FILES['avatar']['tmp_name'], $destPath)) {
                echo json_encode(['error' => 'Failed to upload avatar.']);
                exit();
            }
            $photoUrl = public_url('uploads/avatars/' . $newName);
        }

        try {
            $profileId = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
            $stmt = $pdo->prepare(
                "INSERT INTO UserProfile (ProfileId, UserId, FirstName, LastName, PhoneNumber, ProfilePhotoUrl, CreateDate, UpdateDate)
                 VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                    FirstName = VALUES(FirstName),
                    LastName = VALUES(LastName),
                    PhoneNumber = VALUES(PhoneNumber),
                    ProfilePhotoUrl = IFNULL(VALUES(ProfilePhotoUrl), ProfilePhotoUrl),
                    UpdateDate = NOW()"
            );
            $stmt->execute([$profileId, $userId, $firstName, $lastName, $phone, $photoUrl]);

            $freshProfileStmt = $pdo->prepare("SELECT FirstName, LastName, ProfilePhotoUrl FROM UserProfile WHERE UserId = ? LIMIT 1");
            $freshProfileStmt->execute([$userId]);
            $freshProfile = $freshProfileStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $currentFirstName = trim((string)($freshProfile['FirstName'] ?? $firstName));
            $currentLastName = trim((string)($freshProfile['LastName'] ?? $lastName));
            $currentPhotoUrl = (string)($freshProfile['ProfilePhotoUrl'] ?? $photoUrl ?? '');
            $resolvedPhotoUrl = resolve_image_url($currentPhotoUrl, 'asset/image/default_avatar.svg');

            $_SESSION['first_name'] = $currentFirstName !== '' ? $currentFirstName : ($_SESSION['first_name'] ?? 'Member');
            $_SESSION['last_name'] = $currentLastName;
            $_SESSION['profile_photo_url'] = $currentPhotoUrl;
            $_SESSION['profile_photo_version'] = time();

            echo json_encode([
                'message' => 'Profile updated successfully.',
                'photo' => $resolvedPhotoUrl,
                'first_name' => $currentFirstName,
                'last_name' => $currentLastName,
            ]);
        } catch (Exception $e) {
            echo json_encode(['error' => 'Failed to update profile.']);
        }
        exit();
    }

    // 2. Change Password
    if ($action === 'change_password') {
        $currentPass = $_POST['current_password']  ?? '';
        $newPass     = $_POST['new_password']       ?? '';
        $confirmPass = $_POST['confirm_password']   ?? '';

        if ($newPass !== $confirmPass) {
            echo json_encode(['error' => 'New passwords do not match.']);
            exit();
        }
        if (strlen($newPass) < 8) {
            echo json_encode(['error' => 'Password must be at least 8 characters.']);
            exit();
        }
        try {
            $stmt = $pdo->prepare("SELECT PasswordHash FROM Users WHERE UserId = ?");
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            if (!$row || !password_verify($currentPass, $row['PasswordHash'])) {
                echo json_encode(['error' => 'Current password is incorrect.']);
                exit();
            }
            $pdo->prepare("UPDATE Users SET PasswordHash = ? WHERE UserId = ?")
                ->execute([password_hash($newPass, PASSWORD_DEFAULT), $userId]);
            echo json_encode(['message' => 'Password changed successfully.']);
        } catch (Exception $e) {
            echo json_encode(['error' => 'Failed to change password.']);
        }
        exit();
    }

    // 3. Add Address
    if ($action === 'add_address') {
        $recipientName = trim($_POST['recipient_name'] ?? '');
        $phone         = trim($_POST['phone']          ?? '');
        $addressLine1  = trim($_POST['address_line1']  ?? '');
        $addressLine2  = trim($_POST['address_line2']  ?? '');
        $state         = trim($_POST['states']         ?? '');
        $city          = trim($_POST['cities']         ?? '');
        $postcode      = trim($_POST['postcodes']      ?? '');
        $isDefault     = isset($_POST['is_default']) ? 1 : 0;
        $useDetailedAddress = supportsDetailedAddressColumns($pdo);
        $fullAddress = implode(', ', array_filter([$addressLine1, $addressLine2, $postcode, $city, $state]));

        if (!$recipientName || !$phone || !$addressLine1) {
            echo json_encode(['error' => 'All address fields are required.']);
            exit();
        }
        if ($useDetailedAddress && (!$city || !$state || !$postcode)) {
            echo json_encode(['error' => 'All address fields are required.']);
            exit();
        }
        try {
            $pdo->beginTransaction();
            if ($isDefault) {
                $pdo->prepare("UPDATE Addresses SET IsDefault = 0 WHERE UserId = ?")->execute([$userId]);
            }
            $addressId = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));

            if ($useDetailedAddress) {
                $pdo->prepare("INSERT INTO Addresses (AddressId, UserId, RecipientName, PhoneNumber, AddressLine1, AddressLine2, States, City, Postcode, IsDefault) VALUES (?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$addressId, $userId, $recipientName, $phone, $addressLine1, $addressLine2, $state, $city, $postcode, $isDefault]);
            } else {
                $pdo->prepare("INSERT INTO Addresses (AddressId, UserId, RecipientName, PhoneNumber, FullAddress, IsDefault) VALUES (?,?,?,?,?,?)")
                    ->execute([$addressId, $userId, $recipientName, $phone, $fullAddress, $isDefault]);
            }

            $pdo->commit();
            echo json_encode(['message' => 'Address added successfully.']);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['error' => 'Failed to add address.']);
        }
        exit();
    }

    // 4. Set Default Address
    if ($action === 'set_default_address') {
        $addressId = trim($_POST['address_id'] ?? '');

        if ($addressId === '') {
            echo json_encode(['error' => 'Address id is required.']);
            exit();
        }

        try {
            $checkStmt = $pdo->prepare("SELECT AddressId FROM Addresses WHERE AddressId = ? AND UserId = ? LIMIT 1");
            $checkStmt->execute([$addressId, $userId]);
            if (!$checkStmt->fetch()) {
                echo json_encode(['error' => 'Address not found.']);
                exit();
            }

            $pdo->beginTransaction();
            $pdo->prepare("UPDATE Addresses SET IsDefault = 0 WHERE UserId = ?")->execute([$userId]);
            $pdo->prepare("UPDATE Addresses SET IsDefault = 1 WHERE AddressId = ? AND UserId = ?")->execute([$addressId, $userId]);
            $pdo->commit();

            echo json_encode(['message' => 'Default address updated.']);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['error' => 'Failed to update default address.']);
        }
        exit();
    }

    // 5. Delete Address
    if ($action === 'delete_address') {
        $addressId = trim($_POST['address_id'] ?? '');

        if ($addressId === '') {
            echo json_encode(['error' => 'Address id is required.']);
            exit();
        }

        try {
            $checkStmt = $pdo->prepare("SELECT AddressId FROM Addresses WHERE AddressId = ? AND UserId = ? LIMIT 1");
            $checkStmt->execute([$addressId, $userId]);
            if (!$checkStmt->fetch()) {
                echo json_encode(['error' => 'Address not found.']);
                exit();
            }

            $pdo->prepare("DELETE FROM Addresses WHERE AddressId = ? AND UserId = ?")->execute([$addressId, $userId]);
            echo json_encode(['message' => 'Address deleted.']);
        } catch (Exception $e) {
            echo json_encode(['error' => 'Failed to delete address.']);
        }
        exit();
    }

    // 6. Update Address
    if ($action === 'update_address') {
        $addressId     = trim($_POST['address_id']      ?? '');
        $recipientName = trim($_POST['recipient_name']  ?? '');
        $phone         = trim($_POST['phone']           ?? '');
        $addressLine1  = trim($_POST['address_line1']   ?? '');
        $addressLine2  = trim($_POST['address_line2']   ?? '');
        $state         = trim($_POST['states']          ?? '');
        $city          = trim($_POST['cities']          ?? '');
        $postcode      = trim($_POST['postcodes']       ?? '');
        $isDefault     = isset($_POST['is_default']) ? 1 : 0;
        $useDetailedAddress = supportsDetailedAddressColumns($pdo);
        $fullAddress = implode(', ', array_filter([$addressLine1, $addressLine2, $postcode, $city, $state]));

        if (!$addressId || !$recipientName || !$phone || !$addressLine1) {
            echo json_encode(['error' => 'All address fields are required.']);
            exit();
        }
        if ($useDetailedAddress && (!$city || !$state || !$postcode)) {
            echo json_encode(['error' => 'All address fields are required.']);
            exit();
        }

        try {
            $checkStmt = $pdo->prepare("SELECT AddressId FROM Addresses WHERE AddressId = ? AND UserId = ? LIMIT 1");
            $checkStmt->execute([$addressId, $userId]);
            if (!$checkStmt->fetch()) {
                echo json_encode(['error' => 'Address not found.']);
                exit();
            }

            $pdo->beginTransaction();
            if ($isDefault) {
                $pdo->prepare("UPDATE Addresses SET IsDefault = 0 WHERE UserId = ?")->execute([$userId]);
            }

            if ($useDetailedAddress) {
                $pdo->prepare("UPDATE Addresses SET RecipientName = ?, PhoneNumber = ?, AddressLine1 = ?, AddressLine2 = ?, States = ?, City = ?, Postcode = ?, IsDefault = ? WHERE AddressId = ? AND UserId = ?")
                    ->execute([$recipientName, $phone, $addressLine1, $addressLine2, $state, $city, $postcode, $isDefault, $addressId, $userId]);
            } else {
                $pdo->prepare("UPDATE Addresses SET RecipientName = ?, PhoneNumber = ?, FullAddress = ?, IsDefault = ? WHERE AddressId = ? AND UserId = ?")
                    ->execute([$recipientName, $phone, $fullAddress, $isDefault, $addressId, $userId]);
            }

            $pdo->commit();
            echo json_encode(['message' => 'Address updated successfully.']);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['error' => 'Failed to update address.']);
        }
        exit();
    }

    echo json_encode(['error' => 'Unknown action.']);
    exit();
}

// ─── Page load: fetch profile & addresses ─────────────────────────────────────
$stmt = $pdo->prepare(
    "SELECT u.Email, up.FirstName, up.LastName, up.PhoneNumber, up.ProfilePhotoUrl
     FROM Users u
     LEFT JOIN UserProfile up ON u.UserId = up.UserId
     WHERE u.UserId = ? LIMIT 1"
);
$stmt->execute([$userId]);
$profile = $stmt->fetch() ?: [
    'Email' => '',
    'FirstName' => '',
    'LastName' => '',
    'PhoneNumber' => '',
    'ProfilePhotoUrl' => '',
];

$addrStmt = $pdo->prepare("SELECT * FROM Addresses WHERE UserId = ? ORDER BY IsDefault DESC");
$addrStmt->execute([$userId]);
$addresses = $addrStmt->fetchAll();
$useDetailedAddress = supportsDetailedAddressColumns($pdo);

$avatarSrc = htmlspecialchars(resolve_image_url($profile['ProfilePhotoUrl'] ?? null, 'asset/image/default_avatar.svg'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-commerce || My Profile</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Space+Grotesk:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <style>
        :root {
            --profile-ink: #1b2530;
            --profile-muted: #6b7c8d;
            --profile-line: #dbece5;
            --profile-accent: #0f8f6f;
            --profile-accent-dark: #0b6f56;
            --profile-shadow: 0 16px 32px rgba(11, 51, 39, 0.1);
        }

        body {
            font-family: 'Outfit', sans-serif;
            color: var(--profile-ink);
            background:
                radial-gradient(1300px 500px at 0% 0%, #eefbf5 0%, transparent 55%),
                radial-gradient(950px 450px at 100% 20%, #edf4ff 0%, transparent 54%),
                #f8fcfa;
        }

        .profile-hero {
            background:
                radial-gradient(circle at 10% 25%, rgba(15,143,111,.16), transparent 38%),
                radial-gradient(circle at 88% 72%, rgba(39,124,198,.14), transparent 34%),
                linear-gradient(135deg, #f0faf5, #e6f4ff);
            border: 1px solid var(--profile-line);
            border-radius: 24px;
            box-shadow: var(--profile-shadow);
        }

        .profile-kicker {
            display: inline-block;
            background: rgba(15,143,111,.12);
            color: #0b6f56;
            border: 1px solid rgba(15,143,111,.25);
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            padding: 5px 12px;
            margin-bottom: 10px;
        }

        .profile-title {
            font-family: 'Space Grotesk', sans-serif;
            font-size: clamp(1.8rem, 3vw, 2.4rem);
            font-weight: 800;
            letter-spacing: -.02em;
            margin: 0;
        }

        .profile-subtitle {
            color: var(--profile-muted);
            margin: 0;
            max-width: 52ch;
        }

        .section-panel,
        .profile-card,
        .address-card,
        .tab-card {
            border: 1px solid var(--profile-line);
            border-radius: 20px;
            background: #fff;
            box-shadow: var(--profile-shadow);
        }

        .avatar-circle {
            width: 84px;
            height: 84px;
            object-fit: cover;
            border: 3px solid rgba(15,143,111,.14);
        }

        .avatar-preview {
            width: 112px;
            height: 112px;
            object-fit: cover;
            border: 3px solid rgba(15,143,111,.14);
        }

        .toast-shell {
            position: fixed;
            top: 70px;
            right: 20px;
            z-index: 9999;
            min-width: 280px;
        }

        .profile-sidebar .list-group-item {
            border: 1px solid var(--profile-line);
            color: var(--profile-ink);
            font-weight: 600;
            padding: 0.9rem 1rem;
            background: #fff;
        }

        .profile-sidebar .list-group-item + .list-group-item {
            border-top: 0;
        }

        .profile-sidebar .list-group-item.active {
            background: linear-gradient(135deg, var(--profile-accent), var(--profile-accent-dark));
            border-color: var(--profile-accent-dark);
        }

        .profile-sidebar .list-group-item:hover:not(.active) {
            background: #f4fbf8;
        }

        .profile-card h5,
        .tab-card h5,
        .tab-card h6 {
            font-family: 'Space Grotesk', sans-serif;
            font-weight: 700;
            letter-spacing: -.015em;
        }

        .form-label {
            font-weight: 600;
            color: #234038;
        }

        .form-control,
        .form-select {
            border-radius: 12px;
            border-color: #d8e7e1;
            padding-top: 0.72rem;
            padding-bottom: 0.72rem;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: rgba(15,143,111,.45);
            box-shadow: 0 0 0 .2rem rgba(15,143,111,.12);
        }

        .btn-primary-custom {
            background: linear-gradient(135deg, var(--profile-accent), var(--profile-accent-dark));
            border: none;
            border-radius: 12px;
            color: #fff;
            font-weight: 700;
            padding: 0.78rem 1.1rem;
            transition: transform .15s ease, box-shadow .15s ease;
        }

        .btn-primary-custom:hover {
            color: #fff;
            transform: translateY(-1px);
            box-shadow: 0 10px 20px rgba(11,111,86,.22);
        }

        .btn-outline-custom {
            border: 1.5px solid rgba(27,37,48,.18);
            border-radius: 12px;
            color: #1b2530;
            font-weight: 600;
            background: rgba(255,255,255,.75);
        }

        .btn-outline-custom:hover {
            border-color: var(--profile-accent);
            color: var(--profile-accent-dark);
            background: rgba(15,143,111,.06);
        }

        .address-card + .address-card {
            margin-top: 0.75rem;
        }

        .address-card {
            padding: 1rem;
        }

        .address-card .badge {
            border-radius: 999px;
            padding: 0.45rem 0.65rem;
        }

        .tab-pane .card,
        .tab-pane .profile-card {
            border-radius: 20px;
        }
    </style>
</head>
<body>
<?php include 'layout/nav.php'; ?>

<div class="container py-4 py-md-5">
    <section class="profile-hero p-4 p-md-5 mb-4">
        <div class="d-flex flex-column flex-md-row align-items-md-end justify-content-between gap-3">
            <div>
                <span class="profile-kicker">Account center</span>
                <h1 class="profile-title">Manage your profile, password, and addresses</h1>
                <p class="profile-subtitle mt-2">Keep your account details and shipping addresses in sync across the store.</p>
            </div>
            <div class="text-md-end">
                <div class="small text-muted">Signed in as</div>
                <div class="fw-bold fs-5"><?php echo htmlspecialchars($profile['Email'] ?? ''); ?></div>
            </div>
        </div>
    </section>

    <div class="row g-4">

        <!-- Sidebar -->
        <div class="col-md-3 profile-sidebar">
            <div class="profile-card text-center p-3 mb-3">
                <img src="<?php echo $avatarSrc; ?>" id="sidebarAvatar"
                     class="rounded-circle mx-auto d-block mb-2 avatar-circle" alt="Avatar">
                 <h6 class="mb-0" id="sidebarName"><?php echo htmlspecialchars(trim(($profile['FirstName'] ?? '') . ' ' . ($profile['LastName'] ?? ''))); ?></h6>
                <small class="text-muted"><?php echo htmlspecialchars($profile['Email'] ?? ''); ?></small>
            </div>
            <div class="list-group section-panel overflow-hidden" id="profileTabs">
                <a href="#tab-profile"  class="list-group-item list-group-item-action active" data-bs-toggle="list">
                    <i class="fas fa-user me-2"></i>Edit Profile
                </a>
                <a href="#tab-password" class="list-group-item list-group-item-action" data-bs-toggle="list">
                    <i class="fas fa-lock me-2"></i>Change Password
                </a>
                <a href="#tab-address"  class="list-group-item list-group-item-action" data-bs-toggle="list">
                    <i class="fas fa-map-marker-alt me-2"></i>Addresses
                </a>
            </div>
        </div>

        <!-- Tab content -->
        <div class="col-md-9">
            <div class="tab-content">

                <!-- Tab 1: Edit Profile -->
                <div class="tab-pane fade show active" id="tab-profile">
                    <div class="profile-card p-4 p-md-5">
                        <h5 class="mb-4">Edit Profile</h5>
                        <div class="text-center mb-3">
                            <img src="<?php echo $avatarSrc; ?>" id="avatarPreview"
                                 class="rounded-circle avatar-preview" alt="Avatar Preview">
                            <div class="mt-2 text-muted small">Click "Choose File" below to change your avatar</div>
                        </div>
                        <form id="formProfile" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="update_profile">
                            <div class="mb-3">
                                <label class="form-label">Avatar</label>
                                <input type="file" class="form-control" name="avatar" id="avatarInput" accept="image/*">
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">First Name</label>
                                    <input type="text" class="form-control" name="first_name"
                                           value="<?php echo htmlspecialchars($profile['FirstName'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Last Name</label>
                                    <input type="text" class="form-control" name="last_name"
                                           value="<?php echo htmlspecialchars($profile['LastName'] ?? ''); ?>" required>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Phone Number</label>
                                <input type="text" class="form-control" name="phone"
                                       value="<?php echo htmlspecialchars($profile['PhoneNumber'] ?? ''); ?>">
                            </div>
                            <button type="submit" class="btn btn-primary-custom">
                                <i class="fas fa-save me-1"></i>Save Changes
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Tab 2: Change Password -->
                <div class="tab-pane fade" id="tab-password">
                    <div class="profile-card p-4 p-md-5">
                        <h5 class="mb-4">Change Password</h5>
                        <form id="formPassword" style="max-width: 480px;">
                            <input type="hidden" name="action" value="change_password">
                            <div class="mb-3">
                                <label class="form-label">Current Password</label>
                                <input type="password" class="form-control" name="current_password" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">New Password <small class="text-muted">(min 8 characters)</small></label>
                                <input type="password" class="form-control" name="new_password" required minlength="8">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Confirm New Password</label>
                                <input type="password" class="form-control" name="confirm_password" required minlength="8">
                            </div>
                            <button type="submit" class="btn btn-primary-custom">
                                <i class="fas fa-key me-1"></i>Change Password
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Tab 3: Addresses -->
                <div class="tab-pane fade" id="tab-address">
                    <div class="profile-card p-4 p-md-5">
                        <h5 class="mb-4">My Addresses</h5>

                        <!-- Saved addresses -->
                        <div id="addressList">
                            <?php if ($addresses): ?>
                                <?php foreach ($addresses as $addr): ?>
                                <?php
                                    $addressLine1Value = $addr['AddressLine1'] ?? ($addr['FullAddress'] ?? '');
                                    $addressLine2Value = $addr['AddressLine2'] ?? '';
                                    $stateValue = $addr['States'] ?? '';
                                    $cityValue = $addr['City'] ?? '';
                                    $postcodeValue = $addr['Postcode'] ?? '';

                                    $displayAddress = implode(', ', array_filter([
                                        $addressLine1Value,
                                        $addressLine2Value,
                                        $postcodeValue,
                                        $cityValue,
                                        $stateValue
                                    ]));

                                    if ($displayAddress === '' && isset($addr['FullAddress'])) {
                                        $displayAddress = $addr['FullAddress'];
                                    }
                                ?>
                                <div class="address-card">
                                    <div class="d-flex justify-content-between align-items-start gap-2">
                                        <div class="fw-semibold">
                                            <?php echo htmlspecialchars($addr['RecipientName']); ?>
                                            <?php if ($addr['IsDefault']): ?>
                                                <span class="badge bg-success ms-2">Default</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="d-flex gap-2 flex-shrink-0">
                                            <button type="button"
                                                    class="btn btn-outline-custom btn-sm editAddressBtn"
                                                    data-address-id="<?php echo htmlspecialchars($addr['AddressId'], ENT_QUOTES); ?>"
                                                    data-recipient-name="<?php echo htmlspecialchars($addr['RecipientName'], ENT_QUOTES); ?>"
                                                    data-phone="<?php echo htmlspecialchars($addr['PhoneNumber'], ENT_QUOTES); ?>"
                                                    data-address-line1="<?php echo htmlspecialchars($addressLine1Value, ENT_QUOTES); ?>"
                                                    data-address-line2="<?php echo htmlspecialchars($addressLine2Value, ENT_QUOTES); ?>"
                                                    data-states="<?php echo htmlspecialchars($stateValue, ENT_QUOTES); ?>"
                                                    data-city="<?php echo htmlspecialchars($cityValue, ENT_QUOTES); ?>"
                                                    data-postcode="<?php echo htmlspecialchars($postcodeValue, ENT_QUOTES); ?>"
                                                    data-is-default="<?php echo (int)$addr['IsDefault']; ?>">
                                                Edit
                                            </button>
                                            <?php if (!$addr['IsDefault']): ?>
                                                <form class="setDefaultForm m-0">
                                                    <input type="hidden" name="action" value="set_default_address">
                                                    <input type="hidden" name="address_id" value="<?php echo htmlspecialchars($addr['AddressId']); ?>">
                                                    <button type="submit" class="btn btn-outline-custom btn-sm">Set Default</button>
                                                </form>
                                            <?php endif; ?>
                                            <form class="deleteAddressForm m-0">
                                                <input type="hidden" name="action" value="delete_address">
                                                <input type="hidden" name="address_id" value="<?php echo htmlspecialchars($addr['AddressId']); ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm">Delete</button>
                                            </form>
                                        </div>
                                    </div>
                                    <div class="text-muted small"><?php echo htmlspecialchars($addr['PhoneNumber']); ?></div>
                                    <div><?php echo nl2br(htmlspecialchars($displayAddress)); ?></div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-muted" id="noAddressMsg">No addresses saved yet.</p>
                            <?php endif; ?>
                        </div>

                        <hr>
                        <h6 class="mb-3">Add New Address</h6>
                        <form id="formAddress" style="max-width: 540px;">
                            <input type="hidden" name="action" value="add_address">
                            <div class="mb-3">
                                <label class="form-label">Recipient Name</label>
                                <input type="text" class="form-control" name="recipient_name" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Phone Number</label>
                                <input type="text" class="form-control" name="phone" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Address Line 1</label>
                                <input type="text" class="form-control" name="address_line1" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Address Line 2</label>
                                <input type="text" class="form-control" name="address_line2">
                            </div>
                            <div class="mb-3" <?= $useDetailedAddress ? '' : 'style="display:none;"' ?>>
                                <label class="form-label">State</label>
                                <select class="form-control" name="states" id="states" disabled>
                                    <option value="">Loading states...</option>
                                </select>
                            </div>
                            <div class="mb-3" <?= $useDetailedAddress ? '' : 'style="display:none;"' ?>>
                                <label class="form-label">City</label>
                                <select class="form-control" name="cities" id="cities" disabled>
                                    <option value="">Select City</option>
                                </select>
                            </div>
                            <div class="mb-3" <?= $useDetailedAddress ? '' : 'style="display:none;"' ?>>
                                <label class="form-label">Postcode</label>
                                <select class="form-control" name="postcodes" id="postcode" disabled>
                                    <option value="">Select Postcode</option>
                                </select>
                            </div>

                            <div class="mb-3 form-check">
                                <input type="checkbox" class="form-check-input" name="is_default" id="isDefault">
                                <label class="form-check-label" for="isDefault">Set as default address</label>
                            </div>
                            <button type="submit" class="btn btn-primary-custom">
                                <i class="fas fa-plus me-1"></i>Add Address
                            </button>
                        </form>
                    </div>
                </div>

            </div><!-- /.tab-content -->
        </div>
    </div>
</div>

<div class="modal fade" id="editAddressModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Address</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="formEditAddress">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_address">
                    <input type="hidden" name="address_id" id="editAddressId">

                    <div class="mb-3">
                        <label class="form-label">Recipient Name</label>
                        <input type="text" class="form-control" name="recipient_name" id="editRecipientName" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Phone Number</label>
                        <input type="text" class="form-control" name="phone" id="editPhone" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Address Line 1</label>
                        <input type="text" class="form-control" name="address_line1" id="editAddressLine1" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Address Line 2</label>
                        <input type="text" class="form-control" name="address_line2" id="editAddressLine2">
                    </div>
                    <div class="mb-3" <?= $useDetailedAddress ? '' : 'style="display:none;"' ?>>
                        <label class="form-label">State</label>
                        <select class="form-control" name="states" id="editStates" disabled>
                            <option value="">Loading states...</option>
                        </select>
                    </div>
                    <div class="mb-3" <?= $useDetailedAddress ? '' : 'style="display:none;"' ?>>
                        <label class="form-label">City</label>
                        <select class="form-control" name="cities" id="editCities" disabled>
                            <option value="">Select state first</option>
                        </select>
                    </div>
                    <div class="mb-3" <?= $useDetailedAddress ? '' : 'style="display:none;"' ?>>
                        <label class="form-label">Postcode</label>
                        <select class="form-control" name="postcodes" id="editPostcode" disabled>
                            <option value="">Select city first</option>
                        </select>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_default" id="editIsDefault">
                        <label class="form-check-label" for="editIsDefault">Set as default address</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-custom" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-custom">Save Address</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Toast container -->
<div class="toast-shell" id="toastShell"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const useDetailedAddress = <?= $useDetailedAddress ? 'true' : 'false' ?>;
    let postcodeDataset = [];

    function setSelectOptions(selectEl, placeholder, values) {
        selectEl.innerHTML = '';
        const placeholderOption = document.createElement('option');
        placeholderOption.value = '';
        placeholderOption.textContent = placeholder;
        selectEl.appendChild(placeholderOption);

        values.forEach((value) => {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = value;
            selectEl.appendChild(option);
        });
    }

    function findStateByName(stateName) {
        return postcodeDataset.find((state) => state.name === stateName);
    }

    function findCityByName(stateObj, cityName) {
        if (!stateObj) return null;
        return (stateObj.city || []).find((city) => city.name === cityName) || null;
    }

    function setStateCityPostcode(stateEl, cityEl, postcodeEl, stateValue, cityValue, postcodeValue) {
        const stateObj = findStateByName(stateValue);
        const cities = stateObj ? (stateObj.city || []).map((item) => item.name) : [];

        setSelectOptions(cityEl, cities.length ? 'Select City' : 'No city data available', cities);
        cityEl.disabled = cities.length === 0;
        cityEl.value = cities.includes(cityValue) ? cityValue : '';

        const cityObj = findCityByName(stateObj, cityEl.value);
        const postcodes = cityObj ? (cityObj.postcode || []) : [];

        setSelectOptions(postcodeEl, postcodes.length ? 'Select Postcode' : 'No postcode data available', postcodes);
        postcodeEl.disabled = postcodes.length === 0;
        postcodeEl.value = postcodes.includes(postcodeValue) ? postcodeValue : '';
    }

    async function loadMalaysiaPostcodes() {
        if (!useDetailedAddress) {
            return;
        }

        const stateEl = document.getElementById('states');
        const cityEl = document.getElementById('cities');
        const postcodeEl = document.getElementById('postcode');
        const editStateEl = document.getElementById('editStates');
        const editCityEl = document.getElementById('editCities');
        const editPostcodeEl = document.getElementById('editPostcode');

        stateEl.disabled = true;
        cityEl.disabled = true;
        postcodeEl.disabled = true;
        editStateEl.disabled = true;
        editCityEl.disabled = true;
        editPostcodeEl.disabled = true;

        try {
            const response = await fetch('malaysia_postcodes/malaysia-postcodes/all.json', { cache: 'no-store' });
            if (!response.ok) {
                throw new Error('Failed to load postcode data.');
            }

            const data = await response.json();
            postcodeDataset = Array.isArray(data.state) ? data.state : [];

            if (!postcodeDataset.length) {
                setSelectOptions(stateEl, 'No state data available', []);
                setSelectOptions(cityEl, 'No city data available', []);
                setSelectOptions(postcodeEl, 'No postcode data available', []);
                setSelectOptions(editStateEl, 'No state data available', []);
                setSelectOptions(editCityEl, 'No city data available', []);
                setSelectOptions(editPostcodeEl, 'No postcode data available', []);
                return;
            }

            const stateNames = postcodeDataset.map((item) => item.name);
            setSelectOptions(stateEl, 'Select State', stateNames);
            setSelectOptions(cityEl, 'Select City', []);
            setSelectOptions(postcodeEl, 'Select Postcode', []);
            setSelectOptions(editStateEl, 'Select State', stateNames);
            setSelectOptions(editCityEl, 'Select City', []);
            setSelectOptions(editPostcodeEl, 'Select Postcode', []);

            stateEl.disabled = false;
            cityEl.disabled = true;
            postcodeEl.disabled = true;
            editStateEl.disabled = false;
            editCityEl.disabled = true;
            editPostcodeEl.disabled = true;
        } catch (error) {
            setSelectOptions(stateEl, 'Failed to load states', []);
            setSelectOptions(cityEl, 'No city data available', []);
            setSelectOptions(postcodeEl, 'No postcode data available', []);
            setSelectOptions(editStateEl, 'Failed to load states', []);
            setSelectOptions(editCityEl, 'No city data available', []);
            setSelectOptions(editPostcodeEl, 'No postcode data available', []);

            stateEl.disabled = true;
            cityEl.disabled = true;
            postcodeEl.disabled = true;
            editStateEl.disabled = true;
            editCityEl.disabled = true;
            editPostcodeEl.disabled = true;
            showToast('Unable to load state/city/postcode data.', 'error');
        }
    }

    function attachAddressSelectors() {
        if (!useDetailedAddress) {
            return;
        }

        const stateEl = document.getElementById('states');
        const cityEl = document.getElementById('cities');
        const postcodeEl = document.getElementById('postcode');

        stateEl.addEventListener('change', function () {
            const stateObj = findStateByName(this.value);
            const cities = stateObj ? (stateObj.city || []).map((item) => item.name) : [];
            setSelectOptions(cityEl, cities.length ? 'Select City' : 'No city data available', cities);
            setSelectOptions(postcodeEl, 'Select city first', []);
            cityEl.disabled = cities.length === 0;
            postcodeEl.disabled = true;
        });

        cityEl.addEventListener('change', function () {
            const stateObj = findStateByName(stateEl.value);
            const cityObj = findCityByName(stateObj, this.value);
            const postcodes = cityObj ? (cityObj.postcode || []) : [];
            setSelectOptions(postcodeEl, postcodes.length ? 'Select Postcode' : 'No postcode data available', postcodes);
            postcodeEl.disabled = postcodes.length === 0;
        });

        const editStateEl = document.getElementById('editStates');
        const editCityEl = document.getElementById('editCities');
        const editPostcodeEl = document.getElementById('editPostcode');

        editStateEl.addEventListener('change', function () {
            setStateCityPostcode(editStateEl, editCityEl, editPostcodeEl, this.value, '', '');
        });

        editCityEl.addEventListener('change', function () {
            const stateObj = findStateByName(editStateEl.value);
            const cityObj = findCityByName(stateObj, this.value);
            const postcodes = cityObj ? (cityObj.postcode || []) : [];
            setSelectOptions(editPostcodeEl, postcodes.length ? 'Select Postcode' : 'No postcode data available', postcodes);
            editPostcodeEl.disabled = postcodes.length === 0;
        });
    }

    function showToast(msg, type) {
        const el = document.createElement('div');
        el.className = 'alert alert-' + (type === 'error' ? 'danger' : 'success') +
                       ' alert-dismissible fade show shadow';
        el.innerHTML = msg + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
        document.getElementById('toastShell').appendChild(el);
        setTimeout(() => el.remove(), 4000);
    }

    function ajaxForm(formEl) {
        return fetch('userProfile.php', { method: 'POST', body: new FormData(formEl) })
            .then((response) => response.json())
            .catch(() => ({ error: 'Request failed. Please try again.' }));
    }

    // Avatar live preview
    document.getElementById('avatarInput').addEventListener('change', function () {
        if (this.files[0]) {
            document.getElementById('avatarPreview').src = URL.createObjectURL(this.files[0]);
        }
    });

    // Update Profile
    document.getElementById('formProfile').addEventListener('submit', function (e) {
        e.preventDefault();
        ajaxForm(this).then(res => {
            showToast(res.message || res.error, res.error ? 'error' : 'success');
            if (res.message) {
                const avatarUrl = (res.photo || 'asset/image/default_avatar.svg') + '?v=' + Date.now();
                document.getElementById('sidebarAvatar').src = avatarUrl;
                document.getElementById('avatarPreview').src = avatarUrl;

                const sidebarName = document.getElementById('sidebarName');
                if (sidebarName) {
                    const fullName = ((res.first_name || '') + ' ' + (res.last_name || '')).trim();
                    sidebarName.textContent = fullName || 'Member';
                }

                const navAvatar = document.querySelector('#userDropdown img');
                if (navAvatar) {
                    navAvatar.src = avatarUrl;
                }
            }
        });
    });

    // Change Password
    document.getElementById('formPassword').addEventListener('submit', function (e) {
        e.preventDefault();
        ajaxForm(this).then(res => {
            showToast(res.message || res.error, res.error ? 'error' : 'success');
            if (res.message) this.reset();
        });
    });

    // Add Address
    document.getElementById('formAddress').addEventListener('submit', function (e) {
        e.preventDefault();
        ajaxForm(this).then(res => {
            showToast(res.message || res.error, res.error ? 'error' : 'success');
            if (res.message) {
                // Reload after short delay so the new address appears in the list
                setTimeout(() => location.reload(), 1200);
            }
        });
    });

    // Set Default Address
    document.querySelectorAll('.setDefaultForm').forEach((formEl) => {
        formEl.addEventListener('submit', function (e) {
            e.preventDefault();
            ajaxForm(this).then((res) => {
                showToast(res.message || res.error, res.error ? 'error' : 'success');
                if (res.message) {
                    setTimeout(() => location.reload(), 800);
                }
            });
        });
    });

    // Delete Address
    document.querySelectorAll('.deleteAddressForm').forEach((formEl) => {
        formEl.addEventListener('submit', function (e) {
            e.preventDefault();
            if (!confirm('Are you sure you want to delete this address?')) return;
            const card = this.closest('.border.rounded');
            ajaxForm(this).then((res) => {
                showToast(res.message || res.error, res.error ? 'error' : 'success');
                if (res.message && card) {
                    card.remove();
                }
            });
        });
    });

    const editAddressModalEl = document.getElementById('editAddressModal');
    const editAddressModal = new bootstrap.Modal(editAddressModalEl);

    document.querySelectorAll('.editAddressBtn').forEach((btn) => {
        btn.addEventListener('click', function () {
            document.getElementById('editAddressId').value = this.dataset.addressId || '';
            document.getElementById('editRecipientName').value = this.dataset.recipientName || '';
            document.getElementById('editPhone').value = this.dataset.phone || '';
            document.getElementById('editAddressLine1').value = this.dataset.addressLine1 || '';
            document.getElementById('editAddressLine2').value = this.dataset.addressLine2 || '';
            document.getElementById('editIsDefault').checked = this.dataset.isDefault === '1';

            const editStateEl = document.getElementById('editStates');
            const editCityEl = document.getElementById('editCities');
            const editPostcodeEl = document.getElementById('editPostcode');
            const targetState = this.dataset.states || '';
            const targetCity = this.dataset.city || '';
            const targetPostcode = this.dataset.postcode || '';

            if (!editStateEl.disabled) {
                editStateEl.value = targetState;
                setStateCityPostcode(editStateEl, editCityEl, editPostcodeEl, targetState, targetCity, targetPostcode);
            }

            editAddressModal.show();
        });
    });

    document.getElementById('formEditAddress').addEventListener('submit', function (e) {
        e.preventDefault();
        ajaxForm(this).then((res) => {
            showToast(res.message || res.error, res.error ? 'error' : 'success');
            if (res.message) {
                editAddressModal.hide();
                setTimeout(() => location.reload(), 800);
            }
        });
    });

    if (useDetailedAddress) {
        loadMalaysiaPostcodes();
        attachAddressSelectors();
    }
</script>
</body>
</html>