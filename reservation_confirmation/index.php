
<?php
include "../config/database.php";

$message = "";
$message_type = "";

// CONFIRM RESERVATION
if (isset($_POST["confirm_reservation"])) {

    $reservation_id = intval($_POST["reservation_id"]);

    $conn->begin_transaction();

    try {

        // Lock and get reservation details
        $stmt = $conn->prepare("
            SELECT reservation_id, room_id, check_in, check_out,
                   reservation_status
            FROM reservations
            WHERE reservation_id = ?
            FOR UPDATE
        ");

        $stmt->bind_param("i", $reservation_id);
        $stmt->execute();

        $reservation = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$reservation) {
            throw new Exception("Reservation not found.");
        }

        if ($reservation["reservation_status"] !== "Pending") {
            throw new Exception("Only pending reservations can be confirmed.");
        }

        if (empty($reservation["room_id"])) {
            throw new Exception("Please assign a room before confirming this reservation.");
        }

        if ($reservation["check_in"] >= $reservation["check_out"]) {
            throw new Exception("Invalid reservation dates.");
        }

        // Check room status
        $room_id = intval($reservation["room_id"]);

        $stmt = $conn->prepare("
            SELECT room_status
            FROM rooms
            WHERE room_id = ?
            FOR UPDATE
        ");

        $stmt->bind_param("i", $room_id);
        $stmt->execute();

        $room = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$room) {
            throw new Exception("Assigned room does not exist.");
        }

        if (!in_array($room["room_status"], ["Available", "Reserved"])) {
            throw new Exception("The assigned room is not available for confirmation.");
        }

        // Check overlapping reservations
        $stmt = $conn->prepare("
            SELECT reservation_id
            FROM reservations
            WHERE room_id = ?
            AND reservation_id != ?
            AND reservation_status IN ('Pending', 'Confirmed', 'Checked-in')
            AND check_in < ?
            AND check_out > ?
            LIMIT 1
        ");

        $stmt->bind_param(
            "iiss",
            $room_id,
            $reservation_id,
            $reservation["check_out"],
            $reservation["check_in"]
        );

        $stmt->execute();
        $conflict = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($conflict) {
            throw new Exception("This room already has another reservation during the selected dates.");
        }

        // Confirm reservation
        $stmt = $conn->prepare("
            UPDATE reservations
            SET reservation_status = 'Confirmed'
            WHERE reservation_id = ?
        ");

        $stmt->bind_param("i", $reservation_id);
        $stmt->execute();
        $stmt->close();

        // Mark available room as reserved
        if ($room["room_status"] === "Available") {

            $stmt = $conn->prepare("
                UPDATE rooms
                SET room_status = 'Reserved'
                WHERE room_id = ?
            ");

            $stmt->bind_param("i", $room_id);
            $stmt->execute();
            $stmt->close();
        }

        $conn->commit();

        $message = "Reservation successfully confirmed!";
        $message_type = "success";

    } catch (Exception $e) {

        $conn->rollback();

        $message = $e->getMessage();
        $message_type = "error";
    }
}

// SEARCH AND FILTER
$search = trim($_GET["search"] ?? "");
$status_filter = $_GET["status"] ?? "Pending";

$allowed_statuses = ["Pending", "Confirmed", "All"];

if (!in_array($status_filter, $allowed_statuses)) {
    $status_filter = "Pending";
}

$sql = "
    SELECT
        r.reservation_id,
        r.check_in,
        r.check_out,
        r.adults,
        r.children,
        r.booking_source,
        r.reservation_status,
        r.deposit,
        g.first_name,
        g.last_name,
        rm.room_number,
        rm.room_type
    FROM reservations r
    INNER JOIN guests g ON r.guest_id = g.guest_id
    LEFT JOIN rooms rm ON r.room_id = rm.room_id
    WHERE 1=1
";

$params = [];
$types = "";

if ($status_filter !== "All") {
    $sql .= " AND r.reservation_status = ?";
    $types .= "s";
    $params[] = $status_filter;
} else {
    $sql .= " AND r.reservation_status IN ('Pending', 'Confirmed')";
}

if ($search !== "") {
    $sql .= "
        AND (
            CAST(r.reservation_id AS CHAR) LIKE ?
            OR g.first_name LIKE ?
            OR g.last_name LIKE ?
        )
    ";

    $search_term = "%" . $search . "%";

    $types .= "sss";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

$sql .= " ORDER BY r.created_at DESC";

$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reservation Confirmation</title>

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

        .filter-box {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        input, select {
            padding: 11px;
            border: 1px solid #ccc;
            border-radius: 5px;
        }

        input {
            flex: 1;
            min-width: 180px;
        }

        button, .filter-button {
            padding: 11px 16px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-size: 14px;
        }

        .search-button {
            background: #243447;
            color: white;
        }

        .confirm-button {
            background: #198754;
            color: white;
        }

        .confirm-button:hover {
            background: #146c43;
        }

        .table-container {
            background: white;
            padding: 20px;
            border-radius: 8px;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 950px;
        }

        th, td {
            padding: 13px;
            border-bottom: 1px solid #eee;
            text-align: left;
            font-size: 14px;
        }

        th {
            background: #243447;
            color: white;
        }

        .status {
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

        .no-room {
            color: #dc3545;
            font-weight: bold;
        }

        .empty {
            text-align: center;
            padding: 25px;
            color: #777;
        }

        @media (max-width: 600px) {
            body {
                padding: 12px;
            }

            .filter-box {
                flex-direction: column;
            }

            input, select, .filter-box button {
                width: 100%;
            }
        }
    </style>
</head>

<body>

<div class="container">

    <h1>Reservation Confirmation</h1>

    <p class="subtitle">
        Review pending reservations and confirm guest bookings.
    </p>

    <?php if ($message !== ""): ?>
        <div class="message <?php echo $message_type; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <form method="GET" class="filter-box">

        <input
            type="text"
            name="search"
            placeholder="Search reservation ID or guest name..."
            value="<?php echo htmlspecialchars($search); ?>"
        >

        <select name="status">
            <option value="Pending"
                <?php echo $status_filter === "Pending" ? "selected" : ""; ?>>
                Pending Reservations
            </option>

            <option value="Confirmed"
                <?php echo $status_filter === "Confirmed" ? "selected" : ""; ?>>
                Confirmed Reservations
            </option>

            <option value="All"
                <?php echo $status_filter === "All" ? "selected" : ""; ?>>
                All Pending and Confirmed
            </option>
        </select>

        <button type="submit" class="search-button">
            Search
        </button>

    </form>

    <div class="table-container">

        <h2>Reservation Records</h2>

        <table>

            <thead>
                <tr>
                    <th>Reservation ID</th>
                    <th>Guest Name</th>
                    <th>Room</th>
                    <th>Check-in</th>
                    <th>Check-out</th>
                    <th>Guests</th>
                    <th>Booking Source</th>
                    <th>Deposit</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>

            <tbody>

            <?php if ($result->num_rows > 0): ?>

                <?php while ($row = $result->fetch_assoc()): ?>

                    <tr>

                        <td>
                            #<?php echo $row["reservation_id"]; ?>
                        </td>

                        <td>
                            <?php
                            echo htmlspecialchars(
                                $row["first_name"] . " " . $row["last_name"]
                            );
                            ?>
                        </td>

                        <td>
                            <?php if ($row["room_number"] !== null): ?>
                                <?php echo htmlspecialchars($row["room_number"]); ?>
                                (<?php echo htmlspecialchars($row["room_type"]); ?>)
                            <?php else: ?>
                                <span class="no-room">Not Assigned</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php echo htmlspecialchars($row["check_in"]); ?>
                        </td>

                        <td>
                            <?php echo htmlspecialchars($row["check_out"]); ?>
                        </td>

                        <td>
                            <?php
                            echo intval($row["adults"]) + intval($row["children"]);
                            ?>
                        </td>

                        <td>
                            <?php echo htmlspecialchars($row["booking_source"]); ?>
                        </td>

                        <td>
                            ₱<?php echo number_format((float)$row["deposit"], 2); ?>
                        </td>

                        <td>
                            <span class="status <?php echo strtolower($row["reservation_status"]); ?>">
                                <?php echo htmlspecialchars($row["reservation_status"]); ?>
                            </span>
                        </td>

                        <td>

                            <?php if ($row["reservation_status"] === "Pending"): ?>

                                <?php if ($row["room_number"] !== null): ?>

                                    <form method="POST"
                                          onsubmit="return confirm('Are you sure you want to confirm this reservation?');">

                                        <input
                                            type="hidden"
                                            name="reservation_id"
                                            value="<?php echo $row["reservation_id"]; ?>"
                                        >

                                        <button
                                            type="submit"
                                            name="confirm_reservation"
                                            class="confirm-button"
                                        >
                                            Confirm
                                        </button>

                                    </form>

                                <?php else: ?>

                                    <span class="no-room">Assign a room first</span>

                                <?php endif; ?>

                            <?php else: ?>

                                <span>Already Confirmed</span>

                            <?php endif; ?>

                        </td>

                    </tr>

                <?php endwhile; ?>

            <?php else: ?>

                <tr>
                    <td colspan="10" class="empty">
                        No reservation records found.
                    </td>
                </tr>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>

</body>
</html>

<?php
$stmt->close();
$conn->close();
?>
