"""Generate local article fixtures; truth records are consumed only by tests."""
import argparse
import hashlib
import json
import random
import struct
import subprocess
import zlib
from datetime import datetime, timezone
from pathlib import Path

C = 716800
BASE = 1700000000000


def deterministic_file(path, label, size):
    block = b"".join(hashlib.sha256(label.encode("ascii") + struct.pack("<I", j)).digest() for j in range(256))
    with path.open("wb") as output:
        while size:
            chunk = block[:min(size, len(block))]
            output.write(chunk)
            size -= len(chunk)


def md5_file(path):
    with path.open("rb") as source:
        return hashlib.file_digest(source, "md5").digest()


def packet(set_id, kind, body):
    body += b"\0" * ((-len(body)) % 4)
    tail = set_id + (b"PAR 2.0\0" + kind).ljust(16, b"\0") + body
    return b"PAR2\0PKT" + struct.pack("<Q", 32 + len(tail)) + hashlib.md5(tail).digest() + tail


def index(files):
    entries = []
    for name, path in files.items():
        size = path.stat().st_size
        with path.open("rb") as source:
            prefix = hashlib.md5(source.read(16384)).digest()
        fid = hashlib.md5(prefix + struct.pack("<Q", size) + name.encode("ascii")).digest()
        entries.append((fid, name, path, size, prefix))
    entries.sort()
    assert len({entry[4] for entry in entries}) == len(entries)
    main = struct.pack("<QI", 1048576, len(entries)) + b"".join(entry[0] for entry in entries)
    set_id = hashlib.md5(main).digest()
    result = packet(set_id, b"Main", main)
    for fid, name, path, size, prefix in entries:
        result += packet(set_id, b"FileDesc", fid + md5_file(path) + prefix + struct.pack("<Q", size) + name.encode("ascii"))
        checks = bytearray(fid)
        with path.open("rb") as source:
            while chunk := source.read(1048576):
                chunk = chunk.ljust(1048576, b"\0")
                checks.extend(hashlib.md5(chunk).digest() + struct.pack("<I", zlib.crc32(chunk) & 0xffffffff))
        result += packet(set_id, b"IFSC", bytes(checks))
    return result


def rar_files(root):
    sizes = [2 * C + 100000] * 3 + [C + 100000]
    name = b"fixture.bin"
    overhead = 59 + len(name)
    inner = root / "truth-inner.bin"
    deterministic_file(inner, "archive-inner", sum(sizes) - len(sizes) * overhead)
    content = inner.read_bytes()
    def header(kind, flags, extra):
        raw = struct.pack("<BHH", kind, flags, 7 + len(extra)) + extra
        return struct.pack("<H", zlib.crc32(raw) & 0xffff) + raw
    files, cursor = {}, 0
    for i, size in enumerate(sizes):
        piece = content[cursor:cursor + size - overhead]
        cursor += len(piece)
        last = i == len(sizes) - 1
        main = header(0x73, 0x0011 | (0x0100 if i == 0 else 0), b"\0" * 6)
        flags = 0x8000 | (1 if i else 0) | (0 if last else 2)
        extra = struct.pack("<IIBIIBBHI", len(piece), len(content), 3,
                            zlib.crc32(content if last else piece) & 0xffffffff, 0x00210000, 20, 0x30, len(name), 0x81a4) + name
        volume = b"Rar!\x1a\x07\0" + main + header(0x74, flags, extra) + piece + header(0x7b, 0 if last else 1, b"")
        path = root / f"fixture.part{i+1:02d}.rar"
        path.write_bytes(volume)
        assert len(volume) == size
        files[path.name] = path
    assert len(content) == 5417320 and hashlib.md5(content).hexdigest() == "c9c88fab31c37d1a0a05fbbd429a53cc"
    return files


def encoded_body(data, part, total, size, whole_crc):
    output = bytearray(f"=ybegin part={part} total={total} line=128 size={size} name=opaque\r\n".encode("ascii"))
    output.extend(f"=ypart begin={(part-1)*C+1} end={min(part*C,size)}\r\n".encode("ascii"))
    table = []
    for value in range(256):
        x = (value + 42) & 255
        table.append(bytes([61, (x + 64) & 255]) if x in {0, 9, 10, 13, 32, 46, 61} else bytes([x]))
    line = bytearray()
    for value in data:
        token = table[value]
        if len(line) + len(token) > 128:
            output.extend(line + b"\r\n")
            line.clear()
        line.extend(token)
    if line:
        output.extend(line + b"\r\n")
    output.extend(f"=yend size={len(data)} part={part} pcrc32={zlib.crc32(data)&0xffffffff:08x}".encode("ascii"))
    if part == total:
        output.extend(f" crc32={whole_crc:08x}".encode("ascii"))
    output.extend(b"\r\n")
    return output


def build(root, case, tied=False, substitute=False):
    root.mkdir(parents=True, exist_ok=True)
    (root / "bodies").mkdir()
    if case in {"mixed", "mkv", "mp4"}:
        files = {}
        formats = ["mkv", "mp4"] if case == "mixed" else [case]
        for i, format in enumerate(formats, 1):
            name = f"Synthetic.Container.S01E{i:02d}.{format}"
            path = root / name
            command = ["/usr/bin/ffmpeg", "-nostdin", "-hide_banner", "-loglevel", "error", "-y", "-f", "lavfi",
                       "-i", "testsrc2=size=1280x720:rate=30", "-t", str(5 if i == 1 else 7), "-an", "-c:v", "mpeg4",
                       "-q:v", "2", "-threads", "1", "-fflags", "+bitexact", "-flags:v", "+bitexact", "-map_metadata", "-1"]
            if format == "mp4":
                command += ["-movflags", "+faststart"]
            subprocess.run(command + [str(path)], check=True, timeout=30)
            subprocess.run(["/usr/bin/ffmpeg", "-nostdin", "-v", "error", "-i", str(path), "-f", "null", "-"], check=True, timeout=30)
            files[name] = path
        totals = [(path.stat().st_size+C-1)//C for path in files.values()]
        assert min(totals) >= 3 and len(set(totals)) == len(totals)
        (root / "tools.json").write_text(json.dumps({"ffmpeg": subprocess.check_output(["/usr/bin/ffmpeg", "-version"], text=True).splitlines()[0]}))
    elif case == "rar4":
        files = rar_files(root)
    else:
        totals = {"media1": [4], "media7": list(range(3, 10)), "media32": list(range(3, 35))}[case]
        episodes = [1, 2, 3, 4, 6, 7, 8] if case == "media7" else list(range(1, len(totals) + 1))
        files = {}
        for i, (total, episode) in enumerate(zip(totals, episodes), 1):
            name = "Synthetic.Feature.2026.1080p.mkv" if case == "media1" else f"Synthetic.Show.S01E{episode:02d}.1080p.mkv"
            path = root / name
            deterministic_file(path, f"media-{i}", (total - 1) * C + 100000)
            files[name] = path
    par2 = root / "fixture.par2"
    par2.write_bytes(index(files))
    files[par2.name] = par2
    headers, articles, truth, expected_targets = [], {}, {}, []
    g = 0
    for i, (name, path) in enumerate(files.items(), 1):
        size = path.stat().st_size
        total = (size + C - 1) // C
        is_index = path == par2
        order = list(range(1, total + 1))
        if not is_index and case != "rar4" and total >= 3:
            order[1], order[2] = order[2], order[1]
        if tied and not is_index and ((case == "rar4" and i == 2) or (case == "media1" and i == 1)):
            order = [2, 1] + list(range(3, total + 1))
        whole_crc = 0
        with path.open("rb") as source:
            while chunk := source.read(C):
                whole_crc = zlib.crc32(chunk, whole_crc)
        truth[name] = {"path": str(path), "size": size, "md5": md5_file(path).hex(), "messages": []}
        with path.open("rb") as source:
            for ordinal, part in enumerate(order, 1):
                g += 1
                timestamp = BASE + 10 * g if case == "rar4" else BASE + 100 * i + 10 * (ordinal - 1)
                if is_index and case != "rar4":
                    timestamp = BASE + 100 * (len(files) - 1) + 1000
                tied_start = tied and not is_index and ((case == "rar4" and i == 2) or (case == "media1" and i == 1))
                if tied_start and ordinal == 2:
                    timestamp -= 10
                message = f"m{g:010d}-{timestamp}@nyuu"
                source.seek((part - 1) * C)
                body = encoded_body(source.read(C), part, total, size, whole_crc & 0xffffffff)
                body_path = root / "bodies" / f"{g}.body"
                body_path.write_bytes(body)
                article = 4000000000 + g
                subject = "0123456789abcdefghij" if case == "rar4" else "[a] - " + hashlib.sha256(message.encode("ascii")).hexdigest()[:32] + f" yEnc (1/{total})"
                date = datetime.fromtimestamp(timestamp / 1000, timezone.utc).strftime("%a, %d %b %Y %H:%M:%S +0000")
                headers.append({"Number": str(article), "Subject": subject, "From": "fixture@example.invalid", "Date": date,
                                "Message-ID": f"<{message}>", "Bytes": len(body), "Xref": f"fixture alt.binaries.fixture:{article}"})
                articles[message] = {"body": str(body_path), "header": headers[-1]}
                truth[name]["messages"].append({"id": message, "article": article, "bytes": len(body), "ordinal": ordinal,
                                                   "part": part, "begin": (part-1)*C+1, "end": min(part*C,size)})
                if is_index or part == 1 or (tied_start and ordinal == 1) or (case == "rar4" and part == total):
                    expected_targets.append(message)
    # Source numbering follows global delivery time; per-file positions stay unchanged.
    ordered = sorted(articles.items(), key=lambda entry: (int(entry[0].split("-")[1].split("@")[0]), int(entry[1]["header"]["Number"])))
    remapped, renumbered = {}, {}
    for g, (old, record) in enumerate(ordered, 1):
        timestamp = int(old.split("-")[1].split("@")[0])
        message = f"m{g:010d}-{timestamp}@nyuu"
        article = 4000000000 + g
        header = record["header"]
        header["Number"] = str(article)
        header["Message-ID"] = f"<{message}>"
        header["Xref"] = f"fixture alt.binaries.fixture:{article}"
        if case != "rar4":
            total = header["Subject"].rsplit("/", 1)[1]
            header["Subject"] = "[a] - " + hashlib.sha256(message.encode("ascii")).hexdigest()[:32] + " yEnc (1/" + total
        remapped[old] = (message, article)
        renumbered[message] = record
    articles = renumbered
    expected_targets = [remapped[target][0] for target in expected_targets]
    for file in truth.values():
        for message in file["messages"]:
            message["id"], message["article"] = remapped[message["id"]]
    if substitute:
        eligible = [(name, file) for name, file in truth.items() if len(file["messages"]) >= 4][:2]
        assert len(eligible) == 2
        payloads = []
        for name, file in eligible:
            with open(file["path"], "rb") as source:
                source.seek(C)
                payloads.append(source.read(C))
        for (_, file), replacement in zip(eligible, reversed(payloads)):
            target = next(message for message in file["messages"] if message["part"] == 2)
            body = encoded_body(replacement, 2, len(file["messages"]), file["size"], 0)
            Path(articles[target["id"]]["body"]).write_bytes(body)
    random.Random(507).shuffle(headers)
    (root / "headers.json").write_text(json.dumps(headers))
    (root / "articles.json").write_text(json.dumps(articles))
    (root / "truth.json").write_text(json.dumps({"files": truth, "targets": expected_targets, "parts": g}))


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("root", type=Path)
    parser.add_argument("case", choices=["media1", "media7", "media32", "rar4", "mixed", "mkv", "mp4"])
    parser.add_argument("--tied", action="store_true")
    parser.add_argument("--substitute", action="store_true")
    args = parser.parse_args()
    build(args.root, args.case, args.tied, args.substitute)
