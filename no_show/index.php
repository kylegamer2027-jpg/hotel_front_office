
<?php
require_once "../config/database.php";

$message = "";
$messageType = "";

$today = date("Y-m-d");

// Process no-show action
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["mark_no_show"])) {

    $reservationId = (int) ($_POST["reservation_id"] ?? 0);

    if ($reservationId <= 0) {

        $message = "Invalid reservation selected.";
        $messageType = "error";

    } else {

        // Only confirmed reservations whose check-in date has passed
        // or is today can be marked as no-show.
        $stmt = $conn->prepare("
            UPDATE reservations
            SET reservation_status = 'No-show'
            WHERE reservation_id = ?
              AND reservation_status = 'Confirmed'
              AND check_in <= ?
        ");

        $stmt->bind_param("is", $reservationId, $today);

        if ($stmt->execute()) {

            if ($stmt->affected_rows > 0) {
                $message = "Reservation successfully marked as No-show.";
                $messageType = "success";
            } else {
                $message = "This reservation cannot be marked as No-show. Make sure it is Confirmed and its check-in date is today or earlier.";
                $messageType = "error";
            }

        } else {

            $message = "Unable to update the reservation.";
            $messageType = "error";

        }

        $stmt->close();
    }
}

// Get confirmed reservations scheduled for arrival today or earlier
$reservations = $conn->prepare("
    SELECT
        r.reservation_id,
        r.check_in,
        r.check_out,
        r.reservation_status,
        r.deposit,
        g.first_name,
        g.last_name,
        g.phone,
        rm.room_number,
        rm.room_type
    FROM reservations r
    INNER JOIN guests g
        ON r.guest_id = g.guest_id
    LEFT JOIN rooms rm
        ON r.room_id = rm.room_id
    WHERE r.reservation_status = 'Confirmed'
      AND r.check_in <= ?
    ORDER BY r.check_in ASC
");

$reservations->bind_param("s", $today);
$reservations->execute();

$reservationResult = $reservations->get_result();

// Get no-show history
$noShows = $conn->query("
    SELECT
        r.reservation_id,
        r.check_in,
        r.check_out,
        r.deposit,
        g.first_name,
        g.last_name,
        g.phone,
        rm.room_number
    FROM reservations r
    INNER JOIN guests g
        ON r.guest_id = g.guest_id
    LEFT JOIN rooms rm
        ON r.room_id = rm.room_id
    WHERE r.reservation_status = 'No-show'
    ORDER BY r.check_in DESC
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>No-Show Management</title>

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
            max-width: 1250px;
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

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 850px;
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

        .no-show {
            color: #dc3545;
            font-weight: bold;
        }

        .confirmed {
            color: #198754;
            font-weight: bold;
        }

        .action-button {
            background: #dc3545;
            color: white;
            border: none;
            padding: 9px 13px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
        }

        .action-button:hover {
            background: #b02a37;
        }

        .empty {
            text-align: center;
            padding: 22px;
            color: #777;
        }

        .back {
            display: inline-block;
            margin-top: 5px;
            color: #0d6efd;
            text-decoration: none;
        }

        .note {
            color: #666;
            font-size: 13px;
            margin-top: 12px;
        }
    </style>
</head>

<body>

<div class="container">

    <h1>No-Show Management</h1>

    <p class="subtitle">
        Manage confirmed reservations for guests who did not arrive.
    </p>

    <?php if ($message !== ""): ?>

        <div class="message <?= $messageType ?>">
            <?= htmlspecialchars($message) ?>
        </div>

    <?php endif; ?>

    <div class="card">

        <h2>Reservations Awaiting Arrival</h2>

        <p>
            These are confirmed reservations with a check-in date
            of today or earlier.
        </p>

        <div class="table-wrapper">

            <table>

                <thead>
                    <tr>
                        <th>Reservation ID</th>
                        <th>Guest Name</th>
                        <th>Phone</th>
                        <th>Room</th>
                        <th>Check-in</th>
                        <th>Check-out</th>
                        <th>Deposit</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>

                <?php if ($reservationResult->num_rows > 0): ?>

                    <?php while ($r = $reservationResult->fetch_assoc()): ?>

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
                                <?= htmlspecialchars($r["phone"] ?? "N/A") ?>
                            </td>

                            <td>
                                <?php if ($r["room_number"]): ?>
                                    Room <?= htmlspecialchars($r["room_number"]) ?>
                                    (<?= htmlspecialchars($r["room_type"] ?? "") ?>)
                                <?php else: ?>
                                    Not Assigned
                                <?php endif; ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($r["check_in"]) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($r["check_out"]) ?>
                            </td>

                            <td>
                                ₱<?= number_format((float) $r["deposit"], 2) ?>
                            </td>

                            <td>

                                <form method="POST"
                                      onsubmit="return confirm('Are you sure you want to mark this reservation as No-show?');">

                                    <input
                                        type="hidden"
                                        name="reservation_id"
                                        value="<?= (int) $r["reservation_id"] ?>"
                                    >

                                    <button
                                        type="submit"
                                        name="mark_no_show"
                                        class="action-button"
                                    >
                                        Mark No-show
                                    </button>

                                </form>

                            </td>

                        </tr>

                    <?php endwhile; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="8" class="empty">
                            No confirmed reservations are awaiting arrival.
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

        <p class="note">
            Only Confirmed reservations with a check-in date of today
            or earlier can be marked as No-show.
        </p>

    </div>

    <div class="card">

        <h2>No-Show History</h2>

        <div class="table-wrapper">

            <table>

                <thead>
                    <tr>
                        <th>Reservation ID</th>
                        <th>Guest Name</th>
                        <th>Phone</th>
                        <th>Room Number</th>
                        <th>Check-in Date</th>
                        <th>Check-out Date</th>
                        <th>Deposit</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>

                <?php if ($noShows && $noShows->num_rows > 0): ?>

                    <?php while ($r = $noShows->fetch_assoc()): ?>

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
                                <?= htmlspecialchars($r["phone"] ?? "N/A") ?>
                            </td>

                            <td>
                                <?= $r["room_number"]
                                    ? "Room " . htmlspecialchars($r["room_number"])
                                    : "Not Assigned" ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($r["check_in"]) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($r["check_out"]) ?>
                            </td>

                            <td>
                                ₱<?= number_format((float) $r["deposit"], 2) ?>
                            </td>

                            <td class="no-show">
                                No-show
                            </td>

                        </tr>

                    <?php endwhile; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="8" class="empty">
                            No no-show records found.
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
