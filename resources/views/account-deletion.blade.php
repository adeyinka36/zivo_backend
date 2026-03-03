<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Zivo – Account & Data Deletion</title>
    <style>
        body {
            margin: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background-color: #050505;
            color: #f9fafb;
        }
        .page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2.5rem 1.5rem;
        }
        .card {
            max-width: 48rem;
            width: 100%;
            background: #020617;
            border-radius: 1.25rem;
            border: 1px solid rgba(148, 163, 184, 0.35);
            padding: 2.25rem 2rem;
            box-shadow: 0 24px 60px rgba(15, 23, 42, 0.7);
        }
        h1 {
            font-size: 1.75rem;
            line-height: 1.25;
            font-weight: 700;
            margin: 0 0 0.75rem;
        }
        h2 {
            font-size: 1.1rem;
            margin: 2rem 0 0.75rem;
            font-weight: 600;
        }
        p {
            margin: 0.4rem 0;
            color: #e5e7eb;
            line-height: 1.6;
            font-size: 0.97rem;
        }
        ul {
            margin: 0.35rem 0 0.35rem 1.1rem;
            padding: 0;
            color: #e5e7eb;
            line-height: 1.6;
            font-size: 0.97rem;
        }
        li {
            margin: 0.15rem 0;
        }
        .tagline {
            font-size: 0.9rem;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            color: #9ca3af;
            margin-bottom: 0.4rem;
        }
        .highlight {
            color: #facc15;
            font-weight: 600;
        }
        .label {
            font-weight: 600;
            color: #f9fafb;
        }
        a {
            color: #facc15;
            text-decoration: underline;
            text-decoration-thickness: 1px;
            text-underline-offset: 3px;
        }
        .pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.2rem 0.7rem;
            border-radius: 999px;
            border: 1px solid rgba(148, 163, 184, 0.5);
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.09em;
            color: #9ca3af;
            margin-bottom: 1rem;
        }
        .pill-dot {
            width: 0.4rem;
            height: 0.4rem;
            border-radius: 999px;
            background: #facc15;
        }
        .section-muted {
            border-radius: 0.9rem;
            border: 1px solid rgba(55, 65, 81, 0.7);
            background: radial-gradient(circle at top left, rgba(234, 179, 8, 0.1), transparent),
                        radial-gradient(circle at bottom right, rgba(8, 47, 73, 0.9), #020617);
            padding: 1.1rem 1rem 1.1rem 1.1rem;
            margin-top: 1.4rem;
        }
        .section-muted h2 {
            margin-top: 0.1rem;
        }
        .small {
            font-size: 0.85rem;
            color: #9ca3af;
        }
        @media (max-width: 640px) {
            .card {
                padding: 1.75rem 1.4rem;
                border-radius: 1rem;
            }
            h1 {
                font-size: 1.4rem;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <main class="card" aria-labelledby="zivo-account-deletion-heading">
            <div class="pill">
                <span class="pill-dot"></span>
                Zivo account & data deletion
            </div>
            <p class="tagline">For users of the Zivo Android app published by the Zivo team.</p>
            <h1 id="zivo-account-deletion-heading">Requesting deletion of your Zivo account</h1>

            <p>
                This page explains how you can request deletion of your
                <span class="highlight">Zivo</span> account and the personal data associated
                with it. The process applies to the same Zivo creator/viewer account that you
                use to sign in to the Android app.
            </p>

            <h2>How to request deletion</h2>
            <p>To ask us to delete your account, please use one of the options below:</p>
            <ul>
                <li>
                    <span class="label">From inside the app:</span>
                    open the menu in the Zivo app and use the in‑app support or contact option to
                    send us a message with the subject “Delete my account”.
                </li>
                <li>
                    <span class="label">By email:</span>
                    contact us at
                    <a href="mailto:privacy@zivo.app">privacy@zivo.app</a>
                    from the email address linked to your Zivo account and clearly state that you
                    want your account and data deleted.
                </li>
            </ul>
            <p>
                We may ask you to confirm that you own the account (for example by replying from
                the registered email address or confirming recent activity).
            </p>

            <div class="section-muted">
                <h2>What will be deleted</h2>
                <p>When we complete your deletion request, we permanently delete:</p>
                <ul>
                    <li>Your Zivo account and login credentials (name, email address, password hash).</li>
                    <li>Creator profile details and business information you provided.</li>
                    <li>Location‑based media campaigns, quiz content, and other media you created,
                        where this is technically and contractually possible.</li>
                    <li>Device identifiers we store for notifications.</li>
                </ul>

                <h2>What may be retained and why</h2>
                <p>
                    Some information cannot be immediately deleted for legal, security, or
                    compliance reasons. We may retain, for a limited period:
                </p>
                <ul>
                    <li>
                        <span class="label">Payment and billing records</span> (for example, Stripe
                        transaction data) for up to <span class="highlight">7 years</span> as
                        required by tax and accounting laws.
                    </li>
                    <li>
                        <span class="label">Security, fraud‑prevention, and abuse logs</span> for up
                        to <span class="highlight">24 months</span>.
                    </li>
                    <li>
                        <span class="label">Anonymised analytics data</span> that no longer
                        identifies you personally.
                    </li>
                </ul>
            </div>

            <h2>How long deletion takes</h2>
            <p>
                We aim to respond to all verified deletion requests within
                <span class="highlight">30 days</span>. In some cases (for example, if we must
                remove or settle outstanding payouts) it may take longer, but we will keep you
                informed by email.
            </p>

            <p class="small">
                If you have questions about how your data is handled before or after deletion,
                you can reach us any time at
                <a href="mailto:privacy@zivo.app">privacy@zivo.app</a>.
            </p>
        </main>
    </div>
</body>
</html>

