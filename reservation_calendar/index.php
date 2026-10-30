
<?php
require_once "../config/database.php";

// Get selected month and year
$month = filter_input(INPUT_GET, "month", FILTER_VALIDATE_INT);
$year = filter_input(INPUT_GET, "year", FILTER_VALIDATE_INT);

$currentDate = new DateTime();

if (
    !$month || $month < 1 || $month > 12 ||
    !$year || $year < 2000 || $year > 2100
) {
    $month = (int) $currentDate->format("m");
    $year = (int) $currentDate->format("Y");
}

// Calendar date calculations
$firstDay = new DateTime("$year-$month-01");
$lastDay = clone $firstDay;
$lastDay->modify("last day of this month");

$daysInMonth = (int) $lastDay->format("d");
$startingDay = (int) $firstDay->format("w");

$startDate = $firstDay->format("Y-m-d");
$endDate = $lastDay->format("Y-m-d");

// Previous and next month
$previousMonth = clone $firstDay;
$previousMonth->modify("-1 month");

$nextMonth = clone $firstDay;
$nextMonth->modify("+1 month");

// Retrieve reservations overlapping the selected month
$stmt = $conn->prepare("
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
    WHERE r.check_in <= ?
      AND r.check_out >= ?
      AND r.reservation_status NOT IN ('Cancelled', 'No-show')
    ORDER BY r.check_in ASC
");

$stmt->bind_param("ss", $endDate, $startDate);
$stmt->execute();
$result = $stmt->get_result();

// Organize reservations by arrival and departure date
$arrivals = [];
$departures = [];

while ($row = $result->fetch_assoc()) {
    $checkIn = $row["check_in"];
    $checkOut = $row["check_out"];

    if ($checkIn >= $startDate && $checkIn <= $endDate) {
        $arrivals[$checkIn][] = $row;
    }

    if ($checkOut >= $startDate && $checkOut <= $endDate) {
        $departures[$checkOut][] = $row;
    }
}

$stmt->close();

// Month title
$monthTitle = $firstDay->format("F Y");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Reservation Calendar</title>

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

        .calendar-section {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 8px #00000010;
        }

        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .calendar-header h2 {
            margin: 0;
        }

        .navigation {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .navigation a {
            background: #172b4d;
            color: white;
            text-decoration: none;
            padding: 9px 13px;
            border-radius: 5px;
        }

        .today-link {
            background: #198754 !important;
        }

        .calendar-wrapper {
            overflow-x: auto;
        }

        .calendar {
            display: grid;
            grid-template-columns: repeat(7, minmax(110px, 1fr));
            min-width: 770px;
            border-top: 1px solid #ddd;
            border-left: 1px solid #ddd;
        }

        .day-name {
            background: #172b4d;
            color: white;
            padding: 12px 5px;
            text-align: center;
            font-weight: bold;
        }

        .day {
            min-height: 145px;
            border-right: 1px solid #ddd;
            border-bottom: 1px solid #ddd;
            padding: 7px;
            background: white;
        }

        .empty-day {
            background: #f5f6f8;
        }

        .day-number {
            font-weight: bold;
            margin-bottom: 8px;
        }

        .today {
            color: #0d6efd;
            background: #eaf2ff;
            border-radius: 50%;
            padding: 4px 7px;
        }

        .event {
            padding: 6px;
            border-radius: 4px;
            margin-bottom: 5px;
            font-size: 11px;
            line-height: 1.4;
            overflow-wrap: anywhere;
        }

        .arrival {
            background: #d1e7dd;
            color: #0f5132;
            border-left: 3px solid #198754;
        }

        .departure {
            background: #cfe2ff;
            color: #084298;
            border-left: 3px solid #0d6efd;
        }

        .status {
            font-weight: bold;
        }

        .legend {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            margin-top: 20px;
            font-size: 13px;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .legend-color {
            width: 14px;
            height: 14px;
            border-radius: 3px;
        }

        .back {
            display: inline-block;
            margin-top: 20px;
            color: #0d6efd;
            text-decoration: none;
        }

        @media print {
            body {
                background: white;
                padding: 0;
            }

            .navigation, .back {
                display: none;
            }

            .calendar-section {
                box-shadow: none;
            }

            .day {
                min-height: 120px;
            }
        }
    </style>
</head>

<body>
<div class="container">

    <h1>Reservation Calendar</h1>

    <p class="subtitle">
        View guest arrivals and departures through a monthly calendar.
    </p>

    <div class="calendar-section">

        <div class="calendar-header">
            <h2><?= htmlspecialchars($monthTitle) ?></h2>

            <div class="navigation">
                <a href="?month=<?= $previousMonth->format("n") ?>&year=<?= $previousMonth->format("Y") ?>">
                    Previous
                </a>

                <a class="today-link" href="index.php">
                    Today
                </a>

                <a href="?month=<?= $nextMonth->format("n") ?>&year=<?= $nextMonth->format("Y") ?>">
                    Next
                </a>

                <button onclick="window.print()">
                    Print
                </button>
            </div>
        </div>

        <div class="calendar-wrapper">
            <div class="calendar">

                <?php
                $weekdays = [
                    "Sunday", "Monday", "Tuesday", "Wednesday",
                    "Thursday", "Friday", "Saturday"
                ];

                foreach ($weekdays as $weekday):
                ?>
                    <div class="day-name">
                        <?= $weekday ?>
                    </div>
                <?php endforeach; ?>

                <?php
                // Empty spaces before the first day
                for ($i = 0; $i < $startingDay; $i++):
                ?>
                    <div class="day empty-day"></div>
                <?php endfor; ?>

                <?php
                // Display each day of the month
                for ($day = 1; $day <= $daysInMonth; $day++):
                    $date = sprintf("%04d-%02d-%02d", $year, $month, $day);
                    $isToday = $date === date("Y-m-d");
                ?>

                    <div class="day">

                        <div class="day-number">
                            <span class="<?= $isToday ? "today" : "" ?>">
                                <?= $day ?>
                            </span>
                        </div>

                        <!-- Guest Arrivals -->
                        <?php if (isset($arrivals[$date])): ?>
                            <?php foreach ($arrivals[$date] as $event): ?>
                                <div class="event arrival">
                                    <strong>ARRIVAL</strong><br>

                                    <?= htmlspecialchars(
                                        $event["first_name"] . " " .
                                        $event["last_name"]
                                    ) ?>

                                    <br>
                                    Room:
                                    <?= htmlspecialchars(
                                        $event["room_number"] ?? "Not assigned"
                                    ) ?>

                                    <br>
                                    <span class="status">
                                        <?= htmlspecialchars($event["reservation_status"]) ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <!-- Guest Departures -->
                        <?php if (isset($departures[$date])): ?>
                            <?php foreach ($departures[$date] as $event): ?>
                                <div class="event departure">
                                    <strong>DEPARTURE</strong><br>

                                    <?= htmlspecialchars(
                                        $event["first_name"] . " " .
                                        $event["last_name"]
                                    ) ?>

                                    <br>
                                    Room:
                                    <?= htmlspecialchars(
                                        $event["room_number"] ?? "Not assigned"
                                    ) ?>

                                    <br>
                                    <span class="status">
                                        <?= htmlspecialchars($event["reservation_status"]) ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                    </div>

                <?php endfor; ?>

                <?php
                // Empty spaces after the last day
                $totalCells = $startingDay + $daysInMonth;
                $remainingCells = (7 - ($totalCells % 7)) % 7;

                for ($i = 0; $i < $remainingCells; $i++):
                ?>
                    <div class="day empty-day"></div>
                <?php endfor; ?>

            </div>
        </div>

        <div class="legend">
            <div class="legend-item">
                <span class="legend-color" style="background:#d1e7dd"></span>
                Guest Arrival
            </div>

            <div class="legend-item">
                <span class="legend-color" style="background:#cfe2ff"></span>
                Guest Departure
            </div>

            <div class="legend-item">
                <span class="legend-color" style="background:#eaf2ff"></span>
                Today's Date
            </div>
        </div>

    </div>

    <a class="back" href="../index.php">
        ← Back to Dashboard
    </a>

</div>
</body>
</html>
