#!/usr/bin/env python3
"""Integration checks using only Python's standard library and PHP."""
import concurrent.futures
import http.cookiejar
import json
import os
import pathlib
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

ROOT = pathlib.Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BIN', 'php')

with tempfile.TemporaryDirectory(prefix='webpantau-api-') as directory:
    with socket.socket() as listener:
        listener.bind(('127.0.0.1', 0))
        port = listener.getsockname()[1]
    environment = dict(os.environ, DATA_DIR=directory, SETUP_KEY='integration-setup-key')
    with tempfile.TemporaryFile() as log:
        server = subprocess.Popen([PHP, '-S', f'127.0.0.1:{port}', '-t', 'public', 'public/router.php'], cwd=ROOT, env=environment, stdout=log, stderr=log)
        try:
            opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
            base = f'http://127.0.0.1:{port}'
            for _ in range(100):
                try:
                    opener.open(base, timeout=1).close()
                    break
                except urllib.error.URLError:
                    time.sleep(0.05)
            else:
                raise AssertionError('PHP server did not start')
            csrf = ''
            def call(path, method='GET', body=None, use_csrf=True):
                req = urllib.request.Request(base + '/api' + path, method=method, headers={'Content-Type': 'application/json', 'X-CSRF-Token': csrf if use_csrf else ''}, data=None if method == 'GET' else json.dumps(body or {}).encode())
                try:
                    res = opener.open(req, timeout=5)
                except urllib.error.HTTPError as error:
                    res = error
                with res:
                    return res.status, json.load(res)
            assert call('/services')[0] == 401
            _, auth = call('/auth')
            assert auth['initialized'] is False
            csrf = auth['csrf']
            credentials = {'username': 'owner', 'password': 'long-integration-password'}
            assert call('/auth/setup', 'POST', credentials, False)[0] == 403
            assert call('/auth/setup', 'POST', credentials)[0] == 403
            assert call('/auth/setup', 'POST', dict(credentials, setupKey='integration-setup-key'))[0] == 200
            _, auth = call('/auth')
            csrf = auth['csrf']
            assert auth['authenticated'] is True
            assert call('/auth/setup', 'POST', dict(credentials, setupKey='integration-setup-key'))[0] == 409
            row = {'name': '<script>alert(1)</script>', 'client': 'Example', 'website': 'example.com', 'provider': 'Registrar', 'type': 'domain', 'cycle': 'yearly', 'expires': '2027-04-20', 'cost': '250000', 'notes': ''}
            assert call('/services', 'POST', dict(row, expires='2027-02-30'))[0] == 400
            status, created = call('/services', 'POST', row)
            assert status == 201
            assert created['website'] == 'https://example.com'
            assert created['clientContact'] == ''  # Old records/clients need no migration.
            row['clientContact'] = 'WhatsApp: +62 812-0000-0000 / owner@example.test'
            assert call('/services', 'POST', dict(row, clientContact='x' * 201))[0] == 400
            assert call('/services', 'POST', dict(row, clientContact=['invalid']))[0] == 400
            assert call('/services/' + created['id'], 'PUT', dict(row, expires='2028-04-20'))[0] == 200
            saved_row = call('/services')[1][0]
            assert saved_row['expires'] == '2028-04-20'
            assert saved_row['clientContact'] == row['clientContact']
            assert call('/services/' + created['id'], 'PUT', dict(row, clientContact=''))[0] == 200
            assert call('/services')[1][0]['clientContact'] == ''
            assert call('/services', 'POST', row, False)[0] == 403
            assert call('/telegram', 'PUT', {'chatId': '123', 'enabled': True, 'days': [7]})[0] == 400
            dummy = '123456:abcdefghijklmnopqrstuvwxyz'
            assert call('/telegram', 'PUT', {'token': dummy, 'chatId': '123', 'enabled': False, 'days': [30, 7, 0]})[0] == 200
            cfg = call('/telegram')[1]
            assert cfg['hasToken'] is True and 'token' not in cfg
            assert call('/telegram', 'PUT', {'token': '', 'chatId': '123', 'enabled': False, 'days': [7]})[0] == 200
            assert call('/telegram')[1]['hasToken'] is True
            assert call('/services/' + created['id'], 'DELETE')[0] == 200
            assert call('/services')[1] == []
            for target in ['/data/dashboard.json', '/app/bootstrap.php', '/bin/reminders.php', '/api.php', '/../data/dashboard.json']:
                try:
                    opener.open(base + target)
                    raise AssertionError('Private path exposed: ' + target)
                except urllib.error.HTTPError as error:
                    assert error.code == 404
            # Password resets also revoke active sessions and preserve service data.
            new_password = 'new-long-integration-password'
            subprocess.run([PHP, 'bin/set-password.php'], input=new_password.encode(), cwd=ROOT, env=environment, capture_output=True, check=True)
            assert call('/services')[0] == 401
            csrf = call('/auth')[1]['csrf']
            assert call('/auth/login', 'POST', dict(credentials, password=new_password))[0] == 200
            credentials['password'] = new_password
            csrf = call('/auth')[1]['csrf']
            assert call('/auth/logout', 'POST')[0] == 200
            assert call('/services')[0] == 401
            csrf = call('/auth')[1]['csrf']
            assert call('/auth/login', 'POST', dict(credentials, password='wrong-long-password'))[0] == 401
            assert call('/auth/login', 'POST', credentials)[0] == 200
            csrf = call('/auth')[1]['csrf']
            assert call('/auth/logout', 'POST')[0] == 200
            csrf = call('/auth')[1]['csrf']
            codes = [call('/auth/login', 'POST', dict(credentials, password='wrong-long-password'))[0] for _ in range(11)]
            assert 429 in codes
            print('PASS auth, CSRF, setup key, CRUD, Telegram token privacy, private paths, logout and login rate limit')
            # Real independent PHP writers must serialize through the same lock file.
            script = "require 'app/bootstrap.php'; with_store(function(array &$s):void { $s['services'][]=['id'=>bin2hex(random_bytes(8))]; usleep(5000); }, true);"
            with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
                results = list(pool.map(lambda _: subprocess.run([PHP, '-r', script], cwd=ROOT, env=environment, capture_output=True, check=True), range(25)))
            saved = json.loads(pathlib.Path(directory, 'dashboard.json').read_text())
            assert len(saved['services']) == 25
            print('PASS 25 concurrent PHP writers preserve every record')
        finally:
            server.terminate()
            server.wait(timeout=5)
