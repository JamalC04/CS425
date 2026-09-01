#!/usr/bin/env python3
"""
/proj/scripts/sync_banner.py
─────────────────────────────
Fetches all courses + instructors from SIUE Banner SSB for a given term
and upserts them into the MariaDB courses and users tables.

DB credentials are hardcoded below — this file lives at /proj/scripts/ outside the web root.

Usage:
    python3 sync_banner.py                   # auto-detects upcoming term
    python3 sync_banner.py --term 202530     # Spring 2025
    python3 sync_banner.py --term 202510     # Fall 2025
    python3 sync_banner.py --list-terms      # show available terms
    python3 sync_banner.py --term 202530 --dry-run   # fetch only, no DB write

Term code format: YYYYTT
    TT = 10 (Fall), 20 (Spring), 30 (Summer)

Cron (runs 1st of Jan, May, Aug at 3am):
    0 3 1 1,5,8 * /usr/bin/python3 /proj/scripts/sync_banner.py >> /proj/logs/sync_banner.log 2>&1

Dependencies:
    pip3 install requests pymysql openpyxl --break-system-packages
"""

import argparse
import json
import logging
import os
import sys
import time
from datetime import datetime
from pathlib import Path

import pymysql
import requests

# ── Database config ───────────────────────────────────────────────────────
# ── Database config ────────────────────────────────────────────────────────
# Matches /proj/config/db.php exactly.
# To switch dev → prod: change 'dev' to 'prod' in DB_CONFIG below.
DB_CONFIG = {
    'host':    'localhost',
    'port':    3306,
    'db':      'dev',
    'user':    'iaefuser',
    'passwd':  '9-skippers-flame-pencil-3-thunder-BRISK',
    'charset': 'utf8mb4',
}

# ── Banner config ─────────────────────────────────────────────────────────
BANNER_BASE   = "https://banner.siue.edu/StudentRegistrationSsb/ssb"
PAGE_SIZE     = 500
REQUEST_DELAY = 0.4
TIMEOUT       = 30

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)
log = logging.getLogger(__name__)


# ── HTTP session ───────────────────────────────────────────────────────────
def get_session() -> requests.Session:
    s = requests.Session()
    s.headers.update({
        "User-Agent":       "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36",
        "Accept":           "application/json, text/javascript, */*; q=0.01",
        "Referer":          f"{BANNER_BASE}/term/termSelection?mode=search",
        "X-Requested-With": "XMLHttpRequest",
    })
    return s


def fetch_terms(session: requests.Session) -> list[dict]:
    session.get(f"{BANNER_BASE}/term/termSelection?mode=search", timeout=TIMEOUT)
    time.sleep(0.3)
    r = session.get(
        f"{BANNER_BASE}/classSearch/getTerms",
        params={"searchTerm": "", "offset": 1, "max": 20},
        timeout=TIMEOUT,
    )
    r.raise_for_status()
    return [{"code": t["code"], "description": t["description"]} for t in r.json()]


def set_term(session: requests.Session, term_code: str) -> None:
    r = session.post(
        f"{BANNER_BASE}/term/search",
        params={"mode": "search"},
        data={"term": term_code},
        timeout=TIMEOUT,
    )
    r.raise_for_status()
    log.info(f"Term set to {term_code}")


def fetch_page(session: requests.Session, term_code: str, offset: int) -> dict:
    r = session.get(
        f"{BANNER_BASE}/searchResults/searchResults",
        params={
            "txt_term":        term_code,
            "term":            term_code,
            "startDateBefore": "",
            "startDateAfter":  "",
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
    sections = []
    offset   = 0
    while True:
        log.info(f"  Fetching offset {offset}…")
        data = fetch_page(session, term_code, offset)
        page = data.get("data", [])
        if not page:
            break
        sections.extend(page)
        total = data.get("totalCount", 0)
        log.info(f"  {len(sections)} / {total}")
        if len(sections) >= total:
            break
        offset += PAGE_SIZE
        time.sleep(REQUEST_DELAY)
    return sections


def parse_instructor(section: dict) -> tuple[str, str, str]:
    faculty = section.get("faculty") or []
    primary = next((f for f in faculty if f.get("primaryIndicator")), None) or (faculty[0] if faculty else None)
    if not primary:
        return "", "", ""
    display = primary.get("displayName", "") or ""
    email   = (primary.get("emailAddress") or "").lower().strip()
    if "," in display:
        surname, _, given = display.partition(",")
        return given.strip(), surname.strip(), email
    parts = display.strip().split()
    return (parts[0] if parts else ""), (parts[-1] if len(parts) > 1 else ""), email


def term_label(code: str) -> str:
    season = {"10": "Fall", "20": "Spring", "30": "Summer"}.get(code[4:], code[4:])
    return f"{season}{code[:4]}"


def auto_detect_term() -> str:
    m = datetime.now().month
    y = datetime.now().year
    if m <= 4:   return f"{y}20"
    elif m <= 7: return f"{y}30"
    else:        return f"{y+1}10"


# ── DB helpers ─────────────────────────────────────────────────────────────
def upsert_faculty(conn, first: str, last: str, email: str) -> int | None:
    if not email:
        return None
    with conn.cursor() as cur:
        cur.execute("SELECT user_id, role FROM users WHERE email=%s", (email,))
        row = cur.fetchone()
        if row:
            if row["role"] == "student":
                cur.execute("UPDATE users SET role='faculty',given_name=%s,surname=%s,updated_at=NOW() WHERE user_id=%s",
                            (first, last, row["user_id"]))
            return row["user_id"]
        cur.execute(
            "INSERT INTO users (email,given_name,surname,role) VALUES (%s,%s,%s,'faculty') "
            "ON DUPLICATE KEY UPDATE given_name=VALUES(given_name),surname=VALUES(surname),"
            "role=IF(role='student','faculty',role),updated_at=NOW()",
            (email, first, last)
        )
        cur.execute("SELECT user_id FROM users WHERE email=%s", (email,))
        return cur.fetchone()["user_id"]


def upsert_course(conn, section: dict, term: str, faculty_id: int | None) -> None:
    crn    = str(section.get("courseReferenceNumber", "")).strip()
    subj   = section.get("subject", "").strip()
    num    = section.get("courseNumber", "").strip()
    title  = section.get("courseTitle", "").strip()
    sec    = section.get("sequenceNumber", "").strip()
    if not crn:
        return
    name = f"{subj} {num} - {title}".strip(" -") if subj and num else title
    with conn.cursor() as cur:
        cur.execute(
            "INSERT INTO courses (crn,course_name,section,term,faculty_id,active) VALUES (%s,%s,%s,%s,%s,1) "
            "ON DUPLICATE KEY UPDATE course_name=VALUES(course_name),section=VALUES(section),"
            "faculty_id=VALUES(faculty_id),active=1",
            (crn, name, sec, term, faculty_id)
        )


# ── Main ───────────────────────────────────────────────────────────────────
def main():
    parser = argparse.ArgumentParser(description="Sync SIUE Banner courses into MariaDB")
    parser.add_argument("--term",       help="Term code e.g. 202530")
    parser.add_argument("--list-terms", action="store_true")
    parser.add_argument("--dry-run",    action="store_true", help="Fetch but do not write to DB")
    parser.add_argument("--db",         default=None, help="Override dbname (default: dev)")
    args = parser.parse_args()

    if args.db:
        DB_CONFIG["db"] = args.db

    session = get_session()

    if args.list_terms:
        terms = fetch_terms(session)
        print("\nAvailable terms:")
        for t in terms:
            print(f"  {t['code']}  {t['description']}")
        print()
        sys.exit(0)

    term_code = args.term or auto_detect_term()
    log.info(f"Syncing term {term_code} ({term_label(term_code)}) — db={DB_CONFIG['db']}")

    fetch_terms(session)
    set_term(session, term_code)
    time.sleep(0.5)

    sections = fetch_all_sections(session, term_code)
    log.info(f"Total sections fetched: {len(sections)}")

    if args.dry_run:
        log.info("DRY RUN — no DB writes")
        for s in sections[:5]:
            gn, sn, em = parse_instructor(s)
            print(f"  {s.get('courseReferenceNumber')} | {s.get('subject')} {s.get('courseNumber')} — {gn} {sn} <{em}>")
        sys.exit(0)

    conn = pymysql.connect(**DB_CONFIG, cursorclass=pymysql.cursors.DictCursor, autocommit=False)
    active_crns: set[str] = set()
    try:
        for section in sections:
            gn, sn, em = parse_instructor(section)
            fid = upsert_faculty(conn, gn, sn, em)
            upsert_course(conn, section, term_label(term_code), fid)
            crn = str(section.get("courseReferenceNumber", "")).strip()
            if crn:
                active_crns.add(crn)

        # Deactivate courses in this term not in the fresh pull
        if active_crns:
            ph = ",".join(["%s"] * len(active_crns))
            with conn.cursor() as cur:
                cur.execute(
                    f"UPDATE courses SET active=0 WHERE term=%s AND crn NOT IN ({ph})",
                    [term_label(term_code)] + list(active_crns)
                )
        conn.commit()
        log.info(f"Done — {len(active_crns)} active courses upserted.")
    except Exception as e:
        conn.rollback()
        log.error(f"DB error — rolled back: {e}")
        sys.exit(1)
    finally:
        conn.close()


if __name__ == "__main__":
    main()
