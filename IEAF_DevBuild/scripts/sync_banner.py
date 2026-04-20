#!/usr/bin/env python3
"""
/proj/scripts/sync_banner.py
────────────────────────────
Fetches all courses + instructors from SIUE's Banner SSB for a given term
and upserts them into the MariaDB `courses` and `users` tables.

Usage:
    python3 sync_banner.py                   # auto-detects upcoming term
    python3 sync_banner.py --term 202530     # Spring 2025 explicitly
    python3 sync_banner.py --term 202510     # Fall 2025
    python3 sync_banner.py --list-terms      # show available terms

Term code format: YYYYTT
    TT = 10 (Fall), 20 (Spring), 30 (Summer)

Cron (runs 1st of Jan, May, Aug at 3am):
    0 3 1 1,5,8 * /usr/bin/python3 /proj/scripts/sync_banner.py >> /proj/logs/sync_banner.log 2>&1

Dependencies (install once):
    pip3 install requests pymysql --break-system-packages
"""

import argparse
import json
import logging
import sys
import time
from datetime import datetime

import pymysql
import requests

# ── Config ────────────────────────────────────────────────────────────────
BANNER_BASE   = "https://banner.siue.edu/StudentRegistrationSsb/ssb"
DB_CONFIG     = {
    "host":    "127.0.0.1",
    "port":    3306,
    "db":      "prod",      # change to "dev" for testing
    "user":    "iaefuser",
    "passwd":  "",          # fill in from /root/iaefuser_setup.sql
    "charset": "utf8mb4",
}
PAGE_SIZE     = 500          # records per API page (Banner max is usually 500)
REQUEST_DELAY = 0.4          # seconds between paginated requests (be polite)
TIMEOUT       = 30           # HTTP timeout

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)
log = logging.getLogger(__name__)


def get_session() -> requests.Session:
    """Return a requests Session with browser-like headers."""
    s = requests.Session()
    s.headers.update({
        "User-Agent": "Mozilla/5.0 (compatible; ACCESS-IEAF-sync/1.0)",
        "Accept":     "application/json, text/javascript, */*; q=0.01",
        "Referer":    f"{BANNER_BASE}/classSearch/classSearch",
        "X-Requested-With": "XMLHttpRequest",
    })
    return s


def fetch_terms(session: requests.Session) -> list[dict]:
    """
    Retrieve available terms from Banner SSB.
    Returns list of dicts: [{"code": "202530", "description": "Spring 2025"}, ...]
    """
    # Step 1: Banner SSB requires a GET to the main page first to get a session cookie
    session.get(f"{BANNER_BASE}/classSearch/classSearch", timeout=TIMEOUT)
    time.sleep(0.2)

    # Step 2: Fetch available terms
    r = session.get(
        f"{BANNER_BASE}/classSearch/get_terms",
        params={"searchTerm": "", "offset": 1, "max": 20},
        timeout=TIMEOUT,
    )
    r.raise_for_status()
    data = r.json()
    # Response: [{"code": "202530", "description": "Spring 2025 (View only)"}, ...]
    return [{"code": t["code"], "description": t["description"]} for t in data]


def set_term(session: requests.Session, term_code: str) -> None:
    """
    Banner SSB requires POSTing the term selection to establish a session context
    before classSearch endpoints will return results for that term.
    """
    r = session.post(
        f"{BANNER_BASE}/term/search",
        params={"mode": "search"},
        data={"term": term_code, "studyPath": "", "studyPathText": "", "startDatepicker": "", "endDatepicker": ""},
        timeout=TIMEOUT,
    )
    r.raise_for_status()
    log.info(f"Term set to {term_code} (status {r.status_code})")


def fetch_page(session: requests.Session, term_code: str, offset: int) -> dict:
    """Fetch one page of course search results."""
    r = session.get(
        f"{BANNER_BASE}/searchResults/searchResults",
        params={
            "term":            term_code,
            "offset":          offset,
            "max":             PAGE_SIZE,
            "pageOffset":      offset,
            "pageMaxSize":     PAGE_SIZE,
            "sortColumn":      "subjectDescription",
            "sortDirection":   "asc",
        },
        timeout=TIMEOUT,
    )
    r.raise_for_status()
    return r.json()


def fetch_all_sections(session: requests.Session, term_code: str) -> list[dict]:
    """Page through all sections for the given term."""
    all_sections = []
    offset = 0

    while True:
        log.info(f"Fetching offset {offset}...")
        data = fetch_page(session, term_code, offset)

        sections = data.get("data", [])
        if not sections:
            break

        all_sections.extend(sections)
        total = data.get("totalCount", 0)
        log.info(f"  Got {len(sections)} sections (total so far: {len(all_sections)} / {total})")

        if len(all_sections) >= total:
            break

        offset += PAGE_SIZE
        time.sleep(REQUEST_DELAY)

    return all_sections


def parse_instructor_email(section: dict) -> tuple[str, str, str]:
    """
    Extract the primary instructor's name and email from a section dict.
    Banner SSB returns faculty as a list; (P) marks the primary instructor.

    Returns (given_name, surname, email) or ("", "", "") if none found.
    """
    faculty_list = section.get("faculty", []) or []

    primary = None
    for f in faculty_list:
        if f.get("primaryIndicator"):
            primary = f
            break
    if not primary and faculty_list:
        primary = faculty_list[0]
    if not primary:
        return "", "", ""

    display = primary.get("displayName", "") or ""
    email   = (primary.get("emailAddress") or "").lower().strip()

    # displayName is usually "Last, First" — split it
    if "," in display:
        surname, _, given = display.partition(",")
        given_name = given.strip()
        surname    = surname.strip()
    else:
        parts      = display.strip().split()
        given_name = parts[0] if parts else ""
        surname    = parts[-1] if len(parts) > 1 else ""

    return given_name, surname, email


def term_label(term_code: str) -> str:
    """Convert '202530' → 'Spring2025'."""
    year = term_code[:4]
    tt   = term_code[4:]
    season = {"10": "Fall", "20": "Spring", "30": "Summer"}.get(tt, tt)
    return f"{season}{year}"


def upsert_faculty(conn, given_name: str, surname: str, email: str) -> int | None:
    """
    Ensure a faculty user row exists. Returns user_id.
    If email is blank, returns None.
    """
    if not email:
        return None

    with conn.cursor() as cur:
        # Try to find existing user
        cur.execute("SELECT user_id, role FROM users WHERE email = %s", (email,))
        row = cur.fetchone()
        if row:
            # Upgrade to faculty if they were auto-provisioned as student
            if row["role"] == "student":
                cur.execute(
                    "UPDATE users SET role='faculty', given_name=%s, surname=%s, updated_at=NOW() WHERE user_id=%s",
                    (given_name, surname, row["user_id"]),
                )
                log.debug(f"  Upgraded {email} to faculty")
            return row["user_id"]

        # Insert new faculty user
        cur.execute(
            """INSERT INTO users (email, given_name, surname, role)
               VALUES (%s, %s, %s, 'faculty')
               ON DUPLICATE KEY UPDATE
                 given_name = VALUES(given_name),
                 surname    = VALUES(surname),
                 role       = IF(role = 'student', 'faculty', role),
                 updated_at = NOW()""",
            (email, given_name, surname),
        )
        cur.execute("SELECT user_id FROM users WHERE email = %s", (email,))
        return cur.fetchone()["user_id"]


def upsert_course(conn, section: dict, term: str, faculty_id: int | None) -> None:
    """Insert or update a course row."""
    crn         = str(section.get("courseReferenceNumber", "")).strip()
    subject     = section.get("subject", "").strip()
    course_num  = section.get("courseNumber", "").strip()
    title       = section.get("courseTitle", "").strip()
    sec_num     = section.get("sequenceNumber", "").strip()

    if not crn:
        return

    course_name = f"{subject} {course_num} - {title}" if subject and course_num else title

    with conn.cursor() as cur:
        cur.execute(
            """INSERT INTO courses (crn, course_name, section, term, faculty_id, active)
               VALUES (%s, %s, %s, %s, %s, 1)
               ON DUPLICATE KEY UPDATE
                 course_name = VALUES(course_name),
                 section     = VALUES(section),
                 faculty_id  = VALUES(faculty_id),
                 active      = 1""",
            (crn, course_name, sec_num, term, faculty_id),
        )


def deactivate_old_courses(conn, term: str, active_crns: set[str]) -> int:
    """Mark courses in this term that weren't in the fresh pull as inactive."""
    if not active_crns:
        return 0
    placeholders = ",".join(["%s"] * len(active_crns))
    with conn.cursor() as cur:
        cur.execute(
            f"UPDATE courses SET active=0 WHERE term=%s AND crn NOT IN ({placeholders})",
            [term] + list(active_crns),
        )
        return cur.rowcount


def auto_detect_term() -> str:
    """
    Pick the next upcoming term based on today's date.
    Jan–Apr → Spring (20), May–Jul → Summer (30), Aug–Dec → Fall (10).
    """
    now = datetime.now()
    m   = now.month
    y   = now.year
    if m <= 4:
        return f"{y}20"   # Spring
    elif m <= 7:
        return f"{y}30"   # Summer
    else:
        return f"{y+1}10" # Next Fall (register in fall for next fall)


def main():
    parser = argparse.ArgumentParser(description="Sync SIUE Banner courses into MariaDB")
    parser.add_argument("--term",       help="Term code e.g. 202530 (Spring 2025)")
    parser.add_argument("--list-terms", action="store_true", help="List available terms and exit")
    parser.add_argument("--dry-run",    action="store_true", help="Fetch but don't write to DB")
    parser.add_argument("--db",         default=DB_CONFIG["db"], help="Database name (dev or prod)")
    args = parser.parse_args()

    DB_CONFIG["db"] = args.db

    session = get_session()

    # ── List terms mode ───────────────────────────────────────────────────
    if args.list_terms:
        terms = fetch_terms(session)
        print("\nAvailable terms:")
        for t in terms:
            print(f"  {t['code']}  {t['description']}")
        print()
        sys.exit(0)

    # ── Determine term ────────────────────────────────────────────────────
    term_code = args.term or auto_detect_term()
    term_label_str = term_label(term_code)
    log.info(f"Starting Banner sync for term {term_code} ({term_label_str})")

    # ── Fetch data ────────────────────────────────────────────────────────
    fetch_terms(session)          # warms up session cookie
    set_term(session, term_code)  # establishes term context in session
    time.sleep(0.5)

    sections = fetch_all_sections(session, term_code)
    log.info(f"Total sections fetched: {len(sections)}")

    if args.dry_run:
        log.info("DRY RUN — not writing to database.")
        # Print a sample
        for s in sections[:5]:
            gn, sn, em = parse_instructor_email(s)
            print(f"  CRN {s.get('courseReferenceNumber')} | {s.get('subject')} {s.get('courseNumber')} | {gn} {sn} <{em}>")
        sys.exit(0)

    # ── Write to DB ───────────────────────────────────────────────────────
    conn = pymysql.connect(**DB_CONFIG, cursorclass=pymysql.cursors.DictCursor, autocommit=False)
    active_crns: set[str] = set()

    try:
        for section in sections:
            given_name, surname, email = parse_instructor_email(section)
            faculty_id = upsert_faculty(conn, given_name, surname, email)
            upsert_course(conn, section, term_label_str, faculty_id)
            crn = str(section.get("courseReferenceNumber", "")).strip()
            if crn:
                active_crns.add(crn)

        removed = deactivate_old_courses(conn, term_label_str, active_crns)
        conn.commit()
        log.info(f"Sync complete. {len(active_crns)} active courses, {removed} deactivated.")

    except Exception as e:
        conn.rollback()
        log.error(f"DB error — rolled back: {e}")
        sys.exit(1)
    finally:
        conn.close()


if __name__ == "__main__":
    main()
