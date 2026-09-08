"""Independent full-byte assembly oracle; never used by production discovery."""
import argparse
import gzip
import hashlib
import json
import re
import socket
import xml.etree.ElementTree as ET
import zlib
from pathlib import Path


def assembled(root, nzb, port):
    truth = json.loads((root / 'truth.json').read_text())['files']
    by_message = {message['id']: name for name, file in truth.items() for message in file['messages']}
    mismatches = []
    count = 0
    with socket.create_connection(('127.0.0.1', port), timeout=10) as socket_stream, socket_stream.makefile('rb') as peer:
        assert peer.readline().startswith(b'200 ')
        for file in ET.fromstring(gzip.decompress(nzb.read_bytes())).findall('{*}file'):
            segments = file.findall('{*}segments/{*}segment')
            name = by_message[segments[0].text]
            output = root / ('oracle-' + str(count))
            with output.open('w+b') as target:
                for segment in segments:
                    socket_stream.sendall(f'BODY <{segment.text}>\r\n'.encode('ascii'))
                    assert peer.readline().startswith(b'222 ')
                    start = end = None
                    decoded = bytearray()
                    escaped = False
                    checksum = None
                    while True:
                        line = peer.readline()
                        assert line
                        if line == b'.\r\n':
                            break
                        if line.startswith(b'..'):
                            line = line[1:]
                        if line.startswith(b'=ybegin '):
                            continue
                        if line.startswith(b'=ypart '):
                            start = int(re.search(rb'begin=(\d+)', line)[1])
                            end = int(re.search(rb'end=(\d+)', line)[1])
                            continue
                        if line.startswith(b'=yend '):
                            checksum = int(re.search(rb'pcrc32=([0-9a-f]{8})', line)[1], 16)
                            continue
                        for value in line[:-2]:
                            if escaped:
                                value = (value - 64) & 255
                                escaped = False
                            elif value == 61:
                                escaped = True
                                continue
                            decoded.append((value - 42) & 255)
                    assert not escaped and start and end and len(decoded) == end - start + 1
                    assert zlib.crc32(decoded) & 0xffffffff == checksum
                    target.seek(start - 1)
                    target.write(decoded)
            with output.open('rb') as stream:
                actual = hashlib.file_digest(stream, 'md5').hexdigest()
            if actual != truth[name]['md5']:
                mismatches.append(name)
            count += 1
        socket_stream.sendall(b'QUIT\r\n')
        peer.readline()
    return {'files': count, 'mismatches': mismatches}


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('root', type=Path)
    parser.add_argument('nzb', type=Path)
    parser.add_argument('port', type=int)
    args = parser.parse_args()
    print(json.dumps(assembled(args.root, args.nzb, args.port)))
