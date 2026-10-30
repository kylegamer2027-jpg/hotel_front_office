
<?php
include "../config/database.php";

$message = "";
$messageType = "";

$selectedReservation = isset($_GET["reservation_id"])
    ? intval($_GET["reservation_id"])
    : 0;

// PROCESS ROOM TRANSFER
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["transfer_room"])) {

    $reservation_id = intval($_POST["reservation_id"]);
    $new_room_id = intval($_POST["new_room_id"]);
    $reason = trim($_POST["transfer_reason"]);

    if ($reservation_id <= 0 || $new_room_id <= 0 || $reason === "") {
        $message = "Please complete all required fields.";
        $messageType = "error";
        $selectedReservation = $reservation_id;
    } else {

        $conn->begin_transaction();

        try {

            // Lock and retrieve the reservation
            $stmt = $conn->prepare(
                "SELECT reservation_id, room_id, check_in, check_out,
                        reservation_status
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

            if ($reservation["reservation_status"] !== "Checked-in") {
                throw new Exception(
                    "Only checked-in reservations can be transferred."
                );
            }

            $old_room_id = intval($reservation["room_id"]);

            if ($old_room_id <= 0) {
                throw new Exception("The reservation has no assigned room.");
            }

            if ($old_room_id === $new_room_id) {
                throw new Exception(
                    "Please select a different room."
                );
            }

            // Lock the old room
            $stmt = $conn->prepare(
                "SELECT room_status
                 FROM rooms
                 WHERE room_id = ?
                 FOR UPDATE"
            );

            $stmt->bind_param("i", $old_room_id);
            $stmt->execute();
            $oldRoom = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$oldRoom) {
                throw new Exception("Current room not found.");
            }

            // Lock the new room
            $stmt = $conn->prepare(
                "SELECT room_status
                 FROM rooms
                 WHERE room_id = ?
                 FOR UPDATE"
            );

            $stmt->bind_param("i", $new_room_id);
            $stmt->execute();
            $newRoom = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$newRoom) {
                throw new Exception("Selected room does not exist.");
            }

            if ($newRoom["room_status"] !== "Available") {
                throw new Exception(
                    "The selected room is not available."
                );
            }

            // Check for overlapping active reservations
            $stmt = $conn->prepare(
                "SELECT reservation_id
                 FROM reservations
                 WHERE room_id = ?
                 AND reservation_id <> ?
                 AND reservation_status IN
                    ('Pending', 'Confirmed', 'Checked-in')
                 AND check_in < ?
                 AND check_out > ?
                 LIMIT 1"
            );

            $stmt->bind_param(
                "iiss",
                $new_room_id,
                $reservation_id,
                $reservation["check_out"],
                $reservation["check_in"]
            );

            $stmt->execute();
            $conflict = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($conflict) {
                throw new Exception(
                    "The selected room has another overlapping reservation."
                );
            }

            // Update reservation's assigned room
            $stmt = $conn->prepare(
                "UPDATE reservations
                 SET room_id = ?
                 WHERE reservation_id = ?"
            );

            $stmt->bind_param(
                "ii",
                $new_room_id,
                $reservation_id
            );

            if (!$stmt->execute()) {
                throw new Exception("Unable to update reservation.");
            }

            $stmt->close();

            // Set old room to Cleaning
            $stmt = $conn->prepare(
                "UPDATE rooms
                 SET room_status = 'Cleaning'
                 WHERE room_id = ?"
            );

            $stmt->bind_param("i", $old_room_id);

            if (!$stmt->execute()) {
                throw new Exception("Unable to update old room status.");
            }

            $stmt->close();

            // Set new room to Occupied
            $stmt = $conn->prepare(
                "UPDATE rooms
                 SET room_status = 'Occupied'
                 WHERE room_id = ?"
            );

            $stmt->bind_param("i", $new_room_id);

            if (!$stmt->execute()) {
                throw new Exception("Unable to update new room status.");
            }

            $stmt->close();

            // Record transfer history
            $stmt = $conn->prepare(
                "INSERT INTO room_transfers
                (reservation_id, old_room_id, new_room_id, transfer_reason)
                VALUES (?, ?, ?, ?)"
            );

            $stmt->bind_param(
                "iiis",
                $reservation_id,
                $old_room_id,
                $new_room_id,
                $reason
            );

            if (!$stmt->execute()) {
                throw new Exception("Unable to save transfer history.");
            }

            $stmt->close();

            $conn->commit();

            header(
                "Location: index.php?reservation_id=" .
                $reservation_id . "&success=1"
            );
            exit;

        } catch (Exception $e) {

            $conn->rollback();

            $message = $e->getMessage();
            $messageType = "error";
            $selectedReservation = $reservation_id;
        }
    }
}

// SUCCESS MESSAGE
if (isset($_GET["success"])) {
    $message = "Room transfer completed successfully!";
    $messageType = "success";
}

// GET CHECKED-IN RESERVATIONS
$reservations = $conn->query(
    "SELECT
        r.reservation_id,
        r.room_id,
        r.check_in,
        r.check_out,
        g.first_name,
        g.last_name,
        rm.room_number
     FROM reservations r
     JOIN guests g ON r.guest_id = g.guest_id
     LEFT JOIN rooms rm ON r.room_id = rm.room_id
     WHERE r.reservation_status = 'Checked-in'
     ORDER BY r.reservation_id DESC"
);

// GET SELECTED RESERVATION
$selected = null;

if ($selectedReservation > 0) {

    $stmt = $conn->prepare(
        "SELECT
            r.reservation_id,
            r.room_id,
            r.check_in,
            r.check_out,
            r.reservation_status,
            g.first_name,
            g.last_name,
            rm.room_number,
            rm.room_type
         FROM reservations r
         JOIN guests g ON r.guest_id = g.guest_id
         LEFT JOIN rooms rm ON r.room_id = rm.room_id
         WHERE r.reservation_id = ?
         AND r.reservation_status = 'Checked-in'"
    );

    $stmt->bind_param("i", $selectedReservation);
    $stmt->execute();

    $selected = $stmt->get_result()->fetch_assoc();

    $stmt->close();
}

// GET AVAILABLE ROOMS
$availableRooms = $conn->query(
    "SELECT room_id, room_number, room_type, rate
     FROM rooms
     WHERE room_status = 'Available'
     ORDER BY room_number ASC"
);

// GET TRANSFER HISTORY
$history = $conn->query(
    "SELECT
        t.transfer_id,
        t.reservation_id,
        t.transfer_reason,
        t.transfer_date,
        g.first_name,
        g.last_name,
        old_room.room_number AS old_room_number,
        new_room.room_number AS new_room_number
     FROM room_transfers t
     JOIN reservations r ON t.reservation_id = r.reservation_id
     JOIN guests g ON r.guest_id = g.guest_id
     JOIN rooms old_room ON t.old_room_id = old_room.room_id
     JOIN rooms new_room ON t.new_room_id = new_room.room_id
     ORDER BY t.transfer_date DESC, t.transfer_id DESC"
);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Room Transfer Management</title>

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
            margin: 6px 0;
            border: 1px solid #ccc;
            border-radius: 5px;
            box-sizing: border-box;
        }

        input, select {
            width: 100%;
        }

        button {
            background: #263f5b;
            color: white;
            border: none;
            cursor: pointer;
            padding: 11px 18px;
        }

        button:hover {
            background: #172a40;
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

        .info {
            background: #eef3f8;
            padding: 15px;
            border-radius: 6px;
            margin: 15px 0;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 15px;
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

        .select-link {
            color: #1769aa;
            text-decoration: none;
            font-weight: bold;
        }

        @media (max-width: 700px) {
            .card {
                overflow-x: auto;
            }

            table {
                min-width: 650px;
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

    <h1>Room Transfer Management</h1>

    <p>
        Transfer checked-in guests to another available room
        and maintain transfer records.
    </p>

    <?php if ($message !== ""): ?>
        <div class="<?php echo htmlspecialchars($messageType); ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>


    <!-- CHECKED-IN RESERVATIONS -->

    <div class="card no-print">

        <h2>Checked-in Guests</h2>

        <table>
            <thead>
                <tr>
                    <th>Reservation ID</th>
                    <th>Guest Name</th>
                    <th>Current Room</th>
                    <th>Check-in</th>
                    <th>Check-out</th>
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
                            <a class="select-link"
                               href="index.php?reservation_id=<?php echo $row['reservation_id']; ?>">
                                Transfer Room
                            </a>
                        </td>
                    </tr>

                <?php endwhile; ?>

            <?php else: ?>

                <tr>
                    <td colspan="6">No checked-in guests found.</td>
                </tr>

            <?php endif; ?>

            </tbody>
        </table>

    </div>


    <!-- SELECTED GUEST AND TRANSFER FORM -->

    <?php if ($selected): ?>

        <div class="card">

            <h2>Transfer Guest Room</h2>

            <div class="info">

                <p>
                    <strong>Reservation ID:</strong>
                    <?php echo $selected["reservation_id"]; ?>
                </p>

                <p>
                    <strong>Guest Name:</strong>
                    <?php echo htmlspecialchars(
                        $selected["first_name"] . " " . $selected["last_name"]
                    ); ?>
                </p>

                <p>
                    <strong>Current Room:</strong>
                    <?php echo htmlspecialchars(
                        $selected["room_number"] ?? "Not assigned"
                    ); ?>
                </p>

                <p>
                    <strong>Room Type:</strong>
                    <?php echo htmlspecialchars(
                        $selected["room_type"] ?? "N/A"
                    ); ?>
                </p>

            </div>

            <form method="POST">

                <input
                    type="hidden"
                    name="reservation_id"
                    value="<?php echo $selected["reservation_id"]; ?>"
                >

                <div class="form-grid">

                    <div>
                        <label>New Room</label>

                        <select name="new_room_id" required>

                            <option value="">Select an available room</option>

                            <?php if ($availableRooms): ?>

                                <?php while ($room = $availableRooms->fetch_assoc()): ?>

                                    <option value="<?php echo $room["room_id"]; ?>">

                                        <?php
                                        echo "Room " .
                                            htmlspecialchars($room["room_number"]) .
                                            " - " .
                                            htmlspecialchars($room["room_type"]) .
                                            " (₱" .
                                            number_format($room["rate"], 2) .
                                            ")";
                                        ?>

                                    </option>

                                <?php endwhile; ?>

                            <?php endif; ?>

                        </select>
                    </div>

                    <div>
                        <label>Reason for Transfer</label>

                        <input
                            type="text"
                            name="transfer_reason"
                            maxlength="255"
                            placeholder="Example: Guest requested room change"
                            required
                        >
                    </div>

                </div>

                <button type="submit" name="transfer_room">
                    Confirm Room Transfer
                </button>

            </form>

        </div>

    <?php elseif ($selectedReservation > 0): ?>

        <div class="card">
            Reservation not found or the guest is not currently checked in.
        </div>

    <?php endif; ?>


    <!-- ROOM TRANSFER HISTORY -->

    <div class="card">

        <h2>Room Transfer History</h2>

        <table>

            <thead>
                <tr>
                    <th>Transfer ID</th>
                    <th>Reservation ID</th>
                    <th>Guest Name</th>
                    <th>Previous Room</th>
                    <th>New Room</th>
                    <th>Reason</th>
                    <th>Transfer Date</th>
                </tr>
            </thead>

            <tbody>

            <?php if ($history && $history->num_rows > 0): ?>

                <?php while ($transfer = $history->fetch_assoc()): ?>

                    <tr>

                        <td><?php echo $transfer["transfer_id"]; ?></td>

                        <td><?php echo $transfer["reservation_id"]; ?></td>

                        <td>
                            <?php echo htmlspecialchars(
                                $transfer["first_name"] . " " .
                                $transfer["last_name"]
                            ); ?>
                        </td>

                        <td>
                            <?php echo htmlspecialchars(
                                $transfer["old_room_number"]
                            ); ?>
                        </td>

                        <td>
                            <?php echo htmlspecialchars(
                                $transfer["new_room_number"]
                            ); ?>
                        </td>

                        <td>
                            <?php echo htmlspecialchars(
                                $transfer["transfer_reason"]
                            ); ?>
                        </td>

                        <td>
                            <?php echo htmlspecialchars(
                                $transfer["transfer_date"]
                            ); ?>
                        </td>

                    </tr>

                <?php endwhile; ?>

            <?php else: ?>

                <tr>
                    <td colspan="7">No room transfers recorded yet.</td>
                </tr>

            <?php endif; ?>

            </tbody>

        </table>

        <br>

        <button class="no-print" onclick="window.print()">
            Print Transfer History
        </button>

    </div>

</div>

</body>
</html>
