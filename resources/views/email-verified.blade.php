<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5, user-scalable=yes">
    <title>Email Verified - Zivo</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background-color: #000000;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 16px;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        .container {
            text-align: center;
            max-width: 500px;
            width: 100%;
        }
        .icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 20px;
            background-color: #10B981;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
        }
        h1 {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 12px;
            color: #FFFF00;
            line-height: 1.3;
        }
        .message {
            font-size: 15px;
            line-height: 1.6;
            color: #e5e5e5;
            margin-bottom: 24px;
        }
        .instructions {
            background-color: #1a1a1a;
            border: 1px solid #333333;
            border-radius: 12px;
            padding: 20px;
            margin-top: 24px;
        }
        .instructions h2 {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 10px;
            color: #FFFF00;
        }
        .instructions p {
            font-size: 14px;
            line-height: 1.6;
            color: #b3b3b3;
        }
        .checkmark {
            color: #ffffff;
            font-weight: bold;
        }
        @media (min-width: 480px) {
            body {
                padding: 24px;
            }
            .icon {
                width: 80px;
                height: 80px;
                font-size: 48px;
                margin-bottom: 24px;
            }
            h1 {
                font-size: 28px;
                margin-bottom: 16px;
            }
            .message {
                font-size: 16px;
                margin-bottom: 32px;
            }
            .instructions {
                padding: 24px;
                margin-top: 32px;
            }
            .instructions h2 {
                font-size: 18px;
                margin-bottom: 12px;
            }
        }
        @media (min-width: 768px) {
            body {
                padding: 32px;
            }
            .container {
                max-width: 600px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">
            <span class="checkmark">✓</span>
        </div>
        <h1>Email Verified Successfully!</h1>
        <div class="instructions">
            <h2>Next Steps</h2>
            <p>Please return to the app and refresh your profile page to see your verified status.</p>
        </div>
    </div>
</body>
</html>

