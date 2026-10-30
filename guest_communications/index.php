
<?php
include "../config/database.php";

$message = "";
$message_type = "";

// ADD GUEST COMMUNICATION
if (isset($_POST["save_communication"])) {

    $guest_id = intval($_POST["guest_id"] ?? 0);
    $communication_type = $_POST["communication_type"] ?? "";
    $notification_type = trim($_POST["notification_type"] ?? "");
    $subject = trim($_POST["subject"] ?? "");
    $message_content = trim($_POST["message_content"] ?? "");

    $allowed_communication_types = ["Email", "SMS"];

    $allowed_notification_types = [
        "Reservation Confirmation",
        "Booking Reminder",
        "Check-in Reminder",
        "General Announcement",
        "Other"
    ];

    if (
        $guest_id <= 0 ||
        !in_array($communication_type, $allowed_communication_types) ||
        !in_array($notification_type, $allowed_notification_types) ||
        $message_content === ""
    ) {

        $message = "Please complete all required fields.";
        $message_type = "error";

    } else {

        // Check if guest exists
        $stmt = $conn->prepare("
            SELECT guest_id
            FROM guests
            WHERE guest_id = ?
        ");

        $stmt->bind_param("i", $guest_id);
        $stmt->execute();

        $guest = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$guest) {

            $message = "Selected guest does not exist.";
            $message_type = "error";

        } else {

            $stmt = $conn->prepare("
                INSERT INTO guest_communications
                (
                    guest_id,
                    communication_type,
                    notification_type,
                    subject,
                    message_content
                )
                VALUES (?, ?, ?, ?, ?)
            ");

            $stmt->bind_param(
                "issss",
                $guest_id,
                $communication_type,
                $notification_type,
                $subject,
                $message_content
            );

            if ($stmt->execute()) {

                $message = "Guest communication successfully recorded.";
                $message_type = "success";

            } else {

                $message = "Unable to save communication.";
                $message_type = "error";
            }

            $stmt->close();
        }
    }
}

// UPDATE COMMUNICATION STATUS
if (isset($_POST["update_status"])) {

    $communication_id = intval($_POST["communication_id"] ?? 0);
    $new_status = $_POST["new_status"] ?? "";

    $allowed_statuses = ["Pending", "Sent", "Failed"];

    if (
        $communication_id <= 0 ||
        !in_array($new_status, $allowed_statuses)
    ) {

        $message = "Invalid communication status.";
        $message_type = "error";

    } else {

        $stmt = $conn->prepare("
            UPDATE guest_communications
            SET status = ?,
                sent_at = CASE
                    WHEN ? = 'Sent' THEN NOW()
                    ELSE NULL
                END
            WHERE communication_id = ?
        ");

        $stmt->bind_param(
            "ssi",
            $new_status,
            $new_status,
            $communication_id
        );

        if ($stmt->execute() && $stmt->affected_rows >= 0) {

            $message = "Communication status successfully updated.";
            $message_type = "success";

        } else {

            $message = "Unable to update communication status.";
            $message_type = "error";
        }

        $stmt->close();
    }
}

// SEARCH AND FILTER
$search = trim($_GET["search"] ?? "");
$type_filter = $_GET["type"] ?? "All";
$status_filter = $_GET["status"] ?? "All";

$allowed_types = ["All", "Email", "SMS"];
$allowed_filters = ["All", "Pending", "Sent", "Failed"];

if (!in_array($type_filter, $allowed_types)) {
    $type_filter = "All";
}

if (!in_array($status_filter, $allowed_filters)) {
    $status_filter = "All";
}

$sql = "
    SELECT
        c.communication_id,
        c.guest_id,
        c.communication_type,
        c.notification_type,
        c.subject,
        c.message_content,
        c.status,
        c.sent_at,
        c.created_at,
        g.first_name,
        g.last_name,
        g.email,
        g.phone
    FROM guest_communications c
    INNER JOIN guests g ON c.guest_id = g.guest_id
    WHERE 1=1
";

$params = [];
$types = "";

if ($type_filter !== "All") {
    $sql .= " AND c.communication_type = ?";
    $types .= "s";
    $params[] = $type_filter;
}

if ($status_filter !== "All") {
    $sql .= " AND c.status = ?";
    $types .= "s";
    $params[] = $status_filter;
}

if ($search !== "") {

    $sql .= "
        AND (
            CAST(c.communication_id AS CHAR) LIKE ?
            OR g.first_name LIKE ?
            OR g.last_name LIKE ?
            OR g.email LIKE ?
            OR g.phone LIKE ?
        )
    ";

    $search_term = "%" . $search . "%";

    $types .= "sssss";

    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

$sql .= " ORDER BY c.created_at DESC";

$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$communications = $stmt->get_result();

// GET GUEST LIST
$guests = $conn->query("
    SELECT guest_id, first_name, last_name, email, phone
    FROM guests
    ORDER BY first_name ASC
");
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Guest Communication and Notifications</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f4f6f9;
            margin: 0;
            padding: 25px;
            color: #333;
        }

        .container {
            max-width: 1250px;
            margin: auto;
        }

        h1 {
            color: #243447;
            margin-bottom: 8px;
        }

        .subtitle {
            color: #777;
            margin-bottom: 25px;
        }

        .card {
            background: white;
            padding: 22px;
            border-radius: 8px;
            margin-bottom: 25px;
        }

        .message {
            padding: 14px;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        .success {
            background: #d4edda;
            color: #155724;
        }

        .error {
            background: #f8d7da;
            color: #721c24;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 18px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        label {
            font-weight: bold;
            font-size: 14px;
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
        }

        .full-width {
            grid-column: 1 / -1;
        }

        button {
            padding: 10px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 13px;
        }

        .save-button {
            background: #243447;
            color: white;
            padding: 12px 20px;
        }

        .save-button:hover {
            background: #172534;
        }

        .update-button {
            background: #198754;
            color: white;
        }

        .filter-box {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .filter-box input {
            flex: 1;
            min-width: 180px;
        }

        .search-button {
            background: #243447;
            color: white;
        }

        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1150px;
        }

        th, td {
            padding: 13px;
            text-align: left;
            border-bottom: 1px solid #eee;
            font-size: 13px;
            vertical-align: top;
        }

        th {
            background: #243447;
            color: white;
        }

        .status {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: bold;
        }

        .pending {
            background: #fff3cd;
            color: #856404;
        }

        .sent {
            background: #d4edda;
            color: #155724;
        }

        .failed {
            background: #f8d7da;
            color: #721c24;
        }

        .type {
            font-weight: bold;
            color: #243447;
        }

        .message-content {
            max-width: 250px;
            white-space: normal;
            overflow-wrap: anywhere;
        }

        .status-form {
            display: flex;
            flex-direction: column;
            gap: 7px;
            min-width: 125px;
        }

        .status-form select {
            padding: 8px;
        }

        .empty {
            text-align: center;
            padding: 25px;
            color: #777;
        }

        .note {
            background: #eaf2f8;
            color: #24506b;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 13px;
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

            .filter-box {
                flex-direction: column;
            }
        }
    </style>
</head>

<body>

<div class="container">

    <h1>Guest Communication and Notifications</h1>

    <p class="subtitle">
        Record guest messages and manage communication history.
    </p>

    <?php if ($message !== ""): ?>

        <div class="message <?php echo $message_type; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>

    <?php endif; ?>

    <div class="note">
        Note: This module records communication details and status.
        It does not automatically send emails or SMS messages.
    </div>

    <!-- ADD COMMUNICATION -->
    <div class="card">

        <h2>Record Guest Communication</h2>

        <form method="POST">

            <div class="form-grid">

                <div class="form-group full-width">

                    <label>Select Guest</label>

                    <select name="guest_id" required>

                        <option value="">Select Guest</option>

                        <?php while ($guest = $guests->fetch_assoc()): ?>

                            <option value="<?php echo $guest["guest_id"]; ?>">

                                <?php
                                echo htmlspecialchars(
                                    $guest["first_name"] . " " . $guest["last_name"]
                                );

                                echo " | " . htmlspecialchars(
                                    $guest["email"] ?? ""
                                );
                                ?>

                            </option>

                        <?php endwhile; ?>

                    </select>

                </div>

                <div class="form-group">

                    <label>Communication Method</label>

                    <select name="communication_type" required>

                        <option value="">Select Method</option>
                        <option value="Email">Email</option>
                        <option value="SMS">SMS</option>

                    </select>

                </div>

                <div class="form-group">

                    <label>Notification Type</label>

                    <select name="notification_type" required>

                        <option value="">Select Notification</option>

                        <option value="Reservation Confirmation">
                            Reservation Confirmation
                        </option>

                        <option value="Booking Reminder">
                            Booking Reminder
                        </option>

                        <option value="Check-in Reminder">
                            Check-in Reminder
                        </option>

                        <option value="General Announcement">
                            General Announcement
                        </option>

                        <option value="Other">
                            Other
                        </option>

                    </select>

                </div>

                <div class="form-group full-width">

                    <label>Subject (Optional)</label>

                    <input
                        type="text"
                        name="subject"
                        maxlength="150"
                        placeholder="Enter message subject"
                    >

                </div>

                <div class="form-group full-width">

                    <label>Message Content</label>

                    <textarea
                        name="message_content"
                        rows="5"
                        placeholder="Enter the guest message..."
                        required
                    ></textarea>

                </div>

                <div class="form-group full-width">

                    <button
                        type="submit"
                        name="save_communication"
                        class="save-button"
                    >
                        Save Communication
                    </button>

                </div>

            </div>

        </form>

    </div>

    <!-- COMMUNICATION HISTORY -->
    <div class="card">

        <h2>Communication History</h2>

        <form method="GET" class="filter-box">

            <input
                type="text"
                name="search"
                placeholder="Search guest, email, phone, or ID..."
                value="<?php echo htmlspecialchars($search); ?>"
            >

            <select name="type">

                <option value="All"
                    <?php echo $type_filter === "All" ? "selected" : ""; ?>>
                    All Methods
                </option>

                <option value="Email"
                    <?php echo $type_filter === "Email" ? "selected" : ""; ?>>
                    Email
                </option>

                <option value="SMS"
                    <?php echo $type_filter === "SMS" ? "selected" : ""; ?>>
                    SMS
                </option>

            </select>

            <select name="status">

                <option value="All"
                    <?php echo $status_filter === "All" ? "selected" : ""; ?>>
                    All Statuses
                </option>

                <option value="Pending"
                    <?php echo $status_filter === "Pending" ? "selected" : ""; ?>>
                    Pending
                </option>

                <option value="Sent"
                    <?php echo $status_filter === "Sent" ? "selected" : ""; ?>>
                    Sent
                </option>

                <option value="Failed"
                    <?php echo $status_filter === "Failed" ? "selected" : ""; ?>>
                    Failed
                </option>

            </select>

            <button type="submit" class="search-button">
                Search
            </button>

        </form>

        <div class="table-container">

            <table>

                <thead>

                    <tr>
                        <th>ID</th>
                        <th>Guest</th>
                        <th>Contact Details</th>
                        <th>Method</th>
                        <th>Notification</th>
                        <th>Subject</th>
                        <th>Message</th>
                        <th>Status</th>
                        <th>Created At</th>
                        <th>Update Status</th>
                    </tr>

                </thead>

                <tbody>

                    <?php if ($communications->num_rows > 0): ?>

                        <?php while ($row = $communications->fetch_assoc()): ?>

                            <tr>

                                <td>
                                    #<?php echo $row["communication_id"]; ?>
                                </td>

                                <td>
                                    <?php
                                    echo htmlspecialchars(
                                        $row["first_name"] . " " . $row["last_name"]
                                    );
                                    ?>
                                </td>

                                <td>
                                    Email:
                                    <?php echo htmlspecialchars($row["email"] ?? "N/A"); ?>
                                    <br><br>
                                    Phone:
                                    <?php echo htmlspecialchars($row["phone"] ?? "N/A"); ?>
                                </td>

                                <td class="type">
                                    <?php echo htmlspecialchars($row["communication_type"]); ?>
                                </td>

                                <td>
                                    <?php echo htmlspecialchars($row["notification_type"]); ?>
                                </td>

                                <td>
                                    <?php echo htmlspecialchars($row["subject"]); ?>
                                </td>

                                <td class="message-content">
                                    <?php echo nl2br(htmlspecialchars($row["message_content"])); ?>
                                </td>

                                <td>

                                    <span class="status <?php echo strtolower($row["status"]); ?>">
                                        <?php echo htmlspecialchars($row["status"]); ?>
                                    </span>

                                    <?php if ($row["sent_at"]): ?>
                                        <br><br>
                                        <small>
                                            Sent: <?php echo htmlspecialchars($row["sent_at"]); ?>
                                        </small>
                                    <?php endif; ?>

                                </td>

                                <td>
                                    <?php echo htmlspecialchars($row["created_at"]); ?>
                                </td>

                                <td>

                                    <form method="POST" class="status-form">

                                        <input
                                            type="hidden"
                                            name="communication_id"
                                            value="<?php echo $row["communication_id"]; ?>"
                                        >

                                        <select name="new_status" required>

                                            <option value="Pending"
                                                <?php echo $row["status"] === "Pending" ? "selected" : ""; ?>>
                                                Pending
                                            </option>

                                            <option value="Sent"
                                                <?php echo $row["status"] === "Sent" ? "selected" : ""; ?>>
                                                Sent
                                            </option>

                                            <option value="Failed"
                                                <?php echo $row["status"] === "Failed" ? "selected" : ""; ?>>
                                                Failed
                                            </option>

                                        </select>

                                        <button
                                            type="submit"
                                            name="update_status"
                                            class="update-button"
                                        >
                                            Update
                                        </button>

                                    </form>

                                </td>

                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="10" class="empty">
                                No communication records found.
                            </td>
                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

</body>
</html>

<?php
$stmt->close();
$conn->close();
?>
