
<?php
require_once "../config/database.php";

$message = "";
$messageType = "";

// Assign or reassign a room
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["assign_room"])) {

    $reservationId = (int) ($_POST["reservation_id"] ?? 0);
    $roomId = (int) ($_POST["room_id"] ?? 0);

    // Get reservation details
    $stmt = $conn->prepare("
        SELECT reservation_id, room_id, check_in, check_out, reservation_status
        FROM reservations
        WHERE reservation_id = ?
    ");

    $stmt->bind_param("i", $reservationId);
    $stmt->execute();
    $reservation = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$reservation) {
        $message = "Reservation not found.";
        $messageType = "error";

    } elseif (!in_array($reservation["reservation_status"], ["Pending", "Confirmed"], true)) {
        $message = "Only Pending or Confirmed reservations can be assigned or reassigned.";
        $messageType = "error";

    } else {

        // Check whether the selected room exists and is operationally available
        $stmt = $conn->prepare("
            SELECT room_id, room_number, room_status
            FROM rooms
            WHERE room_id = ?
        ");

        $stmt->bind_param("i", $roomId);
        $stmt->execute();
        $room = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$room) {
            $message = "Selected room does not exist.";
            $messageType = "error";

        } elseif ($room["room_status"] !== "Available") {
            $message = "This room is not currently marked as Available.";
            $messageType = "error";

        } else {

            // Check for overlapping active reservations
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
                $roomId,
                $reservationId,
                $reservation["check_out"],
                $reservation["check_in"]
            );

            $stmt->execute();
            $conflict = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($conflict) {
                $message = "This room is already assigned to another reservation during those dates.";
                $messageType = "error";

            } else {

                // Update the reservation's room assignment
                $stmt = $conn->prepare("
                    UPDATE reservations
                    SET room_id = ?
                    WHERE reservation_id = ?
                ");

                $stmt->bind_param("ii", $roomId, $reservationId);

                if ($stmt->execute()) {
                    $message = "Room successfully assigned to the reservation.";
                    $messageType = "success";
                } else {
                    $message = "Unable to update the room assignment.";
                    $messageType = "error";
                }

                $stmt->close();
            }
        }
    }
}

// Get reservations that can receive a room assignment
$reservations = $conn->query("
    SELECT
        r.reservation_id,
        r.check_in,
        r.check_out,
        r.reservation_status,
        r.room_id,
        g.first_name,
        g.last_name,
        rm.room_number
    FROM reservations r
    INNER JOIN guests g ON r.guest_id = g.guest_id
    LEFT JOIN rooms rm ON r.room_id = rm.room_id
    WHERE r.reservation_status IN ('Pending', 'Confirmed')
    ORDER BY r.check_in ASC
");

// Get currently available rooms
$rooms = $conn->query("
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
    <title>Room Assignment</title>

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
            margin-bottom: 22px;
            box-shadow: 0 2px 8px #00000010;
        }

        .message {
            padding: 13px;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .success {
            background: #d1e7dd;
            color: #0f5132;
        }

        .error {
            background: #f8d7da;
            color: #842029;
        }

        label {
            display: block;
            font-weight: bold;
            margin-bottom: 7px;
        }

        select {
            width: 100%;
            padding: 11px;
            border: 1px solid #ccc;
            border-radius: 5px;
            margin-bottom: 15px;
        }

        button {
            background: #172b4d;
            color: white;
            border: none;
            padding: 11px 17px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
        }

        button:hover {
            background: #263f68;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 750px;
        }

        th, td {
            padding: 13px;
            border-bottom: 1px solid #eee;
            text-align: left;
        }

        th {
            background: #172b4d;
            color: white;
        }

        .status {
            font-weight: bold;
            color: #198754;
        }

        .empty {
            text-align: center;
            padding: 20px;
            color: #777;
        }

        .back {
            display: inline-block;
            margin-top: 5px;
            color: #0d6efd;
            text-decoration: none;
        }
    </style>
</head>

<body>

<div class="container">

    <h1>Room Assignment</h1>

    <p class="subtitle">
        Assign or reassign available rooms to guest reservations.
    </p>

    <?php if ($message !== ""): ?>
        <div class="message <?= $messageType ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2>Assign a Room</h2>

        <form method="POST">

            <label for="reservation_id">Select Reservation</label>

            <select name="reservation_id" id="reservation_id" required>
                <option value="">-- Select Reservation --</option>

                <?php while ($r = $reservations->fetch_assoc()): ?>

                    <option value="<?= (int) $r["reservation_id"] ?>">
                        Reservation #<?= (int) $r["reservation_id"] ?>
                        -
                        <?= htmlspecialchars($r["first_name"] . " " . $r["last_name"]) ?>
                        (<?= htmlspecialchars($r["check_in"]) ?> to
                        <?= htmlspecialchars($r["check_out"]) ?>)
                        -
                        <?= $r["room_number"]
                            ? "Current Room: " . htmlspecialchars($r["room_number"])
                            : "No Room Assigned" ?>
                    </option>

                <?php endwhile; ?>
            </select>

            <label for="room_id">Select Available Room</label>

            <select name="room_id" id="room_id" required>
                <option value="">-- Select Room --</option>

                <?php while ($room = $rooms->fetch_assoc()): ?>

                    <option value="<?= (int) $room["room_id"] ?>">
                        Room <?= htmlspecialchars($room["room_number"]) ?>
                        -
                        <?= htmlspecialchars($room["room_type"]) ?>
                        -
                        ₱<?= number_format((float) $room["rate"], 2) ?> per night
                    </option>

                <?php endwhile; ?>
            </select>

            <button type="submit" name="assign_room">
                Assign Room
            </button>

        </form>
    </div>

    <div class="card">

        <h2>Reservation Room Assignments</h2>

        <div class="table-wrapper">

            <table>
                <thead>
                    <tr>
                        <th>Reservation ID</th>
                        <th>Guest Name</th>
                        <th>Check-in</th>
                        <th>Check-out</th>
                        <th>Assigned Room</th>
                        <th>Reservation Status</th>
                    </tr>
                </thead>

                <tbody>

                <?php
                $reservations->data_seek(0);
                ?>

                <?php if ($reservations->num_rows > 0): ?>

                    <?php while ($r = $reservations->fetch_assoc()): ?>

                        <tr>
                            <td>
                                #<?= (int) $r["reservation_id"] ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $r["first_name"] . " " . $r["last_name"]
                                ) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($r["check_in"]) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($r["check_out"]) ?>
                            </td>

                            <td>
                                <?php if ($r["room_number"]): ?>
                                    Room <?= htmlspecialchars($r["room_number"]) ?>
                                <?php else: ?>
                                    <span style="color:#dc3545;">
                                        Not Assigned
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td class="status">
                                <?= htmlspecialchars($r["reservation_status"]) ?>
                            </td>
                        </tr>

                    <?php endwhile; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="6" class="empty">
                            No Pending or Confirmed reservations found.
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>
            </table>

        </div>
    </div>

    <a class="back" href="../index.php">
        ← Back to Dashboard
    </a>

</div>

</body>
</html>
