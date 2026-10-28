
<?php
require_once "../config/database.php";

$message = "";
$messageType = "success";

// Add a new guest request
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_request"])) {
    $guest_id = filter_input(INPUT_POST, "guest_id", FILTER_VALIDATE_INT);
    $request_type = trim($_POST["request_type"] ?? "");
    $description = trim($_POST["description"] ?? "");

    $allowedTypes = [
        "Extra Towels",
        "Room Cleaning",
        "Maintenance",
        "Food Request",
        "Wake-up Call",
        "Other"
    ];

    if (
        !$guest_id ||
        !in_array($request_type, $allowedTypes, true) ||
        $description === ""
    ) {
        $message = "Please complete all required fields correctly.";
        $messageType = "error";
    } else {
        // Verify that the guest exists
        $stmt = $conn->prepare(
            "SELECT guest_id FROM guests WHERE guest_id = ?"
        );
        $stmt->bind_param("i", $guest_id);
        $stmt->execute();
        $guest = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$guest) {
            $message = "Selected guest was not found.";
            $messageType = "error";
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO guest_requests
                    (guest_id, request_type, description, request_status)
                 VALUES (?, ?, ?, 'Pending')"
            );

            $stmt->bind_param(
                "iss",
                $guest_id,
                $request_type,
                $description
            );

            if ($stmt->execute()) {
                $message = "Guest request added successfully.";
            } else {
                $message = "Unable to add the guest request.";
                $messageType = "error";
            }

            $stmt->close();
        }
    }
}

// Update a request's status
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["update_status"])
) {
    $request_id = filter_input(
        INPUT_POST,
        "request_id",
        FILTER_VALIDATE_INT
    );

    $newStatus = $_POST["new_status"] ?? "";

    $allowedStatuses = [
        "Pending",
        "In Progress",
        "Completed",
        "Cancelled"
    ];

    if (
        !$request_id ||
        !in_array($newStatus, $allowedStatuses, true)
    ) {
        $message = "Invalid request or status.";
        $messageType = "error";
    } else {
        $stmt = $conn->prepare(
            "UPDATE guest_requests
             SET request_status = ?
             WHERE request_id = ?"
        );

        $stmt->bind_param("si", $newStatus, $request_id);

        if ($stmt->execute() && $stmt->affected_rows >= 0) {
            $message = "Request status updated successfully.";
        } else {
            $message = "Unable to update the request status.";
            $messageType = "error";
        }

        $stmt->close();
    }
}

// Search requests
$search = trim($_GET["search"] ?? "");

$sql = "
    SELECT
        gr.request_id,
        gr.guest_id,
        gr.request_type,
        gr.description,
        gr.request_status,
        gr.created_at,
        g.first_name,
        g.last_name
    FROM guest_requests gr
    INNER JOIN guests g ON gr.guest_id = g.guest_id
";

if ($search !== "") {
    $sql .= "
        WHERE CAST(gr.request_id AS CHAR) LIKE ?
        OR g.first_name LIKE ?
        OR g.last_name LIKE ?
        OR gr.request_type LIKE ?
        OR gr.request_status LIKE ?
    ";
}

$sql .= " ORDER BY gr.request_id DESC";

if ($search !== "") {
    $stmt = $conn->prepare($sql);
    $term = "%" . $search . "%";
    $stmt->bind_param(
        "sssss",
        $term,
        $term,
        $term,
        $term,
        $term
    );
    $stmt->execute();
    $requests = $stmt->get_result();
} else {
    $requests = $conn->query($sql);
}

// Get guests for the registration dropdown
$guests = $conn->query(
    "SELECT guest_id, first_name, last_name
     FROM guests
     ORDER BY first_name, last_name"
);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guest Requests Management</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f4f6f9;
            margin: 0;
            padding: 25px;
            color: #222;
        }

        .container {
            max-width: 1200px;
            margin: auto;
        }

        h1 {
            margin-bottom: 8px;
        }

        .subtitle {
            color: #666;
            margin-bottom: 25px;
        }

        .card {
            background: white;
            padding: 22px;
            border-radius: 8px;
            margin-bottom: 25px;
            box-shadow: 0 2px 8px #00000010;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        label {
            display: block;
            font-weight: bold;
            margin-bottom: 7px;
        }

        input, select, textarea {
            width: 100%;
            padding: 11px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 14px;
        }

        textarea {
            resize: vertical;
            min-height: 85px;
        }

        .full-width {
            grid-column: 1 / -1;
        }

        button {
            padding: 10px 14px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            color: white;
            background: #172b4d;
            font-weight: bold;
        }

        .save-button {
            background: #198754;
        }

        .update-button {
            background: #0d6efd;
            margin-top: 7px;
        }

        .message {
            padding: 12px 15px;
            border-radius: 6px;
            margin-bottom: 18px;
        }

        .success {
            background: #d1e7dd;
            color: #0f5132;
        }

        .error {
            background: #f8d7da;
            color: #842029;
        }

        .search-form {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }

        .search-form input {
            max-width: 400px;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 850px;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
            vertical-align: top;
        }

        th {
            background: #172b4d;
            color: white;
        }

        .status-form {
            min-width: 140px;
        }

        .back {
            display: inline-block;
            margin-top: 5px;
            color: #0d6efd;
            text-decoration: none;
        }

        @media (max-width: 650px) {
            body {
                padding: 12px;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .full-width {
                grid-column: auto;
            }
        }
    </style>
</head>

<body>
<div class="container">
    <h1>Guest Requests Management</h1>
    <p class="subtitle">
        Record guest requests and monitor their progress.
    </p>

    <?php if ($message !== ""): ?>
        <div class="message <?= $messageType === "error" ? "error" : "success" ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2>Add Guest Request</h2>

        <form method="POST">
            <div class="form-grid">
                <div>
                    <label for="guest_id">Guest</label>
                    <select name="guest_id" id="guest_id" required>
                        <option value="">Select a guest</option>
                        <?php if ($guests): ?>
                            <?php while ($guest = $guests->fetch_assoc()): ?>
                                <option value="<?= (int) $guest["guest_id"] ?>">
                                    <?= htmlspecialchars(
                                        $guest["first_name"] . " " .
                                        $guest["last_name"]
                                    ) ?>
                                    (ID: <?= (int) $guest["guest_id"] ?>)
                                </option>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <div>
                    <label for="request_type">Request Type</label>
                    <select name="request_type" id="request_type" required>
                        <option value="">Select request type</option>
                        <option value="Extra Towels">Extra Towels</option>
                        <option value="Room Cleaning">Room Cleaning</option>
                        <option value="Maintenance">Maintenance</option>
                        <option value="Food Request">Food Request</option>
                        <option value="Wake-up Call">Wake-up Call</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <div class="full-width">
                    <label for="description">Request Description</label>
                    <textarea
                        name="description"
                        id="description"
                        maxlength="1000"
                        placeholder="Describe what the guest needs..."
                        required
                    ></textarea>
                </div>

                <div class="full-width">
                    <button class="save-button" type="submit" name="add_request">
                        Add Request
                    </button>
                </div>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>Guest Request List</h2>

        <form method="GET" class="search-form">
            <input
                type="text"
                name="search"
                value="<?= htmlspecialchars($search) ?>"
                placeholder="Search ID, guest, type, or status..."
            >
            <button type="submit">Search</button>
            <a class="back" href="index.php">Clear</a>
        </form>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Request ID</th>
                        <th>Guest</th>
                        <th>Request Type</th>
                        <th>Description</th>
                        <th>Date Created</th>
                        <th>Status</th>
                        <th>Update Status</th>
                    </tr>
                </thead>

                <tbody>
                <?php if ($requests && $requests->num_rows > 0): ?>
                    <?php while ($row = $requests->fetch_assoc()): ?>
                        <tr>
                            <td><?= (int) $row["request_id"] ?></td>

                            <td>
                                <?= htmlspecialchars(
                                    $row["first_name"] . " " .
                                    $row["last_name"]
                                ) ?>
                            </td>

                            <td><?= htmlspecialchars($row["request_type"]) ?></td>

                            <td><?= nl2br(htmlspecialchars($row["description"])) ?></td>

                            <td><?= htmlspecialchars($row["created_at"]) ?></td>

                            <td><?= htmlspecialchars($row["request_status"]) ?></td>

                            <td>
                                <form method="POST" class="status-form">
                                    <input
                                        type="hidden"
                                        name="request_id"
                                        value="<?= (int) $row["request_id"] ?>"
                                    >

                                    <select name="new_status" required>
                                        <?php foreach (
                                            ["Pending", "In Progress", "Completed", "Cancelled"]
                                            as $status
                                        ): ?>
                                            <option
                                                value="<?= htmlspecialchars($status) ?>"
                                                <?= $row["request_status"] === $status
                                                    ? "selected"
                                                    : "" ?>
                                            >
                                                <?= htmlspecialchars($status) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                    <button
                                        class="update-button"
                                        type="submit"
                                        name="update_status"
                                    >
                                        Update
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7">No guest requests found.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <a class="back" href="../index.php">← Back to Dashboard</a>
</div>
</body>
</html>