<?php
require 'vendor/autoload.php';

use Google\Client;
use Google\Service\Sheets;

// Initialize Google Client
$client = new Client();
$client->setApplicationName('Reddit Clone');
$client->setAuthConfig('credentials.json');
$client->setScopes([Sheets::SPREADSHEETS]);
$service = new Sheets($client);

$spreadsheetId = '1JCq6dQtyMwZY11q0VMwDFGYdqGGHdWYwrdvdkqgSwu4';
$range = 'Sheet1!A:E'; // Adjust according to your sheet's layout

// Handle new post submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['url']) && isset($_POST['title'])) {
    $url = filter_var($_POST['url'], FILTER_VALIDATE_URL);
    $title = htmlspecialchars($_POST['title'], ENT_QUOTES, 'UTF-8');
    if ($url && $title) {
        // Fetch existing data to determine the new ID
        $response = $service->spreadsheets_values->get($spreadsheetId, $range);
        $posts = $response->getValues();
        
        // Determine the new ID
        $id = 1;
        foreach ($posts as $post) {
            if ($post[0] == $id) {
                $id++;
            } else {
                break;
            }
        }

        $date_added = (new DateTime())->format(DateTime::ATOM);
        $values = [
            [$id, $title, $url, 0, $date_added] // id, title, url, initial vote, date_added
        ];
        $body = new Google_Service_Sheets_ValueRange([
            'values' => $values
        ]);
        $params = ['valueInputOption' => 'RAW'];
        $service->spreadsheets_values->append($spreadsheetId, $range, $body, $params);
    }
}

// Fetch posts
$response = $service->spreadsheets_values->get($spreadsheetId, $range);
$posts = $response->getValues();

if (!$posts) {
    $posts = [];
}

// Skip the header row
array_shift($posts);

if (empty($posts)) {
    echo "No posts found.";
    exit;
}

// Handle voting
session_start();
$votes = $_SESSION['votes'] ?? [];

if (isset($_GET['vote']) && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $vote = $_GET['vote'];

    if (!isset($votes[$id])) {
        $votes[$id] = 0;
    }

    if ($vote === 'up') {
        $votes[$id] = ($votes[$id] === 1) ? 0 : 1;
    } elseif ($vote === 'down') {
        $votes[$id] = ($votes[$id] === -1) ? 0 : -1;
    }
    $_SESSION['votes'] = $votes;
}

// Update votes in the posts data
foreach ($posts as &$post) {
    $post[3] = (int)$post[3] + ($votes[$post[0]] ?? 0);
}

// Sort posts by votes (descending)
usort($posts, function($a, $b) {
    return $b[3] <=> $a[3];
});

// Extract domain from URL
function getDomain($url) {
    $parsedUrl = parse_url($url);
    $host = $parsedUrl['host'] ?? '';
    
    // Extract domain name using basic string operations
    $hostParts = explode('.', $host);
    if (count($hostParts) > 2) {
        // If the host has subdomains, take the last two parts as the domain
        return implode('.', array_slice($hostParts, -2));
    }
    return $host;
}

// Convert date to relative time
function timeAgo($date) {
    $now = new DateTime();
    $ago = new DateTime($date);
    $diff = $now->getTimestamp() - $ago->getTimestamp();
    
    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        return floor($diff / 60) . ' minutes ago';
    } elseif ($diff < 86400) {
        return floor($diff / 3600) . ' hours ago';
    } elseif ($diff < 604800) {
        return floor($diff / 86400) . ' days ago';
    } else {
        return floor($diff / 604800) . ' weeks ago';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reddit Clone</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: auto;
            padding: 20px;
            background-color: #f4f4f4;
        }
        h1 {
            text-align: center;
            color: #333;
        }
        form {
            margin-bottom: 20px;
            padding: 10px;
            background: #fff;
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }
        form input[type="text"],
        form input[type="url"] {
            width: calc(100% - 22px);
            padding: 10px;
            margin-bottom: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        form button {
            padding: 10px 15px;
            background-color: #28a745;
            color: #fff;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        form button:hover {
            background-color: #218838;
        }
        .post {
            background: #fff;
            padding: 15px;
            margin-bottom: 10px;
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }
        .post-title {
            font-size: 1.2em;
            font-weight: bold;
            color: #333;
        }
        .votes {
            font-weight: bold;
            margin-left: 10px;
            color: #007bff;
        }
        .vote-buttons {
            display: inline-block;
            margin-right: 10px;
        }
        .vote-buttons a {
            text-decoration: none;
            font-size: 1.2em;
        }
        .vote-buttons a:hover {
            color: #0056b3;
        }
        .domain {
            font-size: 0.9em;
            color: #555;
        }
        .date {
            font-size: 0.8em;
            color: #888;
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <h1>Reddit Clone</h1>
    
    <form method="POST">
        <input type="text" name="title" placeholder="Enter a title" required>
        <input type="url" name="url" placeholder="Submit a URL" required>
        <button type="submit">Submit</button>
    </form>

    <h2>Posts</h2>
    
    <?php foreach ($posts as $post): ?>
        <div class="post">
            <div class="post-title">
                <a href="<?= htmlspecialchars($post[2], ENT_QUOTES, 'UTF-8') ?>" target="_blank"><?= htmlspecialchars($post[1], ENT_QUOTES, 'UTF-8') ?></a>
                <span class="domain">(<?= getDomain(htmlspecialchars($post[2], ENT_QUOTES, 'UTF-8')) ?>)</span>
            </div>
            <div class="vote-buttons">
                <a href="?vote=up&id=<?= htmlspecialchars($post[0], ENT_QUOTES, 'UTF-8') ?>">🔺</a>
                <a href="?vote=down&id=<?= htmlspecialchars($post[0], ENT_QUOTES, 'UTF-8') ?>">🔻</a>
            </div>
            <span class="votes"><?= htmlspecialchars($post[3], ENT_QUOTES, 'UTF-8') ?> votes</span>
            <div class="date"><?= timeAgo($post[4]) ?></div>
        </div>
    <?php endforeach; ?>
</body>
</html>
