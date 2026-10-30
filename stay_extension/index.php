
<?php
require_once "../config/database.php";

$message = "";
$messageType = "";

$today = date("Y-m-d");

// Process stay extension
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["extend_stay"])) {

    $reservationId = (int) ($_POST["reservation_id"] ?? 0);
    $newCheckOut = trim($_POST["new_check_out"] ?? "");

    $newDate = DateTime::createFromFormat("Y-m-d", $newCheckOut);

    $validDate = $newDate &&
        $newDate->format("Y-m-d") === $newCheckOut;

    if ($reservationId <= 0 || !$validDate) {

        $message = "Please select a valid reservation and check-out date.";
        $messageType = "error";

    } else {

        // Get reservation information
        $stmt = $conn->prepare("
            SELECT
                reservation_id,
                room_id,
                check_in,
                check_out,
                reservation_status
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

        } elseif (!in_array(
            $reservation["reservation_status"],
            ["Confirmed", "Checked-in"],
            true
        )) {

            $message = "Only Confirmed or Checked-in reservations can be extended.";
            $messageType = "error";

        } elseif (empty($reservation["room_id"])) {

            $message = "This reservation has no assigned room.";
            $messageType = "error";

        } elseif ($newCheckOut <= $reservation["check_out"]) {

            $message = "The new check-out date must be later than the current check-out date.";
            $messageType = "error";

        } else {

            // Check for another reservation using the same room
            $stmt = $conn->prepare("
                SELECT reservation_id
                FROM reservations
                WHERE room_id = ?
                  AND reservation_id != ?
                  AND reservation_status IN (
                      'Pending',
                      'Confirmed',
                      'Checked-in'
                  )
                  AND check_in < ?
                  AND check_out > ?
                LIMIT 1
            ");

            $stmt->bind_param(
                "iiss",
                $reservation["room_id"],
                $reservationId,
                $newCheckOut,
                $reservation["check_out"]
            );

            $stmt->execute();

            $conflict = $stmt->get_result()->fetch_assoc();

            $stmt->close();

            if ($conflict) {

                $message = "Stay extension denied. Another reservation is scheduled for this room during the requested extension.";
                $messageType = "error";

            } else {

                // Update reservation check-out date
                $stmt = $conn->prepare("
                    UPDATE reservations
                    SET check_out = ?
                    WHERE reservation_id = ?
                ");

                $stmt->bind_param(
                    "si",
                    $newCheckOut,
                    $reservationId
                );

                if ($stmt->execute()) {

                    $message = "Guest stay successfully extended!";
                    $messageType = "success";

                } else {

                    $message = "Unable to update the reservation.";
                    $messageType = "error";

                }

                $stmt->close();
            }
        }
    }
}

// Get reservations eligible for extension
$reservations = $conn->query("
    SELECT
        r.reservation_id,
        r.check_in,
        r.check_out,
        r.reservation_status,
        r.room_id,
        g.first_name,
        g.last_name,
        rm.room_number,
        rm.room_type
    FROM reservations r
    INNER JOIN guests g
        ON r.guest_id = g.guest_id
    LEFT JOIN rooms rm
        ON r.room_id = rm.room_id
    WHERE r.reservation_status IN ('Confirmed', 'Checked-in')
      AND r.room_id IS NOT NULL
    ORDER BY r.check_out ASC
");

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Stay Extension Management</title>

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

        select,
        input {
            width: 100%;
            padding: 11px;
            border: 1px solid #ccc;
            border-radius: 5px;
            margin-bottom: 16px;
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

        .note {
            color: #666;
            font-size: 13px;
            margin-top: 12px;
        }
    </style>
</head>

<body>

<div class="container">

    <h1>Stay Extension Management</h1>

    <p class="subtitle">
        Manage guest requests to extend their hotel stay.
    </p>

    <?php if ($message !== ""): ?>

        <div class="message <?= $messageType ?>">
            <?= htmlspecialchars($message) ?>
        </div>

    <?php endif; ?>

    <div class="card">

        <h2>Extend Guest Stay</h2>

        <form method="POST">

            <label for="reservation_id">
                Select Reservation
            </label>

            <select
                name="reservation_id"
                id="reservation_id"
                required
            >

                <option value="">
                    -- Select Reservation --
                </option>

                <?php
                $reservations->data_seek(0);
                ?>

                <?php while ($r = $reservations->fetch_assoc()): ?>

                    <option
                        value="<?= (int) $r["reservation_id"] ?>"
                        data-checkout="<?= htmlspecialchars($r["check_out"]) ?>"
                    >
                        Reservation #<?= (int) $r["reservation_id"] ?>
                        -
                        <?= htmlspecialchars(
                            $r["first_name"] . " " . $r["last_name"]
                        ) ?>
                        -
                        Room <?= htmlspecialchars($r["room_number"]) ?>
                        -
                        Current Checkout:
                        <?= htmlspecialchars($r["check_out"]) ?>
                    </option>

                <?php endwhile; ?>

            </select>

            <label for="new_check_out">
                New Check-out Date
            </label>

            <input
                type="date"
                name="new_check_out"
                id="new_check_out"
                min="<?= htmlspecialchars($today) ?>"
                required
            >

            <button type="submit" name="extend_stay">
                Extend Stay
            </button>

            <p class="note">
                The new check-out date must be later than the current
                check-out date. Room availability will be checked before
                the extension is saved.
            </p>

        </form>

    </div>

    <div class="card">

        <h2>Current Guest Stays</h2>

        <div class="table-wrapper">

            <table>

                <thead>
                    <tr>
                        <th>Reservation ID</th>
                        <th>Guest Name</th>
                        <th>Room Number</th>
                        <th>Room Type</th>
                        <th>Check-in</th>
                        <th>Current Check-out</th>
                        <th>Status</th>
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
                                Room <?= htmlspecialchars($r["room_number"]) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($r["room_type"] ?? "N/A") ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($r["check_in"]) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($r["check_out"]) ?>
                            </td>

                            <td class="status">
                                <?= htmlspecialchars($r["reservation_status"]) ?>
                            </td>

                        </tr>

                    <?php endwhile; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="7" class="empty">
                            No eligible reservations found.
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

<script>
    const reservationSelect = document.getElementById("reservation_id");
    const checkoutInput = document.getElementById("new_check_out");

    function updateMinimumDate() {
        const selectedOption =
            reservationSelect.options[reservationSelect.selectedIndex];

        const currentCheckout = selectedOption.dataset.checkout;

        if (currentCheckout) {
            checkoutInput.min = currentCheckout;
        } else {
            checkoutInput.min = "<?= htmlspecialchars($today) ?>";
        }

        if (checkoutInput.value && checkoutInput.value <= checkoutInput.min) {
            checkoutInput.value = "";
        }
    }

    reservationSelect.addEventListener("change", updateMinimumDate);
    updateMinimumDate();
</script>

</body>
</html>
