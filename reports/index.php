
<?php
require_once "../config/database.php";

// Get dashboard summary figures
function getCount(mysqli $conn, string $sql): int {
    $result = $conn->query($sql);

    if (!$result) {
        return 0;
    }

    $row = $result->fetch_row();
    return (int) ($row[0] ?? 0);
}

$totalGuests = getCount($conn, "SELECT COUNT(*) FROM guests");
$totalRooms = getCount($conn, "SELECT COUNT(*) FROM rooms");
$totalReservations = getCount($conn, "SELECT COUNT(*) FROM reservations");

$availableRooms = getCount(
    $conn,
    "SELECT COUNT(*) FROM rooms WHERE room_status = 'Available'"
);

$occupiedRooms = getCount(
    $conn,
    "SELECT COUNT(*) FROM rooms WHERE room_status = 'Occupied'"
);

$cleaningRooms = getCount(
    $conn,
    "SELECT COUNT(*) FROM rooms WHERE room_status = 'Cleaning'"
);

// Count reservations by status
$statusResult = $conn->query(
    "SELECT reservation_status, COUNT(*) AS total
     FROM reservations
     GROUP BY reservation_status
     ORDER BY reservation_status"
);

// Count rooms by status
$roomStatusResult = $conn->query(
    "SELECT room_status, COUNT(*) AS total
     FROM rooms
     GROUP BY room_status
     ORDER BY room_status"
);

// Recent reservations
$recentResult = $conn->query(
    "SELECT
        r.reservation_id,
        CONCAT(g.first_name, ' ', g.last_name) AS guest_name,
        rm.room_number,
        r.check_in,
        r.check_out,
        r.reservation_status,
        r.deposit
     FROM reservations r
     INNER JOIN guests g ON r.guest_id = g.guest_id
     LEFT JOIN rooms rm ON r.room_id = rm.room_id
     ORDER BY r.reservation_id DESC
     LIMIT 10"
);

// Calculate total deposits recorded
$depositResult = $conn->query(
    "SELECT COALESCE(SUM(deposit), 0) AS total_deposits
     FROM reservations
     WHERE reservation_status <> 'Cancelled'"
);

$totalDeposits = 0;

if ($depositResult) {
    $depositRow = $depositResult->fetch_assoc();
    $totalDeposits = (float) ($depositRow["total_deposits"] ?? 0);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports and Analytics</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 25px;
            font-family: Arial, sans-serif;
            background: #f4f6f9;
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

        .summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 18px;
            margin-bottom: 28px;
        }

        .card {
            background: white;
            padding: 22px;
            border-radius: 8px;
            box-shadow: 0 2px 8px #00000010;
        }

        .card h3 {
            margin: 0 0 12px;
            font-size: 15px;
            color: #666;
        }

        .number {
            font-size: 30px;
            font-weight: bold;
            color: #172b4d;
        }

        .money {
            font-size: 24px;
            overflow-wrap: anywhere;
        }

        .section {
            background: white;
            padding: 22px;
            margin-bottom: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 8px #00000010;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 650px;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        th {
            background: #172b4d;
            color: white;
        }

        .bar-row {
            display: grid;
            grid-template-columns: 140px minmax(70px, 1fr) 35px;
            gap: 12px;
            align-items: center;
            margin: 16px 0;
        }

        .bar-track {
            height: 18px;
            background: #e9edf3;
            border-radius: 5px;
            overflow: hidden;
        }

        .bar {
            height: 100%;
            background: #2874a6;
            min-width: 0;
        }

        .back {
            display: inline-block;
            margin-top: 5px;
            color: #0d6efd;
            text-decoration: none;
        }

        .print-button {
            padding: 10px 15px;
            background: #172b4d;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin: 0 0 20px;
        }

        @media print {
            body {
                background: white;
                padding: 0;
            }

            .print-button, .back {
                display: none;
            }

            .card, .section {
                box-shadow: none;
                border: 1px solid #ddd;
                break-inside: avoid;
            }
        }

        @media (max-width: 600px) {
            body {
                padding: 12px;
            }

            .bar-row {
                grid-template-columns: 100px minmax(50px, 1fr) 25px;
                gap: 7px;
            }
        }
    </style>
</head>

<body>
<div class="container">

    <h1>Reports and Analytics</h1>
    <p class="subtitle">
        Overview of hotel guests, rooms, reservations, and recorded deposits.
    </p>

    <button class="print-button" onclick="window.print()">
        Print Report
    </button>

    <div class="summary">
        <div class="card">
            <h3>Total Guests</h3>
            <div class="number"><?= $totalGuests ?></div>
        </div>

        <div class="card">
            <h3>Total Rooms</h3>
            <div class="number"><?= $totalRooms ?></div>
        </div>

        <div class="card">
            <h3>Total Reservations</h3>
            <div class="number"><?= $totalReservations ?></div>
        </div>

        <div class="card">
            <h3>Available Rooms</h3>
            <div class="number"><?= $availableRooms ?></div>
        </div>

        <div class="card">
            <h3>Occupied Rooms</h3>
            <div class="number"><?= $occupiedRooms ?></div>
        </div>

        <div class="card">
            <h3>Rooms Being Cleaned</h3>
            <div class="number"><?= $cleaningRooms ?></div>
        </div>

        <div class="card">
            <h3>Recorded Deposits</h3>
            <div class="number money">
                ₱<?= number_format($totalDeposits, 2) ?>
            </div>
        </div>
    </div>

    <div class="section">
        <h2>Reservations by Status</h2>

        <?php
        $statuses = [];

        if ($statusResult) {
            while ($row = $statusResult->fetch_assoc()) {
                $statuses[] = $row;
            }
        }

        $maxReservations = 1;

        foreach ($statuses as $row) {
            $maxReservations = max(
                $maxReservations,
                (int) $row["total"]
            );
        }
        ?>

        <?php if (count($statuses) > 0): ?>
            <?php foreach ($statuses as $row): ?>
                <?php
                $count = (int) $row["total"];
                $percentage = ($count / $maxReservations) * 100;
                ?>
                <div class="bar-row">
                    <span><?= htmlspecialchars($row["reservation_status"]) ?></span>
                    <div class="bar-track">
                        <div class="bar"
                             style="width: <?= $percentage ?>%"></div>
                    </div>
                    <strong><?= $count ?></strong>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p>No reservation records available.</p>
        <?php endif; ?>
    </div>

    <div class="section">
        <h2>Rooms by Status</h2>

        <?php
        $roomStatuses = [];

        if ($roomStatusResult) {
            while ($row = $roomStatusResult->fetch_assoc()) {
                $roomStatuses[] = $row;
            }
        }

        $maxRooms = 1;

        foreach ($roomStatuses as $row) {
            $maxRooms = max($maxRooms, (int) $row["total"]);
        }
        ?>

        <?php if (count($roomStatuses) > 0): ?>
            <?php foreach ($roomStatuses as $row): ?>
                <?php
                $count = (int) $row["total"];
                $percentage = ($count / $maxRooms) * 100;
                ?>
                <div class="bar-row">
                    <span><?= htmlspecialchars($row["room_status"]) ?></span>
                    <div class="bar-track">
                        <div class="bar-track">
                            <div class="bar"
                                 style="width: <?= $percentage ?>%"></div>
                        </div>
                    </div>
                    <strong><?= $count ?></strong>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p>No room records available.</p>
        <?php endif; ?>
    </div>

    <div class="section">
        <h2>Recent Reservations</h2>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Reservation ID</th>
                        <th>Guest</th>
                        <th>Room</th>
                        <th>Check-in</th>
                        <th>Check-out</th>
                        <th>Status</th>
                        <th>Deposit</th>
                    </tr>
                </thead>

                <tbody>
                <?php if ($recentResult && $recentResult->num_rows > 0): ?>
                    <?php while ($row = $recentResult->fetch_assoc()): ?>
                        <tr>
                            <td><?= (int) $row["reservation_id"] ?></td>
                            <td><?= htmlspecialchars($row["guest_name"]) ?></td>
                            <td>
                                <?= $row["room_number"] !== null
                                    ? htmlspecialchars($row["room_number"])
                                    : "Not assigned" ?>
                            </td>
                            <td><?= htmlspecialchars($row["check_in"]) ?></td>
                            <td><?= htmlspecialchars($row["check_out"]) ?></td>
                            <td><?= htmlspecialchars($row["reservation_status"]) ?></td>
                            <td>
                                ₱<?= number_format((float) $row["deposit"], 2) ?>
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
        </div>
    </div>

    <a class="back" href="../index.php">← Back to Dashboard</a>

</div>
</body>
</html>
