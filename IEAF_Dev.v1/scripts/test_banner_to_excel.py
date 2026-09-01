#!/usr/bin/env python3
"""
test_banner_to_excel.py
────────────────────────
Standalone test script — fetches course data from SIUE Banner SSB
for a given term and writes it to an Excel file.

Run this to verify Banner connectivity BEFORE deploying the portal.
No database connection required. No .env file required.

Usage:
    python3 test_banner_to_excel.py
    python3 test_banner_to_excel.py --term 202530
    python3 test_banner_to_excel.py --term 202510 --out fall2025.xlsx
    python3 test_banner_to_excel.py --list-terms

Output:
    Banner_Courses_<term>.xlsx  (in the same directory as this script)
    Contains two sheets:
      - Courses  : one row per section with CRN, subject, number, title,
                   section, credits, campus, schedule type, instructor
      - Instructors : unique instructor list with name and email

Dependencies (install once):
    pip install requests openpyxl

    OR on the server:
    pip3 install requests openpyxl --break-system-packages
"""

import argparse
import sys
import time
from datetime import datetime
from pathlib import Path

try:
    import requests
except ImportError:
    sys.exit("Missing 'requests'. Run: pip install requests")

try:
    import openpyxl
    from openpyxl.styles import Font, PatternFill, Alignment, Border, Side
    from openpyxl.utils import get_column_letter
except ImportError:
    sys.exit("Missing 'openpyxl'. Run: pip install openpyxl")


# ── Banner SSB config ──────────────────────────────────────────────────────
BANNER_BASE   = "https://banner.siue.edu/StudentRegistrationSsb/ssb"
PAGE_SIZE     = 500
REQUEST_DELAY = 0.4
TIMEOUT       = 30

# SIUE brand colours (for Excel header rows)
SIUE_RED   = "B8002D"
SIUE_NAVY  = "1B2A4A"
WHITE      = "FFFFFF"
LIGHT_GRAY = "F5F4F1"


# ── HTTP helpers ───────────────────────────────────────────────────────────
def make_session() -> requests.Session:
    s = requests.Session()
    s.headers.update({
        "User-Agent":       "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36",
        "Accept":           "application/json, text/javascript, */*; q=0.01",
        "Referer":          f"{BANNER_BASE}/term/termSelection?mode=search",
        "X-Requested-With": "XMLHttpRequest",
    })
    return s


def get_terms(session: requests.Session) -> list[dict]:
    """Return list of available terms from Banner SSB."""
    print("  → Warming up session on term selection page…")
    # Load term selection page to initialize session cookies
    session.get(f"{BANNER_BASE}/term/termSelection?mode=search", timeout=TIMEOUT)
    time.sleep(0.3)
    
    # Query the terms endpoint called by the term selection page
    r = session.get(
        f"{BANNER_BASE}/classSearch/getTerms",
        params={"searchTerm": "", "offset": 1, "max": 20},
        timeout=TIMEOUT,
    )
    r.raise_for_status()
    return r.json()


def set_term(session: requests.Session, term_code: str) -> None:
    """Post term selection to establish Banner session context."""
    print(f"  → Setting term session context to {term_code}…")
    r = session.post(
        f"{BANNER_BASE}/term/search",
        params={"mode": "search"},
        data={"term": term_code},
        timeout=TIMEOUT,
    )
    r.raise_for_status()


def fetch_page(session: requests.Session, term_code: str, offset: int) -> dict:
    # First page fetch needs a quick reset/search execution parameter
    params = {
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
    }
    
    r = session.get(
        f"{BANNER_BASE}/searchResults/searchResults",
        params=params,
        timeout=TIMEOUT,
    )
    r.raise_for_status()
    return r.json()


def fetch_all(session: requests.Session, term_code: str) -> list[dict]:
    """Page through all course sections for a term."""
    sections = []
    offset   = 0
    print("  → Fetching course sections…")
    while True:
        data  = fetch_page(session, term_code, offset)
        page  = data.get("data") or []
        total = data.get("totalCount", 0)
        if not page:
            break
        sections.extend(page)
        print(f"     {len(sections):,} / {total:,}", end="\r", flush=True)
        if len(sections) >= total:
            break
        offset += PAGE_SIZE
        time.sleep(REQUEST_DELAY)
    print()
    return sections


# ── Data extraction ────────────────────────────────────────────────────────
def get_primary_instructor(section: dict) -> dict:
    """Extract the primary instructor's details from a section record."""
    faculty = section.get("faculty") or []
    primary = next((f for f in faculty if f.get("primaryIndicator")), None)
    if not primary and faculty:
        primary = faculty[0]
    if not primary:
        return {"name": "", "email": ""}

    display = primary.get("displayName") or ""
    email   = (primary.get("emailAddress") or "").strip().lower()

    if "," in display:
        surname, _, given = display.partition(",")
        name = f"{given.strip()} {surname.strip()}"
    else:
        name = display.strip()

    return {"name": name, "email": email}


def flatten_section(section: dict) -> dict:
    """Convert a Banner section record into a flat dict for Excel."""
    instructor = get_primary_instructor(section)
    meetings   = section.get("meetingsFaculty") or []
    first_mtg  = meetings[0].get("meetingTime", {}) if meetings else {}

    days = "".join([
        ("M" if first_mtg.get("monday")    else ""),
        ("T" if first_mtg.get("tuesday")   else ""),
        ("W" if first_mtg.get("wednesday") else ""),
        ("R" if first_mtg.get("thursday")  else ""),
        ("F" if first_mtg.get("friday")    else ""),
        ("S" if first_mtg.get("saturday")  else ""),
    ])

    return {
        "CRN":              section.get("courseReferenceNumber", ""),
        "Subject":          section.get("subject", ""),
        "Course Number":    section.get("courseNumber", ""),
        "Section":          section.get("sequenceNumber", ""),
        "Title":            section.get("courseTitle", ""),
        "Credits":          section.get("creditHours") or section.get("creditHourHigh", ""),
        "Campus":           section.get("campusDescription", ""),
        "Schedule Type":    section.get("scheduleTypeDescription", ""),
        "Instruction Method": section.get("instructionalMethodDescription", ""),
        "Enrollment":       section.get("enrollment", 0),
        "Max Enrollment":   section.get("maximumEnrollment", 0),
        "Days":             days,
        "Start Time":       first_mtg.get("beginTime", ""),
        "End Time":         first_mtg.get("endTime", ""),
        "Building":         first_mtg.get("building", ""),
        "Room":             first_mtg.get("room", ""),
        "Term":             section.get("term", ""),
        "Instructor Name":  instructor["name"],
        "Instructor Email": instructor["email"],
    }


# ── Excel helpers ──────────────────────────────────────────────────────────
def _hdr_style(cell, bg: str = SIUE_NAVY) -> None:
    cell.font      = Font(color=WHITE, bold=True, size=10)
    cell.fill      = PatternFill("solid", fgColor=bg)
    cell.alignment = Alignment(horizontal="center", vertical="center", wrap_text=True)
    thin = Side(style="thin", color="CCCCCC")
    cell.border = Border(bottom=thin)


def _row_style(cell, even: bool) -> None:
    cell.fill      = PatternFill("solid", fgColor=(LIGHT_GRAY if even else WHITE))
    cell.alignment = Alignment(vertical="center")
    cell.font      = Font(size=10)


def auto_width(ws) -> None:
    for col in ws.columns:
        max_len = 0
        col_letter = get_column_letter(col[0].column)
        for cell in col:
            try:
                max_len = max(max_len, len(str(cell.value or "")))
            except Exception:
                pass
        ws.column_dimensions[col_letter].width = min(max(max_len + 2, 8), 45)


def build_courses_sheet(ws, rows: list[dict]) -> None:
    if not rows:
        return
    headers = list(rows[0].keys())
    ws.row_dimensions[1].height = 30
    for ci, h in enumerate(headers, 1):
        cell = ws.cell(row=1, column=ci, value=h)
        _hdr_style(cell)

    for ri, row in enumerate(rows, 2):
        even = ri % 2 == 0
        for ci, key in enumerate(headers, 1):
            cell = ws.cell(row=ri, column=ci, value=row.get(key, ""))
            _row_style(cell, even)

    ws.freeze_panes = "A2"
    ws.auto_filter.ref = ws.dimensions
    auto_width(ws)


def build_instructors_sheet(ws, rows: list[dict]) -> None:
    seen   = {}
    for r in rows:
        name  = r.get("Instructor Name",  "").strip()
        email = r.get("Instructor Email", "").strip()
        if email and email not in seen:
            seen[email] = name

    headers = ["Instructor Name", "Instructor Email"]
    ws.row_dimensions[1].height = 25
    for ci, h in enumerate(headers, 1):
        cell = ws.cell(row=1, column=ci, value=h)
        _hdr_style(cell, bg=SIUE_RED)

    for ri, (email, name) in enumerate(sorted(seen.items(), key=lambda x: x[1]), 2):
        even = ri % 2 == 0
        for ci, val in enumerate([name, email], 1):
            cell = ws.cell(row=ri, column=ci, value=val)
            _row_style(cell, even)

    ws.freeze_panes = "A2"
    auto_width(ws)


# ── Term helpers ───────────────────────────────────────────────────────────
def term_label(code: str) -> str:
    season = {"10": "Fall", "20": "Spring", "30": "Summer"}.get(code[4:], code[4:])
    return f"{season} {code[:4]}"


def auto_detect_term() -> str:
    m, y = datetime.now().month, datetime.now().year
    if m <= 4:   return f"{y}20"
    elif m <= 7: return f"{y}30"
    else:        return f"{y+1}10"


# ── Main ───────────────────────────────────────────────────────────────────
def main():
    parser = argparse.ArgumentParser(
        description="Fetch Banner SSB courses and save to Excel",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog=__doc__,
    )
    parser.add_argument("--term",       help="6-digit term code, e.g. 202530")
    parser.add_argument("--out",        help="Output filename (default: Banner_Courses_<term>.xlsx)")
    parser.add_argument("--list-terms", action="store_true", help="List available terms and exit")
    args = parser.parse_args()

    session = make_session()

    # ── List terms mode ────────────────────────────────────────────────────
    if args.list_terms:
        print("\nFetching available terms from Banner SSB…\n")
        try:
            terms = get_terms(session)
        except Exception as e:
            sys.exit(f"ERROR: Could not reach Banner SSB — {e}")
        print(f"{'Code':<10}  Description")
        print("-" * 50)
        for t in terms:
            print(f"  {t['code']:<8}  {t['description']}")
        print()
        sys.exit(0)

    # ── Determine term ─────────────────────────────────────────────────────
    term_code = args.term or auto_detect_term()
    if len(term_code) != 6 or not term_code.isdigit():
        sys.exit("Term code must be 6 digits, e.g. 202530")

    out_file = args.out or f"Banner_Courses_{term_code}.xlsx"

    print(f"\nACCESS IEAF — Banner → Excel Test")
    print(f"{'─'*40}")
    print(f"Term:    {term_code}  ({term_label(term_code)})")
    print(f"Output:  {out_file}")
    print(f"{'─'*40}\n")

    # ── Fetch ──────────────────────────────────────────────────────────────
    try:
        get_terms(session)
        set_term(session, term_code)
        time.sleep(0.5)
        sections = fetch_all(session, term_code)
    except requests.exceptions.ConnectionError:
        sys.exit("ERROR: Cannot reach Banner SSB. Check your network connection or VPN.")
    except requests.exceptions.Timeout:
        sys.exit("ERROR: Request to Banner SSB timed out.")
    except requests.exceptions.HTTPError as e:
        sys.exit(f"ERROR: Banner SSB returned HTTP error — {e}")
    except Exception as e:
        sys.exit(f"ERROR: {e}")

    if not sections:
        print("No sections returned for this term.")
        print("  • Try --list-terms to see available term codes.")
        print("  • The term may not be open for registration yet.")
        sys.exit(0)

    print(f"\nFetched {len(sections):,} sections total.\n")

    # ── Flatten ────────────────────────────────────────────────────────────
    rows = [flatten_section(s) for s in sections]

    # ── Build Excel ────────────────────────────────────────────────────────
    print("Building Excel workbook…")
    wb = openpyxl.Workbook()

    # Sheet 1 — Courses
    ws_courses = wb.active
    ws_courses.title = "Courses"
    build_courses_sheet(ws_courses, rows)

    # Sheet 2 — Instructors
    ws_inst = wb.create_sheet("Instructors")
    build_instructors_sheet(ws_inst, rows)

    # Workbook metadata
    wb.properties.title   = f"Banner Courses {term_label(term_code)}"
    wb.properties.creator = "ACCESS IEAF Portal — Banner Sync Test"

    wb.save(out_file)

    # ── Summary ────────────────────────────────────────────────────────────
    unique_instructors = len({r["Instructor Email"] for r in rows if r["Instructor Email"]})
    unique_courses     = len({(r["Subject"], r["Course Number"]) for r in rows})

    print(f"\n{'='*40}")
    print(f"  Sections:    {len(rows):>6,}")
    print(f"  Unique courses: {unique_courses:>4,}")
    print(f"  Instructors: {unique_instructors:>6,}")
    print(f"{'='*40}")
    print(f"\n  Saved → {Path(out_file).resolve()}\n")
    print("If the file opened correctly, Banner connectivity is confirmed ✓")
    print("You can now run sync_banner.py against the database.\n")


if __name__ == "__main__":
    main()
