<?php
http_response_code(403);
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 Forbidden</title>
    <style>
        body {
            background-color: #050505;
            color: #ffffff;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            text-align: center;
        }
        h1 {
            font-size: 7.5rem;
            font-weight: 800;
            margin: 0;
            color: #3b3b3b;
            letter-spacing: -2px;
            line-height: 1;
        }
        h2 {
            font-size: 2rem;
            font-weight: 700;
            margin: 1rem 0 0.5rem 0;
            color: #4f5b66;
        }
        p {
            font-size: 0.95rem;
            color: #4f5b66;
            margin: 0;
        }
    </style>
</head>
<body>
    <h1>403</h1>
    <h2>Forbidden</h2>
    <p>Access to this resource on the server is denied!</p>
</body>
</html>
<?php exit; ?>
