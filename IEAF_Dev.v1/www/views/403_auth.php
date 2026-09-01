<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Authentication Error — ACCESS IEA Forms</title>
  <style>
    body { font-family: 'Segoe UI', system-ui, sans-serif; background: #F5F4F1;
           display: flex; align-items: center; justify-content: center;
           min-height: 100vh; margin: 0; }
    .box { text-align: center; max-width: 480px; padding: 2rem; }
    .topbar { background: #1B2A4A; color: #fff; padding: .4rem 1.5rem;
               font-size: .875rem; font-weight: 700; margin-bottom: 0; }
    h1 { font-size: 1.5rem; color: #B8002D; margin: 1rem 0 .5rem; }
    p  { color: #565450; margin-bottom: 1rem; line-height: 1.6; }
    a  { display: inline-block; margin-top: .5rem; padding: .6rem 1.4rem;
         background: #B8002D; color: #fff; text-decoration: none;
         border-radius: 4px; font-weight: 600; }
    a:hover { background: #8C0022; }
  </style>
</head>
<body>
  <div class="box">
    <h1>Authentication Error</h1>
    <p>Your login succeeded but we could not retrieve your session
       attributes. This can happen if your session expired mid-login.</p>
    <p>Please try signing in again. If the problem persists, contact
       <a href="mailto:myaccess@siue.edu" style="background:none;color:#B8002D;padding:0">
         myaccess@siue.edu</a>.</p>
    <a href="/mellon/login?ReturnTo=/">Sign in again</a>
  </div>
</body>
</html>
