import json
import re
import sys


def extract_text(path):
    try:
        import pdfplumber

        with pdfplumber.open(path) as pdf:
            pages = [page.extract_text() or "" for page in pdf.pages]
        return "\n".join(pages), len(pages)
    except Exception:
        try:
            from pypdf import PdfReader
        except Exception:
            try:
                from pypdf._reader import PdfReader
            except Exception:
                from PyPDF2 import PdfReader

        reader = PdfReader(path)
        pages = [page.extract_text() or "" for page in reader.pages]
        return "\n".join(pages), len(pages)


def first_match(pattern, text, default=None, flags=re.MULTILINE):
    match = re.search(pattern, text, flags)
    return match.group(1).strip() if match else default


def number(value):
    if value is None:
        return None
    value = str(value).replace(",", "").strip()
    try:
        return float(value)
    except ValueError:
        return None


def parse_totals(text):
    total_qty = 0.0
    total_area = 0.0
    total_weight = 0.0

    one_line_rows = re.findall(
        r"^\s*(\d+(?:\.\d+)?)\s+(\d+(?:\.\d+)?)\s+(\d+(?:\.\d+)?)\s*$",
        text,
        flags=re.MULTILINE,
    )
    for qty, area, weight in one_line_rows:
        total_qty += number(qty) or 0
        total_area += number(area) or 0
        total_weight += number(weight) or 0

    if one_line_rows:
        return total_qty or None, total_area or None, total_weight or None

    qty_area_rows = re.findall(
        r"\n\s*(\d+(?:\.\d+)?)\s+(\d+(?:\.\d+)?)\s*\nName of Receiver:",
        text,
        flags=re.IGNORECASE,
    )
    for qty, area in qty_area_rows:
        total_qty += number(qty) or 0
        total_area += number(area) or 0

    weight_rows = re.findall(
        r"Page:\s*\d+/\d+\s+Date:.*?Time:.*?\n\s*(\d+(?:\.\d+)?)\s*(?:\n|$)",
        text,
        flags=re.IGNORECASE,
    )
    for weight in weight_rows:
        total_weight += number(weight) or 0

    return total_qty or None, total_area or None, total_weight or None


def parse_fixed_ancillary_weight(text):
    total_weight = 0.0

    rows = re.findall(
        r"^\s*(\d+(?:\.\d+)?)\s+(\d+(?:\.\d+)?)\s*$",
        text,
        flags=re.MULTILINE,
    )

    for _qty_or_length, weight in rows:
        total_weight += number(weight) or 0

    return total_weight or None


def parse_dn_number(zone):
    if not zone:
        return None

    match = re.search(r"\(\s*Delivery\s+(.+?)\s*\)", zone, flags=re.IGNORECASE)
    if not match:
        return None

    value = re.sub(r"\s+", "-", match.group(1).strip())
    value = value.replace("_", "-")

    parts = value.split("-", 1)
    if parts[0].isdigit():
        suffix = f"-{parts[1].title()}" if len(parts) > 1 and parts[1] else ""
        return f"D{parts[0]}{suffix}"

    return value.title()


def main():
    if len(sys.argv) < 2:
        raise SystemExit("PDF path is required")

    path = sys.argv[1]
    text, pages = extract_text(path)
    project_name = first_match(r"^Project:\s*(.+)$", text)
    zone_line = first_match(r"^Zone/Area/Floor:\s*(.+)$", text)
    delivery_note = None
    zone = zone_line

    if zone_line:
        match = re.search(r"(.+?)\s+(\d{8,})\s*$", zone_line)
        if match:
            zone = match.group(1).strip()
            delivery_note = match.group(2).strip()
    dn_number = parse_dn_number(zone) or parse_dn_number(project_name)

    title = first_match(r"^(Delivery Note \(.+?\))", text)
    is_fixed = bool(title and "Fixed Ancillaries" in title)
    total_qty, total_area, total_weight = parse_totals(text)
    fixed_weight = parse_fixed_ancillary_weight(text) if is_fixed else None

    data = {
        "source": "pdf",
        "pdf_type": "fixed" if is_fixed else "manufactured",
        "pages": pages,
        "customer": first_match(r"^Customer:\s*(.+)$", text),
        "projectname": project_name,
        "zone": zone,
        "dnnumber": dn_number,
        "wono": delivery_note,
        "deliverynote": delivery_note,
        "woqty": None if is_fixed else total_qty,
        "ductarea": total_area,
        "ductweight": None if is_fixed else total_weight,
        "mnfweight": None if is_fixed else total_weight,
        "fixancweight": fixed_weight,
        "pdfdate": first_match(r"Page:\s*\d+/\d+\s+Date:\s*([0-9/]+)", text),
        "raw_text": text,
    }

    print(json.dumps(data, ensure_ascii=False))


if __name__ == "__main__":
    main()
