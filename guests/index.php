
<?php
require_once "../config/database.php";

$message = "";
$messageType = "";
$errors = [];

// Register a new guest
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $firstName = trim($_POST["first_name"] ?? "");
    $lastName = trim($_POST["last_name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $phone = trim($_POST["phone"] ?? "");
    $address = trim($_POST["address"] ?? "");
    $idType = trim($_POST["id_type"] ?? "");
    $idNumber = trim($_POST["id_number"] ?? "");

    if ($firstName === "") {
        $errors[] = "First name is required.";
    }

    if ($lastName === "") {
        $errors[] = "Last name is required.";
    }

    if ($email !== "" &&
        !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    }

    if (empty($errors)) {
        $stmt = $conn->prepare(
            "INSERT INTO guests
            (first_name, last_name, email, phone, address,
             id_type, id_number)
            VALUES (?, ?, ?, ?, ?, ?, ?)"
        );

        $stmt->bind_param(
            "sssssss",
            $firstName,
            $lastName,
            $email,
            $phone,
            $address,
            $idType,
            $idNumber
        );

        if ($stmt->execute()) {
            $stmt->close();
            header("Location: index.php?success=1");
            exit;
        } else {
            $message = "Unable to register the guest. Please try again.";
            $messageType = "error";
            $stmt->close();
        }
    } else {
        $message = implode(" ", $errors);
        $messageType = "error";
    }
}

// Registration success message
if (isset($_GET["success"])) {
    $message = "Guest registered successfully!";
    $messageType = "success";
}

// Search guests
$search = trim($_GET["q"] ?? "");

if ($search !== "") {
    $searchTerm = "%" . $search . "%";

    $stmt = $conn->prepare(
        "SELECT guest_id, first_name, last_name, email,
                phone, id_type, id_number, created_at
         FROM guests
         WHERE first_name LIKE ?
            OR last_name LIKE ?
            OR email LIKE ?
            OR phone LIKE ?
         ORDER BY guest_id DESC"
    );

    $stmt->bind_param(
        "ssss",
        $searchTerm,
        $searchTerm,
        $searchTerm,
        $searchTerm
    );

    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query(
        "SELECT guest_id, first_name, last_name, email,
                phone, id_type, id_number, created_at
         FROM guests
         ORDER BY guest_id DESC"
    );
}

if (!$result) {
    die("Unable to load the guest list.");
}

// Safely display text in HTML
function escapeHTML($value) {
    return htmlspecialchars(
        (string) ($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Guest Management | Hotel Front Office</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f3f5f9;
            color: #202938;
        }

        header {
            background: #142b4a;
            color: white;
            padding: 22px 30px;
        }

        header h1 {
            margin: 0 0 8px;
        }

        header p {
            margin: 0;
            color: #d5deeb;
        }

        nav {
            background: white;
            padding: 14px 30px;
            border-bottom: 1px solid #e2e8f0;
        }

        nav a {
            color: #142b4a;
            text-decoration: none;
            font-weight: bold;
            margin-right: 20px;
        }

        main {
            max-width: 1200px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .panel {
            background: white;
            padding: 25px;
            margin-bottom: 25px;
            border-radius: 12px;
            box-shadow: 0 3px 12px #0000000d;
        }

        .panel h2 {
            margin-top: 0;
            color: #142b4a;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .field label {
            font-weight: bold;
            font-size: 14px;
        }

        .field input,
        .field select,
        .field textarea {
            width: 100%;
            padding: 11px;
            border: 1px solid #cbd5e1;
            border-radius: 7px;
            font: inherit;
        }

        .field textarea {
            resize: vertical;
            min-height: 80px;
        }

        .full-width {
            grid-column: 1 / -1;
        }

        button {
            border: none;
            border-radius: 7px;
            padding: 12px 20px;
            background: #142b4a;
            color: white;
            font-size: 15px;
            font-weight: bold;
            cursor: pointer;
        }

        button:hover {
            background: #244a78;
        }

        .message {
            padding: 13px;
            margin-bottom: 20px;
            border-radius: 7px;
        }

        .success {
            background: #dcfce7;
            color: #166534;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
        }

        .search-form {
            display: flex;
            gap: 10px;
            margin: 20px 0;
            flex-wrap: wrap;
        }

        .search-form input {
            flex: 1;
            min-width: 220px;
            padding: 11px;
            border: 1px solid #cbd5e1;
            border-radius: 7px;
            font: inherit;
        }

        .clear-search {
            padding: 12px;
            color: #142b4a;
            text-decoration: none;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 900px;
        }

        th,
        td {
            text-align: left;
            padding: 13px;
            border-bottom: 1px solid #e2e8f0;
        }

        th {
            background: #edf2f7;
            color: #142b4a;
        }

        tbody tr:hover {
            background: #f8fafc;
        }

        td a {
            color: #174b83;
            font-weight: bold;
            text-decoration: none;
        }

        td a:hover {
            text-decoration: underline;
        }

        .empty {
            color: #64748b;
            padding: 20px 0;
        }

        @media (max-width: 650px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .full-width {
                grid-column: auto;
            }

            header,
            nav {
                padding: 18px;
            }

            main {
                padding: 0 12px;
            }

            .panel {
                padding: 18px;
            }
        }
    </style>
</head>

<body>

<header>
    <h1>Guest Management</h1>
    <p>Hotel Management System | Front Office Department</p>
</header>

<nav>
    <a href="../index.php">Dashboard</a>
    <a href="index.php">Guest Management</a>
</nav>

<main>

    <?php if ($message !== ""): ?>
        <div class="message <?= escapeHTML($messageType) ?>">
            <?= escapeHTML($message) ?>
        </div>
    <?php endif; ?>

    <!-- Guest Registration Form -->
    <section class="panel">
        <h2>Register New Guest</h2>

        <p>Enter the guest's information below.</p>

        <form method="POST" action="index.php">
            <div class="form-grid">

                <div class="field">
                    <label for="first_name">First Name *</label>
                    <input type="text" id="first_name"
                           name="first_name" maxlength="100"
                           required
                           value="<?= escapeHTML($_POST["first_name"] ?? "") ?>">
                </div>

                <div class="field">
                    <label for="last_name">Last Name *</label>
                    <input type="text" id="last_name"
                           name="last_name" maxlength="100"
                           required
                           value="<?= escapeHTML($_POST["last_name"] ?? "") ?>">
                </div>

                <div class="field">
                    <label for="email">Email Address</label>
                    <input type="email" id="email"
                           name="email" maxlength="150"
                           value="<?= escapeHTML($_POST["email"] ?? "") ?>">
                </div>

                <div class="field">
                    <label for="phone">Phone Number</label>
                    <input type="tel" id="phone"
                           name="phone" maxlength="30"
                           value="<?= escapeHTML($_POST["phone"] ?? "") ?>">
                </div>

                <div class="field">
                    <label for="id_type">Identification Type</label>
                    <select id="id_type" name="id_type">
                        <option value="">Select ID type</option>

                        <?php
                        $idTypes = [
                            "Passport",
                            "Driver's License",
                            "National ID",
                            "School ID",
                            "Other"
                        ];

                        foreach ($idTypes as $type):
                            $selected =
                                ($_POST["id_type"] ?? "") === $type
                                ? "selected" : "";
                        ?>
                            <option value="<?= escapeHTML($type) ?>"
                                <?= $selected ?>>
                                <?= escapeHTML($type) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="id_number">Identification Number</label>
                    <input type="text" id="id_number"
                           name="id_number" maxlength="100"
                           value="<?= escapeHTML($_POST["id_number"] ?? "") ?>">
                </div>

                <div class="field full-width">
                    <label for="address">Address</label>
                    <textarea id="address"
                              name="address"><?= escapeHTML($_POST["address"] ?? "") ?></textarea>
                </div>

                <div class="field full-width">
                    <button type="submit">Register Guest</button>
                </div>

            </div>
        </form>
    </section>

    <!-- Registered Guests Table -->
    <section class="panel">
        <h2>Registered Guests</h2>

        <!-- Search Form -->
        <form method="GET" action="index.php" class="search-form">
            <input
                type="search"
                name="q"
                placeholder="Search name, email, or phone..."
                value="<?= escapeHTML($search) ?>"
            >

            <button type="submit">Search</button>

            <a href="index.php" class="clear-search">
                Clear Search
            </a>
        </form>

        <p>
            Matching guests:
            <strong><?= (int) $result->num_rows ?></strong>
        </p>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Guest ID</th>
                        <th>Guest Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>ID Type</th>
                        <th>ID Number</th>
                        <th>Date Registered</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if ($result->num_rows > 0): ?>

                        <?php while ($guest = $result->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <?= (int) $guest["guest_id"] ?>
                                </td>

                                <td>
                                    <?= escapeHTML(
                                        $guest["first_name"] . " " .
                                        $guest["last_name"]
                                    ) ?>
                                </td>

                                <td>
                                    <?= escapeHTML($guest["email"]) ?>
                                </td>

                                <td>
                                    <?= escapeHTML($guest["phone"]) ?>
                                </td>

                                <td>
                                    <?= escapeHTML($guest["id_type"]) ?>
                                </td>

                                <td>
                                    <?= escapeHTML($guest["id_number"]) ?>
                                </td>

                                <td>
                                    <?= escapeHTML($guest["created_at"]) ?>
                                </td>

                                <td>
                                    <a href="view.php?id=<?= (int) $guest["guest_id"] ?>">
                                        View
                                    </a>
                                    |
                                    <a href="edit.php?id=<?= (int) $guest["guest_id"] ?>">
                                        Edit
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; ?>

                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="empty">
                                No matching guests found.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

</main>

</body>
</html>
