"""Drive pinned clients against the loopback fixture; compare independent hashes."""
import argparse
import base64
import gzip
import hashlib
import json
import os
import signal
import socket
import subprocess
import time
import urllib.parse
import urllib.request
from pathlib import Path

TOOLS = Path('/opt/recovery-fixture-tools')
SAB = TOOLS / 'sabnzbd-10609644c8b5eef462845ec6443f24dd8fcba96a/SABnzbd.py'
API_KEY = '50700000000000000000000000000000'


def free_port():
    with socket.socket() as peer:
        peer.bind(('127.0.0.1', 0))
        return peer.getsockname()[1]


def wait_for(operation, process, seconds=30):
    deadline = time.monotonic() + seconds
    last = None
    while time.monotonic() < deadline and process.poll() is None:
        try:
            result = operation()
            if result:
                return result
        except (OSError, ValueError) as error:
            last = error
        time.sleep(0.1)
    raise RuntimeError(f'local client deadline: {last}; exit={process.poll()}')


def run_client(client, root, nzb, nntp_port):
    directory = root / client
    directory.mkdir()
    complete = directory / 'complete'
    complete.mkdir()
    port = free_port()
    nzb_file = directory / 'fixture.nzb'
    nzb_file.write_bytes(gzip.decompress(nzb.read_bytes()))
    if client == 'sabnzbd':
        version = subprocess.check_output([str(TOOLS / 'venv/bin/python'), str(SAB), '--version'], text=True)
        assert 'SABnzbd.py-5.1.2' in version
        config = directory / 'sabnzbd.ini'
        values = {'host': '127.0.0.1', 'port': port, 'api_key': API_KEY, 'nzb_key': API_KEY,
                  'download_dir': directory / 'incomplete', 'complete_dir': complete, 'admin_dir': directory / 'admin',
                  'log_dir': directory / 'logs', 'nzb_backup_dir': directory / 'backup', 'check_new_rel': 0,
                  'enable_unrar': 0, 'enable_par_cleanup': 0, 'direct_unpack': 0, 'download_free': 0,
                  'folder_rename': 0, 'replace_spaces': 0, 'auto_browser': 0, 'allow_64bit_tools': 1,
                  'enable_https_verification': 1, 'inet_exposure': 0, 'host_whitelist': '127.0.0.1'}
        content = '[misc]\n' + ''.join(f'{key} = {value}\n' for key, value in values.items())
        content += f'[servers]\n[[fixture]]\nhost = 127.0.0.1\nport = {nntp_port}\nconnections = 2\nssl = 0\nenable = 1\noptional = 0\n'
        content += '[categories]\n[[*]]\npp = 1\nscript = None\ndir = \n'
        config.write_text(content)
        command = [str(TOOLS / 'venv/bin/python'), str(SAB), '--new', '-f', str(config), '-b', '0', '--disable-file-log', str(nzb_file)]
        def history():
            url = f'http://127.0.0.1:{port}/api?' + urllib.parse.urlencode({'mode': 'history', 'output': 'json', 'apikey': API_KEY})
            data = json.load(urllib.request.urlopen(url, timeout=2))
            return [job for job in data.get('history', {}).get('slots', []) if job['status'] in {'Completed', 'Failed'}]
    else:
        binary = TOOLS / 'nzbget/usr/bin/nzbget'
        version = subprocess.check_output([str(binary), '--version'], text=True)
        assert 'nzbget version: 25.4' in version
        template = TOOLS / 'nzbget/usr/share/nzbget'
        config = directory / 'nzbget.conf'
        values = {'MainDir': directory, 'DestDir': complete, 'InterDir': directory / 'intermediate',
                  'QueueDir': directory / 'queue', 'TempDir': directory / 'tmp', 'NzbDir': directory / 'incoming',
                  'ControlIP': '127.0.0.1', 'ControlPort': port, 'ControlUsername': '', 'ControlPassword': '',
                  'Server1.Host': '127.0.0.1', 'Server1.Port': nntp_port, 'Server1.Encryption': 'no',
                  'Server1.Username': '', 'Server1.Password': '', 'Server1.Connections': 2,
                  'ParCheck': 'always', 'ParRepair': 'no', 'ParRename': 'yes', 'Unpack': 'no', 'DirectUnpack': 'no',
                  'UnpackCleanupDisk': 'no', 'ArticleCache': 10, 'WebDir': template / 'webui',
                  'ConfigTemplate': template / 'nzbget.conf', 'LogFile': directory / 'nzbget.log',
                  'NzbDirInterval': 0, 'OutputMode': 'log', 'HealthCheck': 'none', 'AppendCategoryDir': 'no', 'DupeCheck': 'no'}
        config.write_text(''.join(f'{key}={value}\n' for key, value in values.items()))
        command = [str(binary), '-c', str(config), '-s']
        def rpc(method, params):
            body = json.dumps({'method': method, 'params': params, 'id': 1}).encode()
            request = urllib.request.Request(f'http://127.0.0.1:{port}/jsonrpc', body, {'Content-Type': 'application/json'})
            response = json.load(urllib.request.urlopen(request, timeout=2))
            if response.get('error'):
                raise RuntimeError(str(response['error']))
            return response['result']
        def history():
            return rpc('history', [False])
    with (directory / 'console.log').open('w') as log:
        process = subprocess.Popen(command, stdout=log, stderr=subprocess.STDOUT, start_new_session=True)
        try:
            if client == 'nzbget':
                wait_for(lambda: rpc('version', []), process)
                subprocess.run([str(binary), '-c', str(config), '-A', str(nzb_file)], check=True, timeout=10, stdout=log, stderr=log)
            finished = wait_for(history, process, 90)
            if client == 'sabnzbd':
                assert finished[0]['status'] == 'Completed', finished
            else:
                assert finished[0]['Status'].startswith('SUCCESS'), finished
            truth = json.loads((root / 'truth.json').read_text())['files']
            actual = {}
            for path in complete.rglob('*'):
                if path.is_file():
                    with path.open('rb') as source:
                        digest = hashlib.file_digest(source, 'md5').hexdigest()
                    actual[digest] = path
            for name, expected in truth.items():
                assert expected['md5'] in actual, (client, name, 'download hash mismatch', [str(p.relative_to(complete)) for p in actual.values()])
                assert actual[expected['md5']].stat().st_size == expected['size']
            par2_path = actual[truth['fixture.par2']['md5']]
            check = subprocess.run(['par2', 'verify', '-q', str(par2_path)], capture_output=True, text=True, timeout=30)
            assert check.returncode == 0, check.stdout + check.stderr
            if 'fixture.part01.rar' in truth:
                first = actual[truth['fixture.part01.rar']['md5']]
                output = directory / 'extracted'
                output.mkdir()
                subprocess.run(['/usr/bin/unrar', 'x', '-p-', '-idq', '-o-', str(first), str(output) + '/'], check=True, timeout=15)
                assert hashlib.md5((output / 'fixture.bin').read_bytes()).hexdigest() == 'c9c88fab31c37d1a0a05fbbd429a53cc'
            return {'client': client, 'files': len(truth), 'whole_file_hashes': 'matched', 'par2': 'verified'}
        except Exception as error:
            log.flush()
            raise RuntimeError(f'{error}\n{(directory / "console.log").read_text()[-8000:]}') from error
        finally:
            try:
                os.killpg(process.pid, signal.SIGTERM)
            except ProcessLookupError:
                pass
            try:
                process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                pass
            finally:
                try:
                    os.killpg(process.pid, signal.SIGKILL)
                except ProcessLookupError:
                    pass
                process.wait(timeout=5)


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('root', type=Path)
    parser.add_argument('nzb', type=Path)
    parser.add_argument('port', type=int)
    parser.add_argument('client', choices=['sabnzbd', 'nzbget'])
    args = parser.parse_args()
    print(json.dumps(run_client(args.client, args.root, args.nzb, args.port)))
