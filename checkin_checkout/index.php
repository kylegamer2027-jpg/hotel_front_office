
<?php
require_once "../config/database.php";

$message = "";
$messageType = "success";

// Process check-in or check-out
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $reservation_id = filter_input(
        INPUT_POST,
        "reservation_id",
        FILTER_VALIDATE_INT
    );

    $action = $_POST["action"] ?? "";

    if (!$reservation_id || !in_array($action, ["checkin", "checkout"], true)) {
        $message = "Invalid reservation or action.";
        $messageType = "error";
    } else {
        $conn->begin_transaction();

        try {
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

            $room_id = $reservation["room_id"];
            $status = $reservation["reservation_status"];

            if (!$room_id) {
                throw new Exception(
                    "Assign a room to this reservation before proceeding."
                );
            }

            // CHECK-IN
            if ($action === "checkin") {
                if (!in_array($status, ["Confirmed"], true)) {
                    throw new Exception(
                        "Only confirmed reservations can be checked in."
                    );
                }

                $today = date("Y-m-d");

                if ($reservation["check_in"] > $today) {
                    throw new Exception(
                        "The check-in date has not arrived yet."
                    );
                }

                if ($reservation["check_out"] <= $today) {
                    throw new Exception(
                        "This reservation's check-out date has passed."
                    );
                }

                // Lock and check room status
                $stmt = $conn->prepare(
                    "SELECT room_status FROM rooms
                     WHERE room_id = ? FOR UPDATE"
                );
                $stmt->bind_param("i", $room_id);
                $stmt->execute();
                $room = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$room || $room["room_status"] !== "Available") {
                    throw new Exception(
                        "The assigned room is not available for check-in."
                    );
                }

                // Check for another active reservation for this room
                $stmt = $conn->prepare(
                    "SELECT reservation_id
                     FROM reservations
                     WHERE room_id = ?
                       AND reservation_id <> ?
                       AND reservation_status IN ('Confirmed', 'Checked-in')
                       AND check_in < ?
                       AND check_out > ?
                     LIMIT 1"
                );
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
                    throw new Exception(
                        "Another active reservation conflicts with these dates."
                    );
                }

                $stmt = $conn->prepare(
                    "UPDATE reservations
                     SET reservation_status = 'Checked-in'
                     WHERE reservation_id = ?"
                );
                $stmt->bind_param("i", $reservation_id);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare(
                    "UPDATE rooms SET room_status = 'Occupied'
                     WHERE room_id = ?"
                );
                $stmt->bind_param("i", $room_id);
                $stmt->execute();
                $stmt->close();

                $message = "Guest checked in successfully.";
            }

            // CHECK-OUT
            if ($action === "checkout") {
                if ($status !== "Checked-in") {
                    throw new Exception(
                        "Only checked-in guests can be checked out."
                    );
                }

                $stmt = $conn->prepare(
                    "SELECT room_status FROM rooms
                     WHERE room_id = ? FOR UPDATE"
                );
                $stmt->bind_param("i", $room_id);
                $stmt->execute();
                $room = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$room || $room["room_status"] !== "Occupied") {
                    throw new Exception(
                        "The assigned room is not marked as occupied."
                    );
                }

                $stmt = $conn->prepare(
                    "UPDATE reservations
                     SET reservation_status = 'Checked-out'
                     WHERE reservation_id = ?"
                );
                $stmt->bind_param("i", $reservation_id);
                $stmt->execute();
                $stmt->close();

                // Send the room to housekeeping after checkout
                $stmt = $conn->prepare(
                    "UPDATE rooms SET room_status = 'Cleaning'
                     WHERE room_id = ?"
                );
                $stmt->bind_param("i", $room_id);
                $stmt->execute();
                $stmt->close();

                $message = "Guest checked out. Room status is now Cleaning.";
            }

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            $message = $e->getMessage();
            $messageType = "error";
        }
    }
}

// Get reservations for the table
$sql = "
    SELECT
        r.reservation_id,
        r.check_in,
        r.check_out,
        r.reservation_status,
        g.first_name,
        g.last_name,
        rm.room_number,
        rm.room_type,
        rm.room_status
    FROM reservations r
    INNER JOIN guests g ON r.guest_id = g.guest_id
    LEFT JOIN rooms rm ON r.room_id = rm.room_id
    ORDER BY r.reservation_id DESC
";

$reservations = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Check-in and Check-out Management</title>

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

        .description {
            color: #666;
            margin-bottom: 25px;
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

        .table-wrapper {
            overflow-x: auto;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px #00000010;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 900px;
        }

        th, td {
            padding: 13px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        th {
            background: #172b4d;
            color: white;
        }

        tr:hover td {
            background: #f8fafc;
        }

        .status {
            font-weight: bold;
        }

        form {
            display: inline-block;
            margin: 2px;
        }

        button {
            padding: 8px 11px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            color: white;
            font-weight: bold;
        }

        .checkin {
            background: #198754;
        }

        .checkout {
            background: #dc3545;
        }

        button:disabled {
            background: #aaa;
            cursor: not-allowed;
        }

        .back {
            display: inline-block;
            margin-top: 20px;
            color: #0d6efd;
            text-decoration: none;
        }
    </style>
</head>

<body>
<div class="container">
    <h1>Check-in and Check-out Management</h1>

    <p class="description">
        Manage guest arrivals and departures and update room status.
    </p>

    <?php if ($message !== ""): ?>
        <div class="message <?= $messageType === "error" ? "error" : "success" ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Reservation ID</th>
                    <th>Guest Name</th>
                    <th>Room</th>
                    <th>Check-in Date</th>
                    <th>Check-out Date</th>
                    <th>Reservation Status</th>
                    <th>Room Status</th>
                    <th>Action</th>
                </tr>
            </thead>

            <tbody>
            <?php if ($reservations && $reservations->num_rows > 0): ?>
                <?php while ($row = $reservations->fetch_assoc()): ?>
                    <tr>
                        <td>
                            <?= (int) $row["reservation_id"] ?>
                        </td>

                        <td>
                            <?= htmlspecialchars(
                                $row["first_name"] . " " . $row["last_name"]
                            ) ?>
                        </td>

                        <td>
                            <?php if ($row["room_number"] !== null): ?>
                                <?= htmlspecialchars($row["room_number"]) ?>
                                (<?= htmlspecialchars($row["room_type"]) ?>)
                            <?php else: ?>
                                Not assigned
                            <?php endif; ?>
                        </td>

                        <td><?= htmlspecialchars($row["check_in"]) ?></td>
                        <td><?= htmlspecialchars($row["check_out"]) ?></td>

                        <td class="status">
                            <?= htmlspecialchars($row["reservation_status"]) ?>
                        </td>

                        <td>
                            <?= htmlspecialchars($row["room_status"] ?? "N/A") ?>
                        </td>

                        <td>
                            <?php if (
                                $row["reservation_status"] === "Confirmed"
                            ): ?>
                                <form method="POST"
                                      onsubmit="return confirm('Check in this guest?');">
                                    <input type="hidden" name="reservation_id"
                                           value="<?= (int) $row["reservation_id"] ?>">
                                    <input type="hidden" name="action"
                                           value="checkin">
                                    <button class="checkin" type="submit">
                                        Check In
                                    </button>
                                </form>

                            <?php elseif (
                                $row["reservation_status"] === "Checked-in"
                            ): ?>
                                <form method="POST"
                                      onsubmit="return confirm('Check out this guest?');">
                                    <input type="hidden" name="reservation_id"
                                           value="<?= (int) $row["reservation_id"] ?>">
                                    <input type="hidden" name="action"
                                           value="checkout">
                                    <button class="checkout" type="submit">
                                        Check Out
                                    </button>
                                </form>

                            <?php else: ?>
                                No action
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>

            <?php else: ?>
                <tr>
                    <td colspan="8">No reservations found.</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <a class="back" href="../index.php">← Back to Dashboard</a>
</div>
</body>
</html>
