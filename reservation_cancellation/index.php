
<?php
include "../config/database.php";

$message = "";
$messageType = "";

// PROCESS RESERVATION CANCELLATION
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["cancel_reservation"])) {

    $reservation_id = intval($_POST["reservation_id"]);
    $reason = trim($_POST["cancellation_reason"]);

    if ($reservation_id <= 0 || $reason === "") {

        $message = "Please provide a valid reservation and cancellation reason.";
        $messageType = "error";

    } elseif (strlen($reason) > 255) {

        $message = "Cancellation reason must not exceed 255 characters.";
        $messageType = "error";

    } else {

        $conn->begin_transaction();

        try {

            // Lock reservation before cancellation
            $stmt = $conn->prepare(
                "SELECT reservation_id, room_id, reservation_status
                 FROM reservations
                 WHERE reservation_id = ?
                 FOR UPDATE"
            );

            $stmt->bind_param("i", $reservation_id);
            $stmt->execute();

            $reservation = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$reservation) {
                throw new Exception("Reservation not found.");
            }

            if (!in_array(
                $reservation["reservation_status"],
                ["Pending", "Confirmed"],
                true
            )) {
                throw new Exception(
                    "Only Pending or Confirmed reservations can be cancelled."
                );
            }

            $room_id = $reservation["room_id"];

            // Update reservation status and cancellation details
            $stmt = $conn->prepare(
                "UPDATE reservations
                 SET reservation_status = 'Cancelled',
                     cancellation_reason = ?,
                     cancelled_at = NOW()
                 WHERE reservation_id = ?"
            );

            $stmt->bind_param("si", $reason, $reservation_id);

            if (!$stmt->execute()) {
                throw new Exception("Unable to cancel reservation.");
            }

            $stmt->close();

            // Release the room if no other active reservations use it
            if ($room_id !== null) {

                $stmt = $conn->prepare(
                    "SELECT reservation_id
                     FROM reservations
                     WHERE room_id = ?
                     AND reservation_status IN
                         ('Pending', 'Confirmed', 'Checked-in')
                     LIMIT 1"
                );

                $stmt->bind_param("i", $room_id);
                $stmt->execute();

                $otherReservation = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$otherReservation) {

                    // Only change Reserved rooms.
                    // Do not overwrite Occupied, Cleaning, or Maintenance.
                    $stmt = $conn->prepare(
                        "UPDATE rooms
                         SET room_status = 'Available'
                         WHERE room_id = ?
                         AND room_status = 'Reserved'"
                    );

                    $stmt->bind_param("i", $room_id);

                    if (!$stmt->execute()) {
                        throw new Exception("Unable to release assigned room.");
                    }

                    $stmt->close();
                }
            }

            $conn->commit();

            header("Location: index.php?success=1");
            exit;

        } catch (Exception $e) {

            $conn->rollback();

            $message = $e->getMessage();
            $messageType = "error";
        }
    }
}

// SUCCESS MESSAGE
if (isset($_GET["success"])) {
    $message = "Reservation cancelled successfully!";
    $messageType = "success";
}

// SEARCH RESERVATIONS
$search = trim($_GET["search"] ?? "");
$statusFilter = $_GET["status"] ?? "";

$allowedStatuses = [
    "Pending",
    "Confirmed",
    "Checked-in",
    "Checked-out",
    "Cancelled",
    "No-show"
];

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = "";
}

$searchTerm = "%" . $search . "%";

$sql = "
    SELECT
        r.reservation_id,
        r.check_in,
        r.check_out,
        r.reservation_status,
        r.deposit,
        r.cancellation_reason,
        r.cancelled_at,
        g.first_name,
        g.last_name,
        rm.room_number
    FROM reservations r
    JOIN guests g ON r.guest_id = g.guest_id
    LEFT JOIN rooms rm ON r.room_id = rm.room_id
    WHERE (
        CAST(r.reservation_id AS CHAR) LIKE ?
        OR g.first_name LIKE ?
        OR g.last_name LIKE ?
    )
";

if ($statusFilter !== "") {
    $sql .= " AND r.reservation_status = ?";
}

$sql .= " ORDER BY r.reservation_id DESC";

$stmt = $conn->prepare($sql);

if ($statusFilter !== "") {
    $stmt->bind_param(
        "ssss",
        $searchTerm,
        $searchTerm,
        $searchTerm,
        $statusFilter
    );
} else {
    $stmt->bind_param(
        "sss",
        $searchTerm,
        $searchTerm,
        $searchTerm
    );
}

$stmt->execute();
$reservations = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Reservation Cancellation Management</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f4f6f9;
            margin: 0;
            padding: 25px;
        }

        .container {
            max-width: 1200px;
            margin: auto;
        }

        h1 {
            color: #263445;
        }

        .card {
            background: white;
            padding: 22px;
            border-radius: 8px;
            margin-bottom: 22px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }

        input, select, button {
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
            box-sizing: border-box;
        }

        input, select {
            width: 100%;
            margin-top: 6px;
        }

        button {
            background: #263f5b;
            color: white;
            border: none;
            cursor: pointer;
            padding: 11px 18px;
            margin-top: 10px;
        }

        button:hover {
            background: #172a40;
        }

        .cancel-button {
            background: #b42318;
        }

        .cancel-button:hover {
            background: #861b13;
        }

        .success {
            background: #d4edda;
            color: #155724;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 15px;
        }

        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 15px;
        }

        .filters {
            display: grid;
            grid-template-columns: 2fr 1fr auto;
            gap: 12px;
            align-items: end;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        th, td {
            padding: 12px;
            border-bottom: 1px solid #ddd;
            text-align: left;
        }

        th {
            background: #263f5b;
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
            background: #d1ecf1;
            color: #0c5460;
        }

        .cancelled {
            background: #f8d7da;
            color: #721c24;
        }

        .checked-in {
            background: #d4edda;
            color: #155724;
        }

        .checked-out {
            background: #e2e3e5;
            color: #383d41;
        }

        .no-show {
            background: #e2e3e5;
            color: #383d41;
        }

        .action-link {
            color: #1769aa;
            text-decoration: none;
            font-weight: bold;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .details {
            background: #eef3f8;
            padding: 15px;
            border-radius: 6px;
            margin: 15px 0;
        }

        @media (max-width: 700px) {
            .filters {
                grid-template-columns: 1fr;
            }

            .card {
                overflow-x: auto;
            }

            table {
                min-width: 850px;
            }
        }

        @media print {
            .no-print {
                display: none;
            }

            body {
                background: white;
                padding: 0;
            }

            .card {
                box-shadow: none;
            }
        }
    </style>
</head>

<body>

<div class="container">

    <h1>Reservation Cancellation Management</h1>

    <p>
        Search reservations, process cancellations, and view cancellation records.
    </p>

    <?php if ($message !== ""): ?>
        <div class="<?php echo htmlspecialchars($messageType); ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>


    <!-- SEARCH AND FILTER -->

    <div class="card no-print">

        <h2>Search Reservations</h2>

        <form method="GET">

            <div class="filters">

                <div>
                    <label>Search Reservation or Guest</label>

                    <input
                        type="text"
                        name="search"
                        placeholder="Enter reservation ID or guest name"
                        value="<?php echo htmlspecialchars($search); ?>"
                    >
                </div>

                <div>
                    <label>Reservation Status</label>

                    <select name="status">

                        <option value="">All Statuses</option>

                        <?php foreach ($allowedStatuses as $status): ?>

                            <option
                                value="<?php echo htmlspecialchars($status); ?>"
                                <?php echo ($statusFilter === $status) ? "selected" : ""; ?>
                            >
                                <?php echo htmlspecialchars($status); ?>
                            </option>

                        <?php endforeach; ?>

                    </select>
                </div>

                <div>
                    <button type="submit">Search</button>
                </div>

            </div>

        </form>

    </div>


    <!-- RESERVATION LIST -->

    <div class="card">

        <h2>Reservation Records</h2>

        <table>

            <thead>
                <tr>
                    <th>Reservation ID</th>
                    <th>Guest Name</th>
                    <th>Room</th>
                    <th>Check-in</th>
                    <th>Check-out</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>

            <tbody>

            <?php if ($reservations && $reservations->num_rows > 0): ?>

                <?php while ($row = $reservations->fetch_assoc()): ?>

                    <tr>

                        <td><?php echo $row["reservation_id"]; ?></td>

                        <td>
                            <?php echo htmlspecialchars(
                                $row["first_name"] . " " . $row["last_name"]
                            ); ?>
                        </td>

                        <td>
                            <?php echo htmlspecialchars(
                                $row["room_number"] ?? "Not assigned"
                            ); ?>
                        </td>

                        <td><?php echo htmlspecialchars($row["check_in"]); ?></td>

                        <td><?php echo htmlspecialchars($row["check_out"]); ?></td>

                        <td>
                            <span class="status <?php
                                echo strtolower(str_replace(" ", "-", $row["reservation_status"]));
                            ?>">
                                <?php echo htmlspecialchars($row["reservation_status"]); ?>
                            </span>
                        </td>

                        <td>

                            <?php if (in_array(
                                $row["reservation_status"],
                                ["Pending", "Confirmed"],
                                true
                            )): ?>

                                <a
                                    class="action-link"
                                    href="index.php?cancel_id=<?php echo $row['reservation_id']; ?>"
                                >
                                    Cancel Reservation
                                </a>

                            <?php elseif ($row["reservation_status"] === "Cancelled"): ?>

                                <span>Cancelled</span>

                            <?php else: ?>

                                <span>Not Allowed</span>

                            <?php endif; ?>

                        </td>

                    </tr>

                <?php endwhile; ?>

            <?php else: ?>

                <tr>
                    <td colspan="7">No reservations found.</td>
                </tr>

            <?php endif; ?>

            </tbody>

        </table>

        <br>

        <button class="no-print" onclick="window.print()">
            Print Reservation Records
        </button>

    </div>


    <!-- CANCELLATION FORM -->

    <?php

    $cancelReservation = null;

    if (isset($_GET["cancel_id"])) {

        $cancel_id = intval($_GET["cancel_id"]);

        $stmt = $conn->prepare(
            "SELECT
                r.reservation_id,
                r.check_in,
                r.check_out,
                r.reservation_status,
                r.deposit,
                g.first_name,
                g.last_name,
                rm.room_number
             FROM reservations r
             JOIN guests g ON r.guest_id = g.guest_id
             LEFT JOIN rooms rm ON r.room_id = rm.room_id
             WHERE r.reservation_id = ?"
        );

        $stmt->bind_param("i", $cancel_id);
        $stmt->execute();

        $cancelReservation = $stmt->get_result()->fetch_assoc();

        $stmt->close();
    }

    ?>

    <?php if ($cancelReservation): ?>

        <div class="card no-print">

            <h2>Cancel Reservation</h2>

            <div class="details">

                <p>
                    <strong>Reservation ID:</strong>
                    <?php echo $cancelReservation["reservation_id"]; ?>
                </p>

                <p>
                    <strong>Guest Name:</strong>
                    <?php echo htmlspecialchars(
                        $cancelReservation["first_name"] . " " .
                        $cancelReservation["last_name"]
                    ); ?>
                </p>

                <p>
                    <strong>Room Number:</strong>
                    <?php echo htmlspecialchars(
                        $cancelReservation["room_number"] ?? "Not assigned"
                    ); ?>
                </p>

                <p>
                    <strong>Check-in:</strong>
                    <?php echo htmlspecialchars($cancelReservation["check_in"]); ?>
                </p>

                <p>
                    <strong>Check-out:</strong>
                    <?php echo htmlspecialchars($cancelReservation["check_out"]); ?>
                </p>

                <p>
                    <strong>Current Status:</strong>
                    <?php echo htmlspecialchars($cancelReservation["reservation_status"]); ?>
                </p>

            </div>

            <?php if (in_array(
                $cancelReservation["reservation_status"],
                ["Pending", "Confirmed"],
                true
            )): ?>

                <form method="POST">

                    <input
                        type="hidden"
                        name="reservation_id"
                        value="<?php echo $cancelReservation["reservation_id"]; ?>"
                    >

                    <div class="form-group">

                        <label>Reason for Cancellation</label>

                        <input
                            type="text"
                            name="cancellation_reason"
                            maxlength="255"
                            placeholder="Enter the reason for cancellation"
                            required
                        >

                    </div>

                    <button
                        type="submit"
                        name="cancel_reservation"
                        class="cancel-button"
                        onclick="return confirm('Are you sure you want to cancel this reservation?');"
                    >
                        Confirm Cancellation
                    </button>

                </form>

            <?php else: ?>

                <p>
                    This reservation cannot be cancelled because its current
                    status is <?php echo htmlspecialchars($cancelReservation["reservation_status"]); ?>.
                </p>

            <?php endif; ?>

        </div>

    <?php endif; ?>


    <!-- CANCELLATION HISTORY -->

    <div class="card">

        <h2>Cancellation History</h2>

        <table>

            <thead>
                <tr>
                    <th>Reservation ID</th>
                    <th>Guest Name</th>
                    <th>Room</th>
                    <th>Cancellation Reason</th>
                    <th>Cancellation Date</th>
                </tr>
            </thead>

            <tbody>

            <?php

            $history = $conn->query(
                "SELECT
                    r.reservation_id,
                    r.cancellation_reason,
                    r.cancelled_at,
                    g.first_name,
                    g.last_name,
                    rm.room_number
                 FROM reservations r
                 JOIN guests g ON r.guest_id = g.guest_id
                 LEFT JOIN rooms rm ON r.room_id = rm.room_id
                 WHERE r.reservation_status = 'Cancelled'
                 ORDER BY r.cancelled_at DESC, r.reservation_id DESC"
            );

            ?>

            <?php if ($history && $history->num_rows > 0): ?>

                <?php while ($cancelled = $history->fetch_assoc()): ?>

                    <tr>

                        <td><?php echo $cancelled["reservation_id"]; ?></td>

                        <td>
                            <?php echo htmlspecialchars(
                                $cancelled["first_name"] . " " .
                                $cancelled["last_name"]
                            ); ?>
                        </td>

                        <td>
                            <?php echo htmlspecialchars(
                                $cancelled["room_number"] ?? "Not assigned"
                            ); ?>
                        </td>

                        <td>
                            <?php echo htmlspecialchars(
                                $cancelled["cancellation_reason"] ?? "Not recorded"
                            ); ?>
                        </td>

                        <td>
                            <?php echo htmlspecialchars(
                                $cancelled["cancelled_at"] ?? "Not recorded"
                            ); ?>
                        </td>

                    </tr>

                <?php endwhile; ?>

            <?php else: ?>

                <tr>
                    <td colspan="5">No cancellation records found.</td>
                </tr>

            <?php endif; ?>

            </tbody>

        </table>

        <br>

        <button class="no-print" onclick="window.print()">
            Print Cancellation History
        </button>

    </div>

</div>

</body>
</html>

<?php
$stmt->close();
?>
