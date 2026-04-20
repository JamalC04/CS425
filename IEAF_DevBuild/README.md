# ACCESS IEAF Portal — WCAG 2.1 AA Compliant Deployment Package

## What Was Fixed (Complete Audit Results)

All contrast ratios were mathematically verified using the WCAG 2.1 relative luminance formula.
All structural/semantic issues were verified by inspecting every PHP file.

### Contrast Failures Fixed (Criterion 1.4.3 / 1.4.11)

| Element | Before | After | Ratio |
|---------|--------|-------|-------|
| Nav link text on navy | rgba(255,255,255,.80) | #FFFFFF | 14.2:1 ✓ |
| Top bar subtitle on navy | rgba(255,255,255,.75) | #FFFFFF | 14.2:1 ✓ |
| Form hint text on white | #7A7970 | #565450 | 7.6:1 ✓ |
| Page subheading on off-white | #7A7970 | #565450 | 6.9:1 ✓ |
| Submitted status pill | #1B5EA8 on #E8F0FB | #0D4A8A on #D6E8F7 | 7.1:1 ✓ |
| Footer link text on navy | rgba(255,255,255,.80) | #FFFFFF | 14.2:1 ✓ |
| Input borders on white | #C4C3BF (1.7:1) | 2px #6B6A66 (5.4:1) | 5.4:1 ✓ |
| SIUE red (brand) | #CC0033 | #B8002D | 6.8:1 ✓ |

### Semantic / Structural Fixes

| File | Criterion | Fix |
|------|-----------|-----|
| header.php | 2.4.7 | Skip link uses clip-path:inset(50%) instead of top:-100% |
| header.php | 2.4.6 | aria-current="page" on active nav link |
| header.php | 1.3.1 | role="status" + aria-live on flash messages |
| ieaf_standard.php | 1.3.1 | Section headings are <h2>, not <div> |
| ieaf_standard.php | 1.3.1 | Required * is aria-hidden + sr-only "(required)" text |
| ieaf_standard.php | 4.1.2 | aria-required="true" on required inputs |
| ieaf_standard.php | 4.1.2 | Error messages linked via aria-describedby |
| ieaf_standard.php | 1.3.5 | autocomplete="name" / "email" on personal fields |
| ieaf_standard.php | 1.3.1 | Checkbox group in <fieldset><legend> |
| ieaf_essential.php | 1.3.1 | Section headings are <h2>, not <div> (10 sections) |
| ieaf_essential.php | 1.3.1 | ALL 6 yes/no radio groups in <fieldset><legend> |
| ieaf_essential.php | 1.3.1 | Required * is aria-hidden + sr-only text |
| ieaf_essential.php | 4.1.2 | aria-required="true" on required inputs |
| ieaf_essential.php | 4.1.2 | Error messages linked via aria-describedby |
| ieaf_essential.php | 1.3.5 | autocomplete="name" / "email" on personal fields |
| ieaf_essential.php | 1.3.1 | Checkbox group in <fieldset><legend> |
| admin/dashboard.php | 2.4.4 | "View"/"Approve"/"Reject" buttons have aria-label with course + faculty context |
| admin/dashboard.php | 1.3.1 | All <th> have scope="col" |
| admin/approve.php | 4.1.2 | Textarea has aria-required + aria-describedby |
| admin/approve.php | 1.3.1 | Required * is aria-hidden + sr-only text |
| student/dashboard.php | 2.4.4 | "View & Print" has aria-label with course name |
| student/dashboard.php | 1.3.1 | Table has aria-label |
| faculty/dashboard.php | 2.4.4 | All "View"/"Edit Draft"/"Start Form" buttons contextual |
| faculty/dashboard.php | 1.3.1 | Table has aria-label |
| history.php | 1.3.1 | All <th> have scope="col" + table has aria-label |
| style.css | 1.4.1 | Status pills use ○●✓✕ prefix (not color alone) |
| style.css | 2.5.5 | All buttons: min-height/min-width 44px |
| style.css | 1.4.4 | Base font sizes raised; no content below 0.875rem |
| style.css | 2.3.3 | prefers-reduced-motion disables all transitions |
| notifications.php | 1.3.1 | Toggles in <fieldset><legend>; labels linked via for= |
| notifications.php | 4.1.2 | aria-describedby on each toggle input |

## File Layout

```
deploy/
├── www/                     ← Copy entirely to /proj/www/
│   ├── schema.sql           ← Run once against dev, then prod
│   ├── index.php
│   ├── includes/
│   │   ├── auth.php         ← mod_auth_mellon SSO bridge
│   │   ├── db.php           ← MariaDB PDO connection
│   │   ├── mailer.php       ← PHPMailer + notification prefs check
│   │   ├── header.php       ← Shared WCAG-compliant header + nav
│   │   └── footer.php
│   ├── account/
│   │   └── notifications.php  ← Per-user email preference toggles
│   ├── forms/
│   │   ├── ieaf_standard.php
│   │   ├── ieaf_essential.php
│   │   └── history.php
│   ├── student/dashboard.php
│   ├── faculty/dashboard.php
│   ├── admin/
│   │   ├── dashboard.php
│   │   ├── approve.php
│   │   └── sync.php         ← Manual Banner sync + email log
│   ├── views/403.php
│   └── assets/css/style.css, assets/js/main.js
├── config/                  ← Copy to /proj/config/ (NOT web-accessible)
│   ├── db.php               ← Add password from /root/iaefuser_setup.sql
│   └── mail.php             ← Add SMTP app password from ITS
└── scripts/                 ← Copy to /proj/scripts/
    ├── sync_banner.py
    └── cron_notifications.php
```

## Deployment Steps

### Phase 1 — Dev

```bash
# 1. Copy files to server
rsync -avz ./www/     user@Server.Name:/proj/www/
rsync -avz ./scripts/ user@Server.Name:/proj/scripts/
scp ./config/db.php   user@Server.Name:/proj/config/db.php
scp ./config/mail.php user@Server.Name:/proj/config/mail.php

# 2. Edit credentials on server
nano /proj/config/db.php    # add password from /root/iaefuser_setup.sql
nano /proj/config/mail.php  # add SMTP app password from ITS

# 3. Run schema against dev
mysql -u iaefuser -p dev < /proj/www/schema.sql

# 4. Promote yourself to admin (after first login)
mysql -u iaefuser -p dev -e "UPDATE users SET role='admin' WHERE email='you@siue.edu';"

# 5. Install PHPMailer
cd /proj && composer require phpmailer/phpmailer

# 6. Install Python dependencies
pip3 install requests pymysql --break-system-packages

# 7. Set permissions
sudo chown -R apache:apache /proj/www/
sudo find /proj/www/ -type f -exec chmod 644 {} \;
sudo find /proj/www/ -type d -exec chmod 755 {} \;

# 8. Test Banner sync
python3 /proj/scripts/sync_banner.py --list-terms
python3 /proj/scripts/sync_banner.py --term 202530 --dry-run
python3 /proj/scripts/sync_banner.py --term 202530 --db dev

# 9. Add cron jobs (crontab -e as root)
# 0 3 1 1,5,8 * /usr/bin/python3 /proj/scripts/sync_banner.py >> /proj/logs/sync_banner.log 2>&1
# 0 9 * * 1,3,5 /usr/bin/php /proj/scripts/cron_notifications.php --job=draft_reminder >> /proj/logs/cron.log 2>&1
# 0 8 * * 1 /usr/bin/php /proj/scripts/cron_notifications.php --job=weekly_digest >> /proj/logs/cron.log 2>&1
```

### Phase 2 — Production (after dev testing passes)

```bash
# Switch database
sed -i "s/'dbname' => 'dev'/'dbname' => 'prod'/" /proj/config/db.php

# Run schema against prod
mysql -u iaefuser -p prod < /proj/www/schema.sql

# Seed admin in prod
mysql -u iaefuser -p prod -e "UPDATE users SET role='admin' WHERE email='you@siue.edu';"

# Sync Banner data into prod
python3 /proj/scripts/sync_banner.py --term 202530 --db prod
```

## Recommended Post-Deploy WCAG Testing

Run these tools on the live dev URL before approving for prod:

1. **axe DevTools** (Chrome/Firefox extension) — target zero critical/serious issues
2. **Keyboard navigation** — Tab through every form completely without touching the mouse
3. **Windows Narrator or macOS VoiceOver** — verify form labels, error announcements, and status changes are announced correctly
4. **200% browser zoom** — no content loss, no horizontal scrollbar on main content area
5. **Print preview** on each IEAF form — nav, buttons, and alerts should not appear
