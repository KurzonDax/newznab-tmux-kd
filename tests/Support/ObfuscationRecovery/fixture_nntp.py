"""Loopback-only NNTP server for automated transport and downloader tests."""
import argparse
import json
import socketserver
import threading
import time
from pathlib import Path


class Server(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True
    request_queue_size = 64

    def __init__(self, root, delay=0, chunk_size=8192, fail_body=False):
        self.articles = json.loads((root / "articles.json").read_text())
        self.metrics = root / "server-events.jsonl"
        self.lock = threading.Lock()
        self.active = 0
        self.delay = delay
        self.chunk_size = chunk_size
        self.fail_body = fail_body
        super().__init__(("127.0.0.1", 0), Handler)

    def event(self, kind, **data):
        with self.lock:
            if kind == "open":
                self.active += 1
            if kind == "close":
                self.active -= 1
            with self.metrics.open("a") as output:
                output.write(json.dumps({"kind": kind, "active": self.active, "time": time.monotonic(), **data}) + "\n")


class Handler(socketserver.StreamRequestHandler):
    def handle(self):
        self.request.settimeout(60)
        self.server.event("open")
        sent = received = 0
        aborted = False
        def send(data):
            nonlocal sent
            self.wfile.write(data)
            sent += len(data)
        try:
            send(b"200 local fixture ready\r\n")
            while True:
                raw = self.rfile.readline(8193)
                if not raw:
                    break
                received += len(raw)
                if len(raw) > 8192:
                    break
                command = raw.decode("ascii").strip()
                verb, _, value = command.partition(" ")
                verb = verb.upper()
                if verb == "QUIT":
                    send(b"205 closing\r\n")
                    break
                if verb == "CAPABILITIES":
                    send(b"101 capabilities\r\nVERSION 2\r\nREADER\r\nOVER\r\n.\r\n")
                elif verb == "MODE":
                    send(b"200 reader\r\n")
                elif verb == "AUTHINFO":
                    send(b"381 password\r\n" if value.upper().startswith("USER ") else b"281 authenticated\r\n")
                elif verb == "GROUP":
                    numbers = [int(v["header"]["Number"]) for v in self.server.articles.values()]
                    send(f"211 {len(numbers)} {min(numbers)} {max(numbers)} alt.binaries.fixture\r\n".encode("ascii"))
                elif verb in {"OVER", "XOVER"}:
                    first, last = map(int, value.split("-"))
                    self.server.event("request", verb=verb, first=first, last=last)
                    time.sleep(self.server.delay)
                    send(b"224 overview\r\n")
                    for article in self.server.articles.values():
                        header = article["header"]
                        if first <= int(header["Number"]) <= last:
                            values = [header[k] for k in ["Number", "Subject", "From", "Date", "Message-ID"]]
                            values += ["", header["Bytes"], 100, header["Xref"]]
                            send(("\t".join(map(str, values)) + "\r\n").encode("ascii"))
                    send(b".\r\n")
                elif verb in {"BODY", "ARTICLE", "HEAD", "STAT"}:
                    message = value.strip("<>")
                    article = self.server.articles.get(message)
                    if article is None:
                        send(b"430 article absent\r\n")
                        continue
                    self.server.event("request", verb=verb, message=message)
                    if verb == "STAT":
                        send(f"223 0 <{message}> exists\r\n".encode("ascii"))
                        continue
                    time.sleep(self.server.delay)
                    if self.server.fail_body and verb == "BODY":
                        send(b"430 article unavailable\r\n")
                        continue
                    code = {"BODY": 222, "ARTICLE": 220, "HEAD": 221}[verb]
                    send(f"{code} 0 <{message}> follows\r\n".encode("ascii"))
                    if verb in {"HEAD", "ARTICLE"}:
                        for key in ["Subject", "From", "Date", "Message-ID"]:
                            send(f"{key}: {article['header'][key]}\r\n".encode("ascii"))
                        if verb == "ARTICLE":
                            send(b"\r\n")
                    if verb != "HEAD":
                        with open(article["body"], "rb") as body:
                            while chunk := body.read(self.server.chunk_size):
                                send(chunk)
                                time.sleep(self.server.delay)
                    send(b".\r\n")
                else:
                    send(b"500 unsupported\r\n")
        except (OSError, UnicodeError, ValueError):
            aborted = True
        finally:
            self.server.event("close", sent=sent, received=received, aborted=aborted)


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("root", type=Path)
    parser.add_argument("--delay", type=float, default=0)
    parser.add_argument("--chunk-size", type=int, default=8192)
    parser.add_argument("--fail-body", action="store_true")
    args = parser.parse_args()
    if args.delay < 0 or not 1 <= args.chunk_size <= 1048576:
        parser.error("invalid stream limits")
    with Server(args.root, args.delay, args.chunk_size, args.fail_body) as server:
        (args.root / "server-port").write_text(str(server.server_address[1]))
        server.serve_forever(poll_interval=0.05)
