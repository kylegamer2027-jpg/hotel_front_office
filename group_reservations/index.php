
<?php
include "../config/database.php";

$message = "";
$message_type = "";

// CREATE GROUP RESERVATION
if (isset($_POST["create_group"])) {

    $group_name = trim($_POST["group_name"] ?? "");
    $contact_guest_id = intval($_POST["contact_guest_id"] ?? 0);
    $check_in = $_POST["check_in"] ?? "";
    $check_out = $_POST["check_out"] ?? "";
    $adults_per_room = intval($_POST["adults_per_room"] ?? 0);
    $children_per_room = intval($_POST["children_per_room"] ?? 0);
    $room_ids = $_POST["room_ids"] ?? [];

    // Validate room selections
    if (!is_array($room_ids)) {
        $room_ids = [];
    }

    $room_ids = array_values(array_unique(
        array_filter(
            array_map("intval", $room_ids),
            function ($id) {
                return $id > 0;
            }
        )
    ));

    if (
        $group_name === "" ||
        $contact_guest_id <= 0 ||
        empty($check_in) ||
        empty($check_out) ||
        $adults_per_room < 1 ||
        $children_per_room < 0 ||
        strtotime($check_in) === false ||
        strtotime($check_out) === false ||
        $check_in >= $check_out ||
        count($room_ids) === 0
    ) {

        $message = "Please complete all fields correctly and select at least one room.";
        $message_type = "error";

    } else {

        $conn->begin_transaction();

        try {

            // Check main guest
            $stmt = $conn->prepare("
                SELECT guest_id
                FROM guests
                WHERE guest_id = ?
            ");

            $stmt->bind_param("i", $contact_guest_id);
            $stmt->execute();

            $guest = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$guest) {
                throw new Exception("Selected contact person does not exist.");
            }

            // Insert group information
            $stmt = $conn->prepare("
                INSERT INTO group_reservations
                (
                    group_name,
                    contact_guest_id,
                    check_in,
                    check_out,
                    adults_per_room,
                    children_per_room
                )
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            $stmt->bind_param(
                "sissii",
                $group_name,
                $contact_guest_id,
                $check_in,
                $check_out,
                $adults_per_room,
                $children_per_room
            );

            if (!$stmt->execute()) {
                throw new Exception("Unable to create group reservation.");
            }

            $group_id = $conn->insert_id;
            $stmt->close();

            // Prepare room and reservation checks
            $room_check = $conn->prepare("
                SELECT room_status
                FROM rooms
                WHERE room_id = ?
                FOR UPDATE
            ");

            $conflict_check = $conn->prepare("
                SELECT reservation_id
                FROM reservations
                WHERE room_id = ?
                AND reservation_status IN
                    ('Pending', 'Confirmed', 'Checked-in')
                AND check_in < ?
                AND check_out > ?
                LIMIT 1
            ");

            $reservation_insert = $conn->prepare("
                INSERT INTO reservations
                (
                    guest_id,
                    room_id,
                    check_in,
                    check_out,
                    adults,
                    children,
                    booking_source,
                    reservation_status,
                    deposit
                )
                VALUES (?, ?, ?, ?, ?, ?, 'Group', 'Pending', 0)
            ");

            $group_room_insert = $conn->prepare("
                INSERT INTO group_reservation_rooms
                (group_id, reservation_id, room_id)
                VALUES (?, ?, ?)
            ");

            foreach ($room_ids as $room_id) {

                // Lock and check room
                $room_check->bind_param("i", $room_id);
                $room_check->execute();

                $room = $room_check->get_result()->fetch_assoc();

                if (!$room) {
                    throw new Exception("One of the selected rooms does not exist.");
                }

                if ($room["room_status"] !== "Available") {
                    throw new Exception(
                        "Room ID " . $room_id . " is not available."
                    );
                }

                // Check overlapping reservations
                $conflict_check->bind_param(
                    "iss",
                    $room_id,
                    $check_out,
                    $check_in
                );

                $conflict_check->execute();

                $conflict = $conflict_check->get_result()->fetch_assoc();

                if ($conflict) {
                    throw new Exception(
                        "Room ID " . $room_id .
                        " already has a reservation during these dates."
                    );
                }

                // Create an individual reservation for this room
                $reservation_insert->bind_param(
                    "iissii",
                    $contact_guest_id,
                    $room_id,
                    $check_in,
                    $check_out,
                    $adults_per_room,
                    $children_per_room
                );

                if (!$reservation_insert->execute()) {
                    throw new Exception("Unable to create room reservation.");
                }

                $reservation_id = $conn->insert_id;

                // Link reservation to group
                $group_room_insert->bind_param(
                    "iii",
                    $group_id,
                    $reservation_id,
                    $room_id
                );

                if (!$group_room_insert->execute()) {
                    throw new Exception("Unable to link room to group.");
                }
            }

            $room_check->close();
            $conflict_check->close();
            $reservation_insert->close();
            $group_room_insert->close();

            $conn->commit();

            $message = "Group reservation successfully created with " .
                       count($room_ids) . " room(s).";

            $message_type = "success";

        } catch (Exception $e) {

            $conn->rollback();

            $message = $e->getMessage();
            $message_type = "error";
        }
    }
}

// UPDATE GROUP STATUS
if (isset($_POST["update_group_status"])) {

    $group_id = intval($_POST["group_id"] ?? 0);
    $new_status = $_POST["new_status"] ?? "";

    $allowed_statuses = ["Pending", "Confirmed", "Cancelled"];

    if (
        $group_id <= 0 ||
        !in_array($new_status, $allowed_statuses)
    ) {

        $message = "Invalid group reservation status.";
        $message_type = "error";

    } else {

        $conn->begin_transaction();

        try {

            $stmt = $conn->prepare("
                SELECT group_id, group_status
                FROM group_reservations
                WHERE group_id = ?
                FOR UPDATE
            ");

            $stmt->bind_param("i", $group_id);
            $stmt->execute();

            $group = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$group) {
                throw new Exception("Group reservation not found.");
            }

            if ($group["group_status"] === "Cancelled") {
                throw new Exception("Cancelled group reservations cannot be changed.");
            }

            // Confirming a group requires all its reservations to be pending
            if ($new_status === "Confirmed") {

                $stmt = $conn->prepare("
                    SELECT COUNT(*) AS total
                    FROM group_reservation_rooms gr
                    INNER JOIN reservations r
                        ON gr.reservation_id = r.reservation_id
                    WHERE gr.group_id = ?
                    AND r.reservation_status != 'Pending'
                ");

                $stmt->bind_param("i", $group_id);
                $stmt->execute();

                $check = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (intval($check["total"]) > 0) {
                    throw new Exception(
                        "Some room reservations are no longer Pending."
                    );
                }
            }

            // Update linked reservations
            if ($new_status === "Confirmed") {

                $stmt = $conn->prepare("
                    UPDATE reservations r
                    INNER JOIN group_reservation_rooms gr
                        ON r.reservation_id = gr.reservation_id
                    SET r.reservation_status = 'Confirmed'
                    WHERE gr.group_id = ?
                    AND r.reservation_status = 'Pending'
                ");

            } elseif ($new_status === "Cancelled") {

                $stmt = $conn->prepare("
                    UPDATE reservations r
                    INNER JOIN group_reservation_rooms gr
                        ON r.reservation_id = gr.reservation_id
                    SET r.reservation_status = 'Cancelled'
                    WHERE gr.group_id = ?
                    AND r.reservation_status IN ('Pending', 'Confirmed')
                ");

            } else {

                throw new Exception(
                    "A confirmed or cancelled group cannot be changed back to Pending."
                );
            }

            $stmt->bind_param("i", $group_id);

            if (!$stmt->execute()) {
                throw new Exception("Unable to update group room reservations.");
            }

            $stmt->close();

            // Update group status
            $stmt = $conn->prepare("
                UPDATE group_reservations
                SET group_status = ?
                WHERE group_id = ?
            ");

            $stmt->bind_param("si", $new_status, $group_id);
            $stmt->execute();
            $stmt->close();

            // Release reserved rooms when group is cancelled
            if ($new_status === "Cancelled") {

                $stmt = $conn->prepare("
                    UPDATE rooms rm
                    INNER JOIN group_reservation_rooms gr
                        ON rm.room_id = gr.room_id
                    SET rm.room_status = 'Available'
                    WHERE gr.group_id = ?
                    AND rm.room_status = 'Reserved'
                    AND NOT EXISTS (
                        SELECT 1
                        FROM reservations r
                        WHERE r.room_id = rm.room_id
                        AND r.reservation_status IN
                            ('Pending', 'Confirmed', 'Checked-in')
                        AND r.check_in < (
                            SELECT g.check_out
                            FROM group_reservations g
                            WHERE g.group_id = ?
                        )
                        AND r.check_out > (
                            SELECT g.check_in
                            FROM group_reservations g
                            WHERE g.group_id = ?
                        )
                    )
                ");

                $stmt->bind_param(
                    "iii",
                    $group_id,
                    $group_id,
                    $group_id
                );

                $stmt->execute();
                $stmt->close();
            }

            $conn->commit();

            $message = "Group reservation status successfully updated.";
            $message_type = "success";

        } catch (Exception $e) {

            $conn->rollback();

            $message = $e->getMessage();
            $message_type = "error";
        }
    }
}

// SEARCH AND FILTER
$search = trim($_GET["search"] ?? "");
$status_filter = $_GET["status"] ?? "All";

$allowed_filters = ["All", "Pending", "Confirmed", "Cancelled"];

if (!in_array($status_filter, $allowed_filters)) {
    $status_filter = "All";
}

$sql = "
    SELECT
        gr.group_id,
        gr.group_name,
        gr.check_in,
        gr.check_out,
        gr.adults_per_room,
        gr.children_per_room,
        gr.group_status,
        gr.created_at,
        g.first_name,
        g.last_name,
        COUNT(grr.id) AS total_rooms
    FROM group_reservations gr
    INNER JOIN guests g
        ON gr.contact_guest_id = g.guest_id
    LEFT JOIN group_reservation_rooms grr
        ON gr.group_id = grr.group_id
    WHERE 1=1
";

$params = [];
$types = "";

if ($status_filter !== "All") {
    $sql .= " AND gr.group_status = ?";
    $types .= "s";
    $params[] = $status_filter;
}

if ($search !== "") {

    $sql .= "
        AND (
            CAST(gr.group_id AS CHAR) LIKE ?
            OR gr.group_name LIKE ?
            OR g.first_name LIKE ?
            OR g.last_name LIKE ?
        )
    ";

    $search_term = "%" . $search . "%";

    $types .= "ssss";

    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

$sql .= "
    GROUP BY gr.group_id
    ORDER BY gr.created_at DESC
";

$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$groups = $stmt->get_result();

// GET GUESTS
$guests = $conn->query("
    SELECT guest_id, first_name, last_name
    FROM guests
    ORDER BY first_name ASC
");

// GET AVAILABLE ROOMS
$available_rooms = $conn->query("
    SELECT room_id, room_number, room_type, rate
    FROM rooms
    WHERE room_status = 'Available'
    ORDER BY room_number ASC
");
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Group Reservations Management</title>

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
            max-width: 1200px;
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

        input, select {
            width: 100%;
            padding: 11px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 14px;
        }

        .full-width {
            grid-column: 1 / -1;
        }

        .room-list {
            border: 1px solid #ddd;
            padding: 12px;
            border-radius: 6px;
            max-height: 220px;
            overflow-y: auto;
        }

        .room-option {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 0;
            border-bottom: 1px solid #eee;
            font-weight: normal;
        }

        .room-option input {
            width: auto;
        }

        button {
            padding: 11px 17px;
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
            min-width: 1050px;
        }

        th, td {
            padding: 13px;
            text-align: left;
            border-bottom: 1px solid #eee;
            font-size: 13px;
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

        .confirmed {
            background: #d4edda;
            color: #155724;
        }

        .cancelled {
            background: #f8d7da;
            color: #721c24;
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
            font-size: 13px;
            margin-bottom: 20px;
        }

        .status-form {
            display: flex;
            gap: 7px;
            min-width: 190px;
        }

        .status-form select {
            min-width: 110px;
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

    <h1>Group Reservations Management</h1>

    <p class="subtitle">
        Create and manage reservations for groups and organizations.
    </p>

    <?php if ($message !== ""): ?>

        <div class="message <?php echo $message_type; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>

    <?php endif; ?>

    <div class="note">
        Each selected room will receive its own individual reservation
        linked to the group. Adults and children are entered per room.
    </div>

    <!-- CREATE GROUP RESERVATION -->
    <div class="card">

        <h2>Create Group Reservation</h2>

        <form method="POST">

            <div class="form-grid">

                <div class="form-group full-width">

                    <label>Group Name / Organization</label>

                    <input
                        type="text"
                        name="group_name"
                        maxlength="150"
                        placeholder="Example: ABC Company Team"
                        required
                    >

                </div>

                <div class="form-group full-width">

                    <label>Main Guest / Contact Person</label>

                    <select name="contact_guest_id" required>

                        <option value="">Select Guest</option>

                        <?php while ($guest = $guests->fetch_assoc()): ?>

                            <option value="<?php echo $guest["guest_id"]; ?>">

                                <?php
                                echo htmlspecialchars(
                                    $guest["first_name"] . " " . $guest["last_name"]
                                );
                                ?>

                            </option>

                        <?php endwhile; ?>

                    </select>

                </div>

                <div class="form-group">

                    <label>Check-in Date</label>

                    <input
                        type="date"
                        name="check_in"
                        min="<?php echo date('Y-m-d'); ?>"
                        required
                    >

                </div>

                <div class="form-group">

                    <label>Check-out Date</label>

                    <input
                        type="date"
                        name="check_out"
                        min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>"
                        required
                    >

                </div>

                <div class="form-group">

                    <label>Adults Per Room</label>

                    <input
                        type="number"
                        name="adults_per_room"
                        min="1"
                        value="1"
                        required
                    >

                </div>

                <div class="form-group">

                    <label>Children Per Room</label>

                    <input
                        type="number"
                        name="children_per_room"
                        min="0"
                        value="0"
                        required
                    >

                </div>

                <div class="form-group full-width">

                    <label>Select Rooms</label>

                    <div class="room-list">

                        <?php if ($available_rooms && $available_rooms->num_rows > 0): ?>

                            <?php while ($room = $available_rooms->fetch_assoc()): ?>

                                <label class="room-option">

                                    <input
                                        type="checkbox"
                                        name="room_ids[]"
                                        value="<?php echo $room["room_id"]; ?>"
                                    >

                                    <span>
                                        Room <?php echo htmlspecialchars($room["room_number"]); ?>
                                        |
                                        <?php echo htmlspecialchars($room["room_type"]); ?>
                                        |
                                        ₱<?php echo number_format($room["rate"], 2); ?>
                                    </span>

                                </label>

                            <?php endwhile; ?>

                        <?php else: ?>

                            <p>No available rooms found.</p>

                        <?php endif; ?>

                    </div>

                </div>

                <div class="form-group full-width">

                    <button
                        type="submit"
                        name="create_group"
                        class="save-button"
                    >
                        Create Group Reservation
                    </button>

                </div>

            </div>

        </form>

    </div>

    <!-- GROUP RESERVATION RECORDS -->
    <div class="card">

        <h2>Group Reservation Records</h2>

        <form method="GET" class="filter-box">

            <input
                type="text"
                name="search"
                placeholder="Search group ID, group name, or contact person..."
                value="<?php echo htmlspecialchars($search); ?>"
            >

            <select name="status">

                <option value="All"
                    <?php echo $status_filter === "All" ? "selected" : ""; ?>>
                    All Statuses
                </option>

                <option value="Pending"
                    <?php echo $status_filter === "Pending" ? "selected" : ""; ?>>
                    Pending
                </option>

                <option value="Confirmed"
                    <?php echo $status_filter === "Confirmed" ? "selected" : ""; ?>>
                    Confirmed
                </option>

                <option value="Cancelled"
                    <?php echo $status_filter === "Cancelled" ? "selected" : ""; ?>>
                    Cancelled
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
                        <th>Group ID</th>
                        <th>Group Name</th>
                        <th>Contact Person</th>
                        <th>Check-in</th>
                        <th>Check-out</th>
                        <th>Rooms</th>
                        <th>Guests Per Room</th>
                        <th>Status</th>
                        <th>Created At</th>
                        <th>Action</th>
                    </tr>

                </thead>

                <tbody>

                    <?php if ($groups->num_rows > 0): ?>

                        <?php while ($row = $groups->fetch_assoc()): ?>

                            <tr>

                                <td>
                                    #<?php echo $row["group_id"]; ?>
                                </td>

                                <td>
                                    <?php echo htmlspecialchars($row["group_name"]); ?>
                                </td>

                                <td>
                                    <?php
                                    echo htmlspecialchars(
                                        $row["first_name"] . " " . $row["last_name"]
                                    );
                                    ?>
                                </td>

                                <td>
                                    <?php echo htmlspecialchars($row["check_in"]); ?>
                                </td>

                                <td>
                                    <?php echo htmlspecialchars($row["check_out"]); ?>
                                </td>

                                <td>
                                    <?php echo intval($row["total_rooms"]); ?>
                                </td>

                                <td>
                                    <?php
                                    echo intval($row["adults_per_room"]) .
                                         " Adults, " .
                                         intval($row["children_per_room"]) .
                                         " Children";
                                    ?>
                                </td>

                                <td>

                                    <span class="status <?php echo strtolower($row["group_status"]); ?>">
                                        <?php echo htmlspecialchars($row["group_status"]); ?>
                                    </span>

                                </td>

                                <td>
                                    <?php echo htmlspecialchars($row["created_at"]); ?>
                                </td>

                                <td>

                                    <?php if (in_array($row["group_status"], ["Pending", "Confirmed"])): ?>

                                        <form method="POST" class="status-form"
                                            onsubmit="return confirm('Update this group reservation status?');">

                                            <input
                                                type="hidden"
                                                name="group_id"
                                                value="<?php echo $row["group_id"]; ?>"
                                            >

                                            <select name="new_status" required>

                                                <?php if ($row["group_status"] === "Pending"): ?>

                                                    <option value="Pending">Pending</option>
                                                    <option value="Confirmed">Confirmed</option>
                                                    <option value="Cancelled">Cancelled</option>

                                                <?php else: ?>

                                                    <option value="Confirmed">Confirmed</option>
                                                    <option value="Cancelled">Cancelled</option>

                                                <?php endif; ?>

                                            </select>

                                            <button
                                                type="submit"
                                                name="update_group_status"
                                                class="update-button"
                                            >
                                                Update
                                            </button>

                                        </form>

                                    <?php else: ?>

                                        <span>Closed</span>

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="10" class="empty">
                                No group reservations found.
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
