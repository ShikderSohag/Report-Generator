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


def parse_vehicle_type(text):
    value = first_match(
        r"^(?:Vehicle\s+Type|Truck\s+Type|Vehicle)\s*:\s*(.+)$",
        text,
        flags=IGNORECASE | MULTILINE,
    )
    return re.sub(r"\s+", " ", value).strip() if value else None


def parse_metal_material(text):
    material_values = re.findall(
        r"(?:Material|Duct\s+Type)\s*:\s*([^\r\n]+)",
        text,
        flags=IGNORECASE,
    )
    material_text = " ".join(material_values)
    searchable = f"{material_text} {text if re.search(r'double\s+wall', text, IGNORECASE) else ''}"
    codes = []

    def add(code):
        if code not in codes:
            codes.append(code)

    if re.search(r"\bdouble\s+wall\b|\bDW\b", searchable, flags=IGNORECASE):
        add("DW")
    if re.search(r"(?:stainless\s+steel|\bSS\b)[^\r\n]{0,20}\b304\b|\b304\b[^\r\n]{0,20}(?:stainless\s+steel|\bSS\b)", searchable, flags=IGNORECASE):
        add("SS 304")
    if re.search(r"(?:stainless\s+steel|\bSS\b)[^\r\n]{0,20}\b316\b|\b316\b[^\r\n]{0,20}(?:stainless\s+steel|\bSS\b)", searchable, flags=IGNORECASE):
        add("SS 316")
    if re.search(r"\bgalvani[sz]ed\b|\bGI\b", searchable, flags=IGNORECASE):
        add("GI")
    if re.search(r"\balumini?um\b", searchable, flags=IGNORECASE):
        add("AL")
    if re.search(r"\bblack\s+steel\b", searchable, flags=IGNORECASE):
        add("BS")
    if re.search(r"\bmild\s+steel\b", searchable, flags=IGNORECASE):
        add("MS")

    if codes:
        return " / ".join(codes)

    if material_values:
        value = re.sub(r"\s+x\s+\d+\s*$", "", material_values[0], flags=IGNORECASE)
        return re.sub(r"\s+", " ", value).strip() or None

    service_type = first_match(r"Service\s+Type\s*:\s*([^,\r\n]+)", text, flags=IGNORECASE)
    return re.sub(r"\s+", " ", service_type).strip() if service_type else None


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


def is_pid_note(text):
    return bool(
        re.search(r"\bPanel\s+Type\b", text, flags=re.IGNORECASE)
        and re.search(r"Total\s+External\s+Area\s*\(m2\)", text, flags=re.IGNORECASE)
    )


def parse_pid_dn_number(text):
    match = re.search(
        r"\bDELIVERY\s+([A-Za-z0-9 -]+?)\s+Total\s+Length\s+of\s+Reinforcement\s+Bars",
        text,
        flags=re.IGNORECASE,
    )
    if not match:
        return None

    value = re.sub(r"\s+", " ", match.group(1).strip()).upper()
    if value == "FINAL":
        return "D1-Final"

    number_match = re.search(r"(\d+)", value)
    if number_match:
        suffix = "-Final" if "FINAL" in value else ""
        return f"D{number_match.group(1)}{suffix}"

    return value.title()


def parse_pid_item_qty(text):
    table_text = text
    header_match = re.search(r"X\s+Y\s+FL\s+MT\s+M2\s+M2\s*\n", text, flags=re.IGNORECASE)
    if header_match:
        table_text = text[header_match.end():]

    table_text = re.split(r"\bReceived\s+By\b", table_text, maxsplit=1, flags=re.IGNORECASE)[0]
    total_qty = 0.0

    for line in table_text.splitlines():
        line = line.strip()
        if not line or not re.match(r"[A-Za-z]", line):
            continue

        values = re.findall(r"\d+(?:\.\d+)?", line)
        if len(values) < 4:
            continue

        total_qty += number(values[-1]) or 0

    return total_qty or None


def parse_pid_note(path, text, pages):
    delivery_note = first_match(r"\bRef\s*#:\s*([A-Za-z0-9-]+)", text, flags=re.IGNORECASE)
    customer = first_match(r"\bCustomer\s+(.+?)\s+Ref\s*#:", text, flags=re.IGNORECASE)
    project_name = first_match(r"\bProject\s+(.+?)\s+Floor/Zone/Area\b", text, flags=re.IGNORECASE | re.DOTALL)
    zone = first_match(r"\bFloor/Zone/Area\s+(.+?)\s+Panel\s+Type\b", text, flags=re.IGNORECASE | re.DOTALL)
    material = first_match(
        r"\bPanel\s+Type\s+(.+?)\s+Total\s+External\s+Area\s*\(m2\)",
        text,
        flags=re.IGNORECASE | re.DOTALL,
    )
    area = number(first_match(
        r"Total\s+External\s+Area\s*\(m2\)\s*:\s*([0-9,.]+)",
        text,
        flags=re.IGNORECASE,
    ))
    supp_rod = number(first_match(
        r"Total\s+Length\s+of\s+Reinforcement\s+Bars\s*\(m\)\s*:\s*([0-9,.]+)",
        text,
        flags=re.IGNORECASE,
    ))

    if customer:
        customer = re.sub(r"\s+", " ", customer)
    if project_name:
        project_name = re.sub(r"\s+", " ", project_name)
    if zone:
        zone = re.sub(r"\s+", " ", zone)
    if material:
        material = re.sub(r"\s+", " ", material)

    return {
        "source": "pdf",
        "duct_system": "pid",
        "pdf_type": "pid_manufactured",
        "pages": pages,
        "customer": customer,
        "projectname": project_name,
        "zone": zone,
        "dnnumber": parse_pid_dn_number(text),
        "wono": delivery_note,
        "deliverynote": delivery_note,
        "woqty": parse_pid_item_qty(text),
        "ductarea": area,
        "ductweight": area,
        "mnfweight": area,
        "fixancweight": None,
        "pidarea": area,
        "pidsupprod": supp_rod,
        "pidmnfqty": parse_pid_item_qty(text),
        "pidmaterial": material,
        "vehicletype": parse_vehicle_type(text),
        "pdfdate": first_match(r"\b(\d{1,2}/\d{1,2}/\d{4})\b", text),
        "raw_text": text,
    }


def main():
    if len(sys.argv) < 2:
        raise SystemExit("PDF path is required")

    path = sys.argv[1]
    text, pages = extract_text(path)

    if is_pid_note(text):
        print(json.dumps(parse_pid_note(path, text, pages), ensure_ascii=False))
        return

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
        "duct_system": "metal",
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
        "metalmaterial": parse_metal_material(text),
        "vehicletype": parse_vehicle_type(text),
        "pdfdate": first_match(r"Page:\s*\d+/\d+\s+Date:\s*([0-9/]+)", text),
        "raw_text": text,
    }

    print(json.dumps(data, ensure_ascii=False))


if __name__ == "__main__":
    main()
