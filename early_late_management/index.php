
<?php
include "../config/database.php";

$message = "";
$message_type = "";

// RECORD EARLY CHECK-IN OR LATE CHECK-OUT
if (isset($_POST["save_record"])) {

    $reservation_id = intval($_POST["reservation_id"]);
    $adjustment_type = $_POST["adjustment_type"] ?? "";
    $actual_time = $_POST["actual_time"] ?? "";
    $reason = trim($_POST["reason"] ?? "");

    $allowed_types = ["Early Check-in", "Late Check-out"];

    if (
        $reservation_id <= 0 ||
        !in_array($adjustment_type, $allowed_types) ||
        empty($actual_time) ||
        $reason === ""
    ) {
        $message = "Please complete all fields correctly.";
        $message_type = "error";

    } else {

        $actual_timestamp = strtotime($actual_time);

        if ($actual_timestamp === false) {

            $message = "Invalid date or time.";
            $message_type = "error";

        } else {

            $formatted_time = date("Y-m-d H:i:s", $actual_timestamp);

            $stmt = $conn->prepare("
                SELECT reservation_id, check_in, check_out,
                       reservation_status
                FROM reservations
                WHERE reservation_id = ?
            ");

            $stmt->bind_param("i", $reservation_id);
            $stmt->execute();

            $reservation = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$reservation) {

                $message = "Reservation not found.";
                $message_type = "error";

            } else {

                $valid_reservation = false;

                if ($adjustment_type === "Early Check-in") {

                    $valid_reservation =
                        $reservation["reservation_status"] === "Confirmed";

                    if (
                        $actual_timestamp >= strtotime($reservation["check_in"] . " 00:00:00")
                    ) {
                        $valid_reservation = false;
                    }

                } else {

                    $valid_reservation =
                        $reservation["reservation_status"] === "Checked-in";

                    if (
                        $actual_timestamp <= strtotime($reservation["check_out"] . " 00:00:00")
                    ) {
                        $valid_reservation = false;
                    }
                }

                if (!$valid_reservation) {

                    $message = "The reservation status or selected time does not qualify for this adjustment.";
                    $message_type = "error";

                } else {

                    $stmt = $conn->prepare("
                        INSERT INTO early_late_records
                        (reservation_id, adjustment_type, actual_time, reason)
                        VALUES (?, ?, ?, ?)
                    ");

                    $stmt->bind_param(
                        "isss",
                        $reservation_id,
                        $adjustment_type,
                        $formatted_time,
                        $reason
                    );

                    if ($stmt->execute()) {

                        $message = "Adjustment record successfully saved.";
                        $message_type = "success";

                    } else {

                        $message = "Unable to save the adjustment record.";
                        $message_type = "error";
                    }

                    $stmt->close();
                }
            }
        }
    }
}

// GET RESERVATIONS FOR THE FORM
$reservations = $conn->query("
    SELECT
        r.reservation_id,
        r.check_in,
        r.check_out,
        r.reservation_status,
        g.first_name,
        g.last_name,
        rm.room_number
    FROM reservations r
    INNER JOIN guests g ON r.guest_id = g.guest_id
    LEFT JOIN rooms rm ON r.room_id = rm.room_id
    WHERE r.reservation_status IN ('Confirmed', 'Checked-in')
    ORDER BY r.reservation_id DESC
");

// GET ADJUSTMENT HISTORY
$history = $conn->query("
    SELECT
        e.record_id,
        e.reservation_id,
        e.adjustment_type,
        e.actual_time,
        e.reason,
        e.created_at,
        g.first_name,
        g.last_name,
        rm.room_number
    FROM early_late_records e
    INNER JOIN reservations r
        ON e.reservation_id = r.reservation_id
    INNER JOIN guests g
        ON r.guest_id = g.guest_id
    LEFT JOIN rooms rm
        ON r.room_id = rm.room_id
    ORDER BY e.created_at DESC
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Early Check-in and Late Check-out</title>

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
            max-width: 1150px;
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
            background: #243447;
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }

        button:hover {
            background: #172534;
        }

        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 850px;
        }

        th, td {
            padding: 13px;
            text-align: left;
            border-bottom: 1px solid #eee;
            font-size: 14px;
        }

        th {
            background: #243447;
            color: white;
        }

        .type {
            display: inline-block;
            padding: 6px 9px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: bold;
        }

        .early {
            background: #dbeafe;
            color: #1e40af;
        }

        .late {
            background: #fff3cd;
            color: #856404;
        }

        .empty {
            text-align: center;
            color: #777;
            padding: 25px;
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

    <h1>Early Check-in and Late Check-out</h1>

    <p class="subtitle">
        Manage guest schedule adjustments and view adjustment records.
    </p>

    <?php if ($message !== ""): ?>
        <div class="message <?php echo $message_type; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <div class="card">

        <h2>Record Schedule Adjustment</h2>

        <form method="POST">

            <div class="form-grid">

                <div class="form-group full-width">

                    <label>Select Reservation</label>

                    <select name="reservation_id" required>

                        <option value="">Select Reservation</option>

                        <?php while ($row = $reservations->fetch_assoc()): ?>

                            <option value="<?php echo $row["reservation_id"]; ?>">

                                <?php
                                echo "Reservation #" . $row["reservation_id"];
                                echo " - " . htmlspecialchars(
                                    $row["first_name"] . " " . $row["last_name"]
                                );
                                echo " - Room " . htmlspecialchars(
                                    $row["room_number"] ?? "Not Assigned"
                                );
                                echo " - " . $row["reservation_status"];
                                ?>

                            </option>

                        <?php endwhile; ?>

                    </select>

                </div>

                <div class="form-group">

                    <label>Adjustment Type</label>

                    <select name="adjustment_type" required>

                        <option value="">Select Adjustment</option>
                        <option value="Early Check-in">Early Check-in</option>
                        <option value="Late Check-out">Late Check-out</option>

                    </select>

                </div>

                <div class="form-group">

                    <label>Actual Date and Time</label>

                    <input
                        type="datetime-local"
                        name="actual_time"
                        required
                    >

                </div>

                <div class="form-group full-width">

                    <label>Reason for Adjustment</label>

                    <textarea
                        name="reason"
                        rows="3"
                        placeholder="Enter the reason for the adjustment..."
                        maxlength="255"
                        required
                    ></textarea>

                </div>

                <div class="form-group full-width">

                    <button type="submit" name="save_record">
                        Save Adjustment
                    </button>

                </div>

            </div>

        </form>

    </div>

    <div class="card">

        <h2>Adjustment History</h2>

        <div class="table-container">

            <table>

                <thead>
                    <tr>
                        <th>Record ID</th>
                        <th>Reservation ID</th>
                        <th>Guest Name</th>
                        <th>Room</th>
                        <th>Adjustment Type</th>
                        <th>Actual Date and Time</th>
                        <th>Reason</th>
                        <th>Recorded At</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if ($history && $history->num_rows > 0): ?>

                        <?php while ($row = $history->fetch_assoc()): ?>

                            <tr>

                                <td>
                                    <?php echo $row["record_id"]; ?>
                                </td>

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
                                    <?php echo htmlspecialchars($row["room_number"] ?? "Not Assigned"); ?>
                                </td>

                                <td>

                                    <?php if ($row["adjustment_type"] === "Early Check-in"): ?>

                                        <span class="type early">
                                            Early Check-in
                                        </span>

                                    <?php else: ?>

                                        <span class="type late">
                                            Late Check-out
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <td>
                                    <?php echo htmlspecialchars($row["actual_time"]); ?>
                                </td>

                                <td>
                                    <?php echo htmlspecialchars($row["reason"]); ?>
                                </td>

                                <td>
                                    <?php echo htmlspecialchars($row["created_at"]); ?>
                                </td>

                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="8" class="empty">
                                No adjustment records found.
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
$conn->close();
?>
