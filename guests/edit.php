
<?php
require_once "../config/database.php";

function escapeHTML($value) {
    return htmlspecialchars(
        (string) ($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}

// Get guest ID
$id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    exit("Invalid guest ID.");
}

// Load existing guest
$stmt = $conn->prepare(
    "SELECT * FROM guests WHERE guest_id = ?"
);

$stmt->bind_param("i", $id);
$stmt->execute();

$result = $stmt->get_result();
$guest = $result->fetch_assoc();

$stmt->close();

if (!$guest) {
    http_response_code(404);
    exit("Guest not found.");
}

$message = "";
$errors = [];

// Update guest when form is submitted
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $firstName = trim($_POST["first_name"] ?? "");
    $lastName = trim($_POST["last_name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $phone = trim($_POST["phone"] ?? "");
    $address = trim($_POST["address"] ?? "");
    $idType = trim($_POST["id_type"] ?? "");
    $idNumber = trim($_POST["id_number"] ?? "");

    // Validate required fields
    if ($firstName === "") {
        $errors[] = "First name is required.";
    }

    if ($lastName === "") {
        $errors[] = "Last name is required.";
    }

    if (
        $email !== "" &&
        !filter_var($email, FILTER_VALIDATE_EMAIL)
    ) {
        $errors[] = "Please enter a valid email address.";
    }

    // Preserve submitted values if validation fails
    $guest["first_name"] = $firstName;
    $guest["last_name"] = $lastName;
    $guest["email"] = $email;
    $guest["phone"] = $phone;
    $guest["address"] = $address;
    $guest["id_type"] = $idType;
    $guest["id_number"] = $idNumber;

    if (empty($errors)) {

        $stmt = $conn->prepare(
            "UPDATE guests
             SET first_name = ?,
                 last_name = ?,
                 email = ?,
                 phone = ?,
                 address = ?,
                 id_type = ?,
                 id_number = ?
             WHERE guest_id = ?"
        );

        $stmt->bind_param(
            "sssssssi",
            $firstName,
            $lastName,
            $email,
            $phone,
            $address,
            $idType,
            $idNumber,
            $id
        );

        if ($stmt->execute()) {
            $stmt->close();

            header(
                "Location: view.php?id=" . $id . "&updated=1"
            );
            exit;
        } else {
            $message = "Unable to update guest information.";
            $stmt->close();
        }

    } else {
        $message = implode(" ", $errors);
    }
}

$idTypes = [
    "Passport",
    "Driver's License",
    "National ID",
    "School ID",
    "Other"
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Edit Guest | Hotel Front Office</title>

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
            padding: 24px;
        }

        header h1 {
            margin-top: 0;
        }

        main {
            max-width: 850px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .panel {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 3px 12px #0000000d;
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

        input,
        select,
        textarea {
            width: 100%;
            padding: 11px;
            border: 1px solid #cbd5e1;
            border-radius: 7px;
            font: inherit;
        }

        textarea {
            min-height: 90px;
            resize: vertical;
        }

        .full {
            grid-column: 1 / -1;
        }

        button {
            padding: 12px 20px;
            border: none;
            border-radius: 7px;
            background: #142b4a;
            color: white;
            font-size: 15px;
            font-weight: bold;
            cursor: pointer;
        }

        button:hover {
            background: #244a78;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
            padding: 12px;
            margin-bottom: 18px;
            border-radius: 7px;
        }

        a {
            color: #174b83;
            text-decoration: none;
        }

        @media (max-width: 600px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .full {
                grid-column: auto;
            }
        }
    </style>
</head>

<body>

<header>
    <h1>Edit Guest Information</h1>
    <p>Guest ID: <?= (int) $guest["guest_id"] ?></p>
</header>

<main>
    <section class="panel">

        <?php if ($message !== ""): ?>
            <div class="error">
                <?= escapeHTML($message) ?>
            </div>
        <?php endif; ?>

        <form method="POST"
              action="edit.php?id=<?= (int) $id ?>">

            <div class="form-grid">

                <div class="field">
                    <label for="first_name">First Name *</label>
                    <input
                        type="text"
                        id="first_name"
                        name="first_name"
                        maxlength="100"
                        required
                        value="<?= escapeHTML($guest["first_name"]) ?>"
                    >
                </div>

                <div class="field">
                    <label for="last_name">Last Name *</label>
                    <input
                        type="text"
                        id="last_name"
                        name="last_name"
                        maxlength="100"
                        required
                        value="<?= escapeHTML($guest["last_name"]) ?>"
                    >
                </div>

                <div class="field">
                    <label for="email">Email Address</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        maxlength="150"
                        value="<?= escapeHTML($guest["email"]) ?>"
                    >
                </div>

                <div class="field">
                    <label for="phone">Phone Number</label>
                    <input
                        type="tel"
                        id="phone"
                        name="phone"
                        maxlength="30"
                        value="<?= escapeHTML($guest["phone"]) ?>"
                    >
                </div>

                <div class="field">
                    <label for="id_type">Identification Type</label>
                    <select id="id_type" name="id_type">
                        <option value="">Select ID type</option>

                        <?php foreach ($idTypes as $type): ?>
                            <option
                                value="<?= escapeHTML($type) ?>"
                                <?= $guest["id_type"] === $type
                                    ? "selected" : "" ?>
                            >
                                <?= escapeHTML($type) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="id_number">Identification Number</label>
                    <input
                        type="text"
                        id="id_number"
                        name="id_number"
                        maxlength="100"
                        value="<?= escapeHTML($guest["id_number"]) ?>"
                    >
                </div>

                <div class="field full">
                    <label for="address">Address</label>
                    <textarea
                        id="address"
                        name="address"
                    ><?= escapeHTML($guest["address"]) ?></textarea>
                </div>

                <div class="field full">
                    <button type="submit">Save Changes</button>

                    <p>
                        <a href="view.php?id=<?= (int) $id ?>">
                            Cancel and return to profile
                        </a>
                    </p>
                </div>

            </div>
        </form>

    </section>
</main>

</body>
</html>
