
<?php
require_once "../config/database.php";

// Selected date (defaults to today)
$selectedDate = $_GET["date"] ?? date("Y-m-d");

$dateObject = DateTime::createFromFormat("Y-m-d", $selectedDate);

if (!$dateObject || $dateObject->format("Y-m-d") !== $selectedDate) {
    $selectedDate = date("Y-m-d");
}

// Daily arrivals
$arrivalStmt = $conn->prepare("
    SELECT
        r.reservation_id,
        g.first_name,
        g.last_name,
        g.phone,
        rm.room_number,
        rm.room_type,
        r.check_in,
        r.check_out,
        r.adults,
        r.children,
        r.reservation_status
    FROM reservations r
    INNER JOIN guests g ON r.guest_id = g.guest_id
    LEFT JOIN rooms rm ON r.room_id = rm.room_id
    WHERE r.check_in = ?
      AND r.reservation_status IN ('Pending', 'Confirmed', 'Checked-in')
    ORDER BY r.reservation_id DESC
");

$arrivalStmt->bind_param("s", $selectedDate);
$arrivalStmt->execute();
$arrivals = $arrivalStmt->get_result();

// Daily departures
$departureStmt = $conn->prepare("
    SELECT
        r.reservation_id,
        g.first_name,
        g.last_name,
        g.phone,
        rm.room_number,
        rm.room_type,
        r.check_in,
        r.check_out,
        r.reservation_status
    FROM reservations r
    INNER JOIN guests g ON r.guest_id = g.guest_id
    LEFT JOIN rooms rm ON r.room_id = rm.room_id
    WHERE r.check_out = ?
      AND r.reservation_status IN ('Confirmed', 'Checked-in', 'Checked-out')
    ORDER BY r.reservation_id DESC
");

$departureStmt->bind_param("s", $selectedDate);
$departureStmt->execute();
$departures = $departureStmt->get_result();

$arrivalCount = $arrivals->num_rows;
$departureCount = $departures->num_rows;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Arrivals and Departures</title>

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

        .filter {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 22px;
            display: flex;
            align-items: end;
            gap: 12px;
            flex-wrap: wrap;
        }

        .filter label {
            display: block;
            margin-bottom: 7px;
            font-weight: bold;
        }

        .filter input {
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
        }

        button {
            padding: 10px 15px;
            border: none;
            border-radius: 5px;
            background: #172b4d;
            color: white;
            cursor: pointer;
        }

        .summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 18px;
            margin-bottom: 25px;
        }

        .card {
            background: white;
            padding: 22px;
            border-radius: 8px;
            box-shadow: 0 2px 8px #00000010;
        }

        .card h3 {
            color: #666;
            font-size: 15px;
            margin-top: 0;
        }

        .number {
            font-size: 32px;
            font-weight: bold;
            color: #172b4d;
        }

        .section {
            background: white;
            padding: 22px;
            border-radius: 8px;
            margin-bottom: 25px;
            box-shadow: 0 2px 8px #00000010;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 750px;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px;
            border-bottom: 1px solid #eee;
            text-align: left;
        }

        th {
            background: #172b4d;
            color: white;
        }

        .back {
            display: inline-block;
            margin-top: 5px;
            text-decoration: none;
            color: #0d6efd;
        }

        .print-button {
            margin-bottom: 20px;
        }

        @media print {
            body {
                background: white;
                padding: 0;
            }

            .filter, .print-button, .back {
                display: none;
            }

            .section, .card {
                box-shadow: none;
                border: 1px solid #ddd;
            }
        }
    </style>
</head>

<body>
<div class="container">

    <h1>Daily Arrivals and Departures</h1>

    <p class="subtitle">
        Monitor the hotel's expected guest arrivals and departures.
    </p>

    <form method="GET" class="filter">
        <div>
            <label for="date">Select Date</label>
            <input
                type="date"
                name="date"
                id="date"
                value="<?= htmlspecialchars($selectedDate) ?>"
                required
            >
        </div>

        <button type="submit">View Report</button>
    </form>

    <button class="print-button" onclick="window.print()">
        Print Daily Report
    </button>

    <div class="summary">
        <div class="card">
            <h3>Expected Arrivals</h3>
            <div class="number"><?= $arrivalCount ?></div>
        </div>

        <div class="card">
            <h3>Expected Departures</h3>
            <div class="number"><?= $departureCount ?></div>
        </div>
    </div>

    <!-- ARRIVALS -->
    <div class="section">
        <h2>Guest Arrivals</h2>

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
                        <th>Guests</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>
                <?php if ($arrivalCount > 0): ?>
                    <?php while ($row = $arrivals->fetch_assoc()): ?>
                        <tr>
                            <td><?= (int) $row["reservation_id"] ?></td>
                            <td>
                                <?= htmlspecialchars(
                                    $row["first_name"] . " " . $row["last_name"]
                                ) ?>
                            </td>
                            <td><?= htmlspecialchars($row["phone"] ?? "") ?></td>
                            <td>
                                <?= $row["room_number"] !== null
                                    ? htmlspecialchars($row["room_number"])
                                    : "Not assigned" ?>
                            </td>
                            <td><?= htmlspecialchars($row["check_in"]) ?></td>
                            <td><?= htmlspecialchars($row["check_out"]) ?></td>
                            <td>
                                <?= (int) $row["adults"] + (int) $row["children"] ?>
                            </td>
                            <td><?= htmlspecialchars($row["reservation_status"]) ?></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8">No arrivals found for this date.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- DEPARTURES -->
    <div class="section">
        <h2>Guest Departures</h2>

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
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>
                <?php if ($departureCount > 0): ?>
                    <?php while ($row = $departures->fetch_assoc()): ?>
                        <tr>
                            <td><?= (int) $row["reservation_id"] ?></td>
                            <td>
                                <?= htmlspecialchars(
                                    $row["first_name"] . " " . $row["last_name"]
                                ) ?>
                            </td>
                            <td><?= htmlspecialchars($row["phone"] ?? "") ?></td>
                            <td>
                                <?= $row["room_number"] !== null
                                    ? htmlspecialchars($row["room_number"])
                                    : "Not assigned" ?>
                            </td>
                            <td><?= htmlspecialchars($row["check_in"]) ?></td>
                            <td><?= htmlspecialchars($row["check_out"]) ?></td>
                            <td><?= htmlspecialchars($row["reservation_status"]) ?></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7">No departures found for this date.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <a class="back" href="../index.php">← Back to Dashboard</a>

</div>
</body>
</html>

<?php
$arrivalStmt->close();
$departureStmt->close();
?>
