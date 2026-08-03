"""Extract the fixed GGB and syllabus PDF sources into deterministic seed data.

The source PDFs remain the authority. Every extracted row keeps its source page,
raw text, and document checksum so the web application can explain its audit.
"""

from __future__ import annotations

import hashlib
import json
import math
import re
import unicodedata
from collections import defaultdict
from pathlib import Path

import pdfplumber


ROOT = Path(__file__).resolve().parents[1]
OUTPUT = ROOT / "database" / "data" / "curriculum.json"
REPORT = ROOT / "docs" / "AUDIT_DATA_SUMBER.md"

LEVELS = [
    {"code": "PAUD", "name": "PAUD", "stage": "PAUD", "grade": None, "age": "5-6 tahun"},
    *[
        {"code": f"{grade}-SD", "name": f"Kelas {grade} SD", "stage": "SD", "grade": grade, "age": f"{grade + 6} tahun"}
        for grade in range(1, 7)
    ],
    *[
        {"code": f"{grade}-SMP", "name": f"Kelas {grade} SMP", "stage": "SMP", "grade": grade, "age": f"{grade + 12} tahun"}
        for grade in range(1, 4)
    ],
    *[
        {"code": f"{grade}-SMA", "name": f"Kelas {grade} SMA", "stage": "SMA", "grade": grade, "age": f"{grade + 15} tahun"}
        for grade in range(1, 4)
    ],
    *[
        {"code": f"PM-{grade}", "name": f"Pra Nikah {grade}", "stage": "PRA NIKAH", "grade": grade, "age": f"{grade + 18} tahun" if grade < 4 else "22 tahun ke atas"}
        for grade in range(1, 5)
    ],
]


def natural_index(path: Path) -> int:
    match = re.match(r"\s*(\d+)", path.name)
    return int(match.group(1)) if match else 999


def clean(value: str | None) -> str:
    if not value:
        return ""
    value = value.replace("\u00a0", " ").replace("\r", "\n")
    value = re.sub(r"[ \t]+", " ", value)
    value = re.sub(r"\n+", "\n", value)
    return value.strip(" \n|")


def slug(value: str) -> str:
    value = unicodedata.normalize("NFKD", value).encode("ascii", "ignore").decode()
    value = re.sub(r"[^A-Za-z0-9]+", "-", value).strip("-")
    return value.upper()[:28] or "MATERI"


def tokens(value: str) -> set[str]:
    normalized = unicodedata.normalize("NFKD", value).encode("ascii", "ignore").decode().lower()
    words = re.findall(r"[a-z0-9]{2,}", normalized)
    stop = {"dan", "yang", "dengan", "untuk", "pada", "dari", "atau", "siswa", "mampu", "materi", "semester", "kelas"}
    return {word for word in words if word not in stop}


def infer_level(path: Path) -> str:
    name = path.stem.upper().replace("_", " ")
    if "PAUD" in name:
        return "PAUD"
    if "PRA" in name or "PRANIKAH" in name:
        age_match = re.search(r"-\s*(19|20|21|22)\s*THN", name)
        if age_match:
            return f"PM-{int(age_match.group(1)) - 18}"
        match = re.search(r"(?:NIKAH|PRANIKAH)\s*(\d)", name)
        if match:
            return f"PM-{match.group(1)}"
    for stage in ("SD", "SMP", "SMA"):
        match = re.search(rf"(?:GGB|SILABUS)\s*(\d)\s*{stage}", name)
        if match:
            return f"{match.group(1)}-{stage}"
    raise ValueError(f"Jenjang tidak dikenali: {path.name}")


def display_level_code(level: str) -> str:
    if level.endswith("-SMP"):
        return f"{int(level.split('-')[0]) + 6}-SMP"
    if level.endswith("-SMA"):
        return f"{int(level.split('-')[0]) + 9}-SMA"
    if level.startswith("PM-"):
        return f"PRA-NIKAH-{level.split('-')[1]}"
    return level


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def extract_ggb(path: Path, document_id: str) -> list[dict]:
    level = infer_level(path)
    items: list[dict] = []
    aspect = "Umum"
    subaspect = "Materi"
    order = 0
    aspect_source_key: str | None = None
    subaspect_source_key: str | None = None
    ignored = (
        "GARIS-GARIS BESAR",
        "TARGET PEMBINAAN GENERUS",
        "JENJANG ",
        "BAB II",
    )

    with pdfplumber.open(path) as pdf:
        for page_number, page in enumerate(pdf.pages, start=1):
            text = page.extract_text(x_tolerance=2, y_tolerance=3) or ""
            for raw_line in text.splitlines():
                line = clean(raw_line)
                if not line or line.isdigit() or any(line.upper().startswith(prefix) for prefix in ignored):
                    continue
                if re.fullmatch(r"(?:SD|SMP|SMA)\s+KELAS\s+\d", line.upper()):
                    continue

                roman = re.match(r"^([IVX]+)\.?\s+(.+)$", line)
                upper = re.match(r"^([A-Z])\.\s+(.+)$", line)
                numbered = re.match(r"^(\d+)\s*[.)]\s*(.+)$", line)
                lettered = re.match(r"^([a-z])\.\s+(.+)$", line)
                title = line
                level_kind = "detail"

                if roman:
                    aspect = clean(roman.group(2)).title()
                    subaspect = "Umum"
                    title = aspect
                    level_kind = "aspect"
                elif upper:
                    subaspect = clean(upper.group(2)).title()
                    title = subaspect
                    level_kind = "subaspect"
                elif numbered:
                    title = clean(numbered.group(2))
                    level_kind = "topic"
                elif lettered:
                    title = clean(lettered.group(2))
                    level_kind = "topic"

                order += 1
                source_key = f"{document_id}:p{page_number}:r{order}"
                parent_source_key = None
                if level_kind == "aspect":
                    aspect_source_key = source_key
                    subaspect_source_key = None
                elif level_kind == "subaspect":
                    parent_source_key = aspect_source_key
                    subaspect_source_key = source_key
                else:
                    parent_source_key = subaspect_source_key or aspect_source_key

                items.append(
                    {
                        "source_key": source_key,
                        "parent_source_key": parent_source_key,
                        "level_code": level,
                        "document_key": document_id,
                        "stable_code": f"{display_level_code(level)} / {slug(aspect)} / {order:03d}",
                        "kind": level_kind,
                        "aspect": aspect,
                        "subaspect": subaspect,
                        "title": title,
                        "raw_text": line,
                        "source_page": page_number,
                        "sort_order": order,
                    }
                )
    return items


def split_bullets(value: str) -> list[str]:
    value = clean(value)
    if not value:
        return []
    chunks = re.split(r"(?:^|\n)\s*[•●▪]\s*", value)
    chunks = [clean(chunk.replace("\n", " ")) for chunk in chunks]
    return [chunk for chunk in chunks if chunk]


def extract_sessions(allocation: str) -> int | None:
    match = re.search(r"(\d+)\s*pertemuan", allocation.lower())
    if match:
        return int(match.group(1))
    if allocation and "tentatif" not in allocation.lower():
        return 1
    return None


def extract_syllabus(path: Path, document_id: str) -> list[dict]:
    level = infer_level(path)
    items: list[dict] = []
    order = 0
    group_number = 0
    current = {"category": "Materi", "allocation": "", "reference": "", "assessment": ""}

    with pdfplumber.open(path) as pdf:
        page_count = len(pdf.pages)
        for page_number, page in enumerate(pdf.pages, start=1):
            for table in page.extract_tables() or []:
                for row in table:
                    cells = [clean(cell) for cell in (row or [])]
                    # Tabel jadwal harian mempunyai delapan kolom dan bukan
                    # daftar materi silabus. Halaman lanjutan kadang kehilangan
                    # kolom NO, sehingga lima kolom harus digeser satu posisi.
                    if len(cells) > 6:
                        continue
                    if len(cells) == 5:
                        cells.insert(0, "")
                    if len(cells) != 6:
                        continue
                    number, category, description, allocation, reference, assessment = cells[:6]
                    joined = " ".join(cells).upper()
                    if "PENJABARAN MATERI" in joined or ("NO" in joined and "MATERI" in joined):
                        continue
                    if not any(cells):
                        continue

                    if category:
                        current["category"] = category.replace("\n", " ")
                        group_number += 1
                    if allocation:
                        current["allocation"] = allocation.replace("\n", " ")
                    if reference:
                        current["reference"] = reference.replace("\n", " ")
                    if assessment:
                        current["assessment"] = assessment.replace("\n", " ")

                    bullets = split_bullets(description)
                    if not bullets and category and category.upper() not in {"KETERANGAN", "CATATAN"}:
                        bullets = [current["category"]]
                    for bullet in bullets:
                        if len(bullet) < 2:
                            continue
                        order += 1
                        sessions = extract_sessions(current["allocation"])
                        source_semester = "both" if level == "PAUD" else ("1" if page_number <= math.ceil(page_count / 2) else "2")
                        items.append(
                            {
                                "source_key": f"{document_id}:p{page_number}:r{order}",
                                "level_code": level,
                                "document_key": document_id,
                                "stable_code": f"{display_level_code(level)} / {slug(current['category'])} / {order:03d}",
                                "category": current["category"],
                                "title": bullet,
                                "description": bullet,
                                "allocation_text": current["allocation"],
                                "reference_text": current["reference"],
                                "assessment_text": current["assessment"],
                                "recommended_sessions": sessions,
                                "needs_allocation": sessions is None,
                                "is_duplicate": False,
                                "source_page": page_number,
                                "sort_order": order,
                                "group_number": group_number,
                                "source_semester": source_semester,
                                "semester_scope": source_semester,
                            }
                        )
    return items


def build_links(ggb_items: list[dict], syllabus_items: list[dict]) -> list[dict]:
    by_level: dict[str, list[dict]] = defaultdict(list)
    for item in ggb_items:
        if item["kind"] != "aspect":
            by_level[item["level_code"]].append(item)

    links: list[dict] = []
    for syllabus in syllabus_items:
        source_tokens = tokens(f"{syllabus['category']} {syllabus['description']}")
        ranked: list[tuple[float, dict]] = []
        for ggb in by_level[syllabus["level_code"]]:
            target_tokens = tokens(f"{ggb['aspect']} {ggb['subaspect']} {ggb['title']}")
            if not source_tokens or not target_tokens:
                score = 0.0
            else:
                score = len(source_tokens & target_tokens) / max(1, len(source_tokens))
            ranked.append((score, ggb))
        score, best = max(ranked, key=lambda pair: pair[0])
        if score >= 0.45:
            status = "sesuai"
        elif score >= 0.12:
            status = "sebagian"
        else:
            status = "perlu_verifikasi"
        links.append(
            {
                "ggb_source_key": best["source_key"],
                "syllabus_source_key": syllabus["source_key"],
                "status": status,
                "confidence": round(score, 4),
                "notes": "Pencocokan awal deterministik; halaman sumber tersedia untuk pemeriksaan admin.",
            }
        )
    return links


def main() -> None:
    ggb_paths = sorted((ROOT / "1. GGB").glob("*.pdf"), key=natural_index)
    syllabus_paths = sorted((ROOT / "2. SILABUS").glob("*.pdf"), key=natural_index)
    if len(ggb_paths) != 17 or len(syllabus_paths) != 17:
        raise RuntimeError(f"Diharapkan 17 GGB dan 17 silabus, ditemukan {len(ggb_paths)} dan {len(syllabus_paths)}")

    documents: list[dict] = []
    ggb_items: list[dict] = []
    syllabus_items: list[dict] = []

    for source_type, paths in (("ggb", ggb_paths), ("silabus", syllabus_paths)):
        for path in paths:
            level_code = infer_level(path)
            document_key = f"{source_type}:{level_code}"
            with pdfplumber.open(path) as pdf:
                page_count = len(pdf.pages)
            documents.append(
                {
                    "source_key": document_key,
                    "level_code": level_code,
                    "type": source_type,
                    "title": path.stem,
                    "path": path.relative_to(ROOT).as_posix(),
                    "sha256": sha256(path),
                    "page_count": page_count,
                }
            )
            if source_type == "ggb":
                ggb_items.extend(extract_ggb(path, document_key))
            else:
                syllabus_items.extend(extract_syllabus(path, document_key))

    seen_syllabus: set[tuple[str, str, str, str]] = set()
    for item in syllabus_items:
        duplicate_key = (
            item["level_code"],
            item["source_semester"],
            clean(item["category"]).casefold(),
            clean(item["title"]).casefold(),
            clean(item["allocation_text"]).casefold(),
        )
        item["is_duplicate"] = duplicate_key in seen_syllabus
        seen_syllabus.add(duplicate_key)

    links = build_links(ggb_items, syllabus_items)
    data = {
        "meta": {
            "schema_version": 2,
            "source_policy": "GGB sebagai induk; silabus dan RPP adalah turunan.",
            "level_count": len(LEVELS),
            "document_count": len(documents),
            "duplicate_syllabus_count": sum(1 for item in syllabus_items if item["is_duplicate"]),
        },
        "levels": [{**level, "sort_order": index} for index, level in enumerate(LEVELS, start=1)],
        "documents": documents,
        "ggb_items": ggb_items,
        "syllabus_items": syllabus_items,
        "links": links,
    }
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    OUTPUT.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")

    by_level = defaultdict(lambda: {"ggb": 0, "silabus": 0, "sesuai": 0, "sebagian": 0, "verifikasi": 0})
    for item in ggb_items:
        by_level[item["level_code"]]["ggb"] += 1
    for item in syllabus_items:
        by_level[item["level_code"]]["silabus"] += 1
    link_to_level = {item["source_key"]: item["level_code"] for item in syllabus_items}
    for link in links:
        target = by_level[link_to_level[link["syllabus_source_key"]]]
        key = "verifikasi" if link["status"] == "perlu_verifikasi" else link["status"]
        target[key] += 1

    lines = [
        "# Audit Data Sumber GGB dan Silabus",
        "",
        "GGB diperlakukan sebagai sumber induk. Seluruh hasil ekstraksi menyimpan dokumen dan nomor halaman.",
        "",
        "| Jenjang | Item GGB | Item Silabus | Sesuai | Sebagian | Perlu verifikasi |",
        "|---|---:|---:|---:|---:|---:|",
    ]
    for level in LEVELS:
        row = by_level[level["code"]]
        lines.append(f"| {level['name']} | {row['ggb']} | {row['silabus']} | {row['sesuai']} | {row['sebagian']} | {row['verifikasi']} |")
    lines += [
        "",
        f"Total dokumen: **{len(documents)}** (17 GGB + 17 silabus).",
        f"Total item GGB: **{len(ggb_items)}**.",
        f"Total item silabus: **{len(syllabus_items)}**.",
        "",
        "> Status pencocokan merupakan audit awal berbasis teks. Item berstatus Perlu verifikasi tetap ditampilkan dan tidak dianggap hilang.",
    ]
    REPORT.parent.mkdir(parents=True, exist_ok=True)
    REPORT.write_text("\n".join(lines) + "\n", encoding="utf-8")

    print(json.dumps({"levels": len(LEVELS), "documents": len(documents), "ggb_items": len(ggb_items), "syllabus_items": len(syllabus_items), "links": len(links)}, ensure_ascii=False))


if __name__ == "__main__":
    main()
