
<?php
require_once "config/database.php";

$guestQuery = $conn->query(
    "SELECT COUNT(*) AS total FROM guests"
);
$guestCount = $guestQuery->fetch_assoc()["total"];

$roomQuery = $conn->query(
    "SELECT COUNT(*) AS total FROM rooms"
);
$roomCount = $roomQuery->fetch_assoc()["total"];

$reservationQuery = $conn->query(
    "SELECT COUNT(*) AS total FROM reservations"
);
$reservationCount = $reservationQuery->fetch_assoc()["total"];

$availableQuery = $conn->query(
    "SELECT COUNT(*) AS total
     FROM rooms
     WHERE room_status = 'Available'"
);
$availableCount = $availableQuery->fetch_assoc()["total"];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Hotel Front Office System</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f3f5f9;
            color: #202938;
        }

        header {
            background: #142b4a;
            color: white;
            padding: 24px;
        }

        header h1 {
            margin: 0 0 8px;
        }

        header p {
            margin: 0;
            color: #d5deeb;
        }

        main {
            max-width: 1100px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .cards {
            display: grid;
            grid-template-columns:
                repeat(auto-fit, minmax(200px, 1fr));
            gap: 18px;
        }

        .card {
            background: white;
            padding: 22px;
            border-radius: 12px;
            box-shadow: 0 3px 12px #0000000d;
        }

        .card h2 {
            font-size: 16px;
            color: #667085;
            margin-top: 0;
        }

        .number {
            font-size: 32px;
            font-weight: bold;
            color: #142b4a;
        }

        .status {
            margin-top: 25px;
            background: white;
            padding: 22px;
            border-radius: 12px;
        }

        @media (max-width: 600px) {
            header {
                padding: 20px;
            }

            main {
                margin-top: 20px;
            }
        }
    </style>
</head>

<body>

<header>
    <h1>Hotel Front Office System</h1>
    <p>Dashboard | Hotel Management System</p>
</header>

<main>
    <h2>Dashboard Overview</h2>

    <section class="cards">
        <div class="card">
            <h2>Total Guests</h2>
            <div class="number">
                <?= (int) $guestCount ?>
            </div>
        </div>

        <div class="card">
            <h2>Total Rooms</h2>
            <div class="number">
                <?= (int) $roomCount ?>
            </div>
        </div>

        <div class="card">
            <h2>Reservations</h2>
            <div class="number">
                <?= (int) $reservationCount ?>
            </div>
        </div>

        <div class="card">
            <h2>Available Rooms</h2>
            <div class="number">
                <?= (int) $availableCount ?>
            </div>
        </div>
    </section>

    <section class="status">
        <h2>System Status</h2>
        <p>
            The database connection is working,
            and the dashboard has loaded successfully.
        </p>
    </section>
</main>

</body>
</html>
