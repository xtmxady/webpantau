#!/usr/bin/env python3
"""Exercise authenticated backup/download/restore against a real PHP server."""
import copy
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

with tempfile.TemporaryDirectory(prefix='webpantau-backup-api-') as directory:
    with socket.socket() as listener:
        listener.bind(('127.0.0.1', 0))
        port = listener.getsockname()[1]
    environment = dict(os.environ, DATA_DIR=directory, SETUP_KEY='backup-test-setup')
    with tempfile.TemporaryFile() as log:
        server = subprocess.Popen([PHP, '-S', f'127.0.0.1:{port}', '-t', 'public', 'public/router.php'], cwd=ROOT, env=environment, stdout=log, stderr=log)
        try:
            base = f'http://127.0.0.1:{port}'
            for _ in range(100):
                try:
                    urllib.request.urlopen(base, timeout=1).close()
                    break
                except urllib.error.URLError:
                    time.sleep(0.05)
            else:
                raise AssertionError('PHP server did not start')

            class Client:
                def __init__(self):
                    self.cookies = http.cookiejar.CookieJar()
                    self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cookies))
                    self.csrf = ''

                def call(self, path, method='GET', body=None, csrf=True, raw=None):
                    payload = raw if raw is not None else json.dumps(body or {}, separators=(',', ':')).encode()
                    request = urllib.request.Request(base + '/api' + path, method=method,
                        headers={'Content-Type': 'application/json', 'X-CSRF-Token': self.csrf if csrf else ''},
                        data=None if method == 'GET' else payload)
                    try:
                        response = self.opener.open(request, timeout=10)
                    except urllib.error.HTTPError as error:
                        response = error
                    with response:
                        self.headers = response.headers
                        return response.status, json.load(response)

                def refresh(self):
                    self.csrf = self.call('/auth')[1]['csrf']

            owner = Client()
            guest = Client()
            owner.refresh()
            guest.refresh()
            assert guest.call('/backup/export')[0] == 401
            assert guest.call('/backup/validate', 'POST', {'backup': {}})[0] == 401
            assert guest.call('/backup/import', 'POST', {'importId': 'a' * 64, 'confirm': True})[0] == 401
            credentials = {'username': 'owner', 'password': 'long-backup-test-password', 'setupKey': 'backup-test-setup'}
            assert owner.call('/auth/setup', 'POST', credentials)[0] == 200
            owner.refresh()
            row = {'name': 'example.com', 'client': 'Toko Satu', 'clientContact': '+62 812-0000 / owner@example.com',
                'website': 'https://example.com', 'provider': 'Registrar', 'type': 'domain', 'cycle': 'yearly',
                'expires': '2027-10-07', 'cost': '150000', 'notes': 'Simpan kontak klien.'}
            code, created = owner.call('/services', 'POST', row)
            assert code == 201
            telegram = {'token': '123456:abcdefghijklmnopqrstuvwxyz', 'chatId': '-100123456', 'enabled': True, 'days': [30, 7, 0]}
            assert owner.call('/telegram', 'PUT', telegram)[0] == 200
            store_path = pathlib.Path(directory, 'dashboard.json')
            original = json.loads(store_path.read_text())
            cookie_before = [(cookie.name, cookie.value) for cookie in owner.cookies]

            code, plain = owner.call('/backup/export')
            assert code == 200 and plain['data']['services'] == [created]
            assert owner.headers['Content-Disposition'].startswith('attachment; filename="webpantau-backup-')
            assert 'no-store' in owner.headers['Cache-Control']
            assert set(plain['data']) == {'services', 'sent'}
            assert 'passwordHash' not in json.dumps(plain) and telegram['token'] not in json.dumps(plain)
            code, full = owner.call('/backup/export?includeTelegram=1')
            assert code == 200 and full['data']['telegram'] == telegram
            assert owner.call('/backup/export?includeTelegram[]=1')[0] == 400
            assert owner.call('/backup/validate', 'POST', {'backup': full}, csrf=False)[0] == 403
            assert owner.call('/backup/import', 'POST', {'importId': 'a' * 64, 'confirm': True}, csrf=False)[0] == 403
            before = store_path.read_bytes()
            bad = copy.deepcopy(full)
            bad['data']['services'].append(bad['data']['services'][0])
            assert owner.call('/backup/validate', 'POST', {'backup': bad})[0] == 400
            assert owner.call('/backup/validate', 'POST', raw=b'{bad-json')[0] == 400
            assert owner.call('/backup/validate', 'POST', raw=b' ' * (5 * 1024 * 1024 + 1))[0] == 413
            assert owner.call('/services', 'POST', raw=b' ' * 32769)[0] == 413
            assert store_path.read_bytes() == before

            code, staged = owner.call('/backup/validate', 'POST', {'backup': full})
            assert code == 200 and staged['serviceCount'] == 1 and staged['includesTelegram'] is True
            assert set(staged) == {'importId', 'serviceCount', 'currentServiceCount', 'includesTelegram', 'exportedAt'}
            stage_file = pathlib.Path(directory, 'imports', staged['importId'] + '.json')
            assert stage_file.stat().st_mode & 0o777 == 0o600
            assert owner.call('/backup/import', 'POST', {'importId': staged['importId'], 'confirm': 'true'})[0] == 400
            assert owner.call('/backup/import', 'POST', {'importId': '../dashboard', 'confirm': True})[0] == 400

            other_session = Client()
            other_session.refresh()
            assert other_session.call('/auth/login', 'POST', credentials)[0] == 200
            other_session.refresh()
            assert other_session.call('/backup/import', 'POST', {'importId': staged['importId'], 'confirm': True})[0] == 409
            code, imported = owner.call('/backup/import', 'POST', {'importId': staged['importId'], 'confirm': True})
            assert code == 200 and imported['telegramDisabled'] is True
            after = json.loads(store_path.read_text())
            assert after['user'] == original['user'] and after['attempts'] == original['attempts']
            assert after['services'] == original['services'] and after['sent'] == original['sent']
            assert after['telegram'] == dict(telegram, enabled=False)
            assert [(cookie.name, cookie.value) for cookie in owner.cookies] == cookie_before
            recovery_path = pathlib.Path(directory, 'backups', imported['recoveryBackup'])
            assert recovery_path.stat().st_mode & 0o777 == 0o600
            recovery = json.loads(recovery_path.read_text())
            assert recovery['data']['telegram'] == telegram
            assert owner.call('/backup/import', 'POST', {'importId': staged['importId'], 'confirm': True})[0] == 409

            # A backup exported without Telegram replaces service data and retains local settings.
            assert owner.call('/services/' + created['id'], 'PUT', dict(row, clientContact='changed@example.com'))[0] == 200
            stage = owner.call('/backup/validate', 'POST', {'backup': plain})[1]
            assert stage['includesTelegram'] is False
            assert owner.call('/backup/import', 'POST', {'importId': stage['importId'], 'confirm': True})[1]['telegramDisabled'] is False
            assert owner.call('/services')[1][0]['clientContact'] == row['clientContact']
            assert json.loads(store_path.read_text())['telegram'] == dict(telegram, enabled=False)

            # A changed service after preview must not be overwritten using that preview.
            stage = owner.call('/backup/validate', 'POST', {'backup': full})[1]
            assert owner.call('/services/' + created['id'], 'PUT', dict(row, notes='Changed after preview'))[0] == 200
            before = store_path.read_bytes()
            assert owner.call('/backup/import', 'POST', {'importId': stage['importId'], 'confirm': True})[0] == 409
            assert store_path.read_bytes() == before

            # Legitimate backups above the normal 32 KiB API limit are supported.
            large = copy.deepcopy(plain)
            large['data']['services'] = [dict(created, id=f'large-{i}', notes='x' * 1900) for i in range(24)]
            assert len(json.dumps(large)) > 32768
            code, stage = owner.call('/backup/validate', 'POST', {'backup': large})
            assert code == 200
            assert owner.call('/backup/import', 'POST', {'importId': stage['importId'], 'confirm': True})[0] == 200
            assert len(owner.call('/services')[1]) == 24

            # Empty backups require the same explicit confirmation before replacing current data.
            empty = copy.deepcopy(plain)
            empty['data'] = {'services': [], 'sent': []}
            stage = owner.call('/backup/validate', 'POST', {'backup': empty})[1]
            assert stage['serviceCount'] == 0 and stage['currentServiceCount'] == 24
            assert owner.call('/backup/import', 'POST', {'importId': stage['importId'], 'confirm': False})[0] == 400
            assert len(owner.call('/services')[1]) == 24
            assert owner.call('/backup/import', 'POST', {'importId': stage['importId'], 'confirm': True})[0] == 200
            assert owner.call('/services')[1] == []

            # The verified pre-import file is an ordinary backup that can restore the old data.
            stage = owner.call('/backup/validate', 'POST', {'backup': recovery})[1]
            assert owner.call('/backup/import', 'POST', {'importId': stage['importId'], 'confirm': True})[0] == 200
            assert owner.call('/services')[1] == [created]
            assert json.loads(store_path.read_text())['user'] == original['user']
            for target in ['/data/backups/' + imported['recoveryBackup'], '/data/imports/' + staged['importId'] + '.json']:
                try:
                    owner.opener.open(base + target)
                    raise AssertionError('Private backup exposed')
                except urllib.error.HTTPError as error:
                    assert error.code == 404
            print('PASS backup auth/CSRF, downloads, owner/contact preservation, private recovery/restore, strict validation/size limits, session/revision binding, large and empty imports')
        finally:
            server.terminate()
            server.wait(timeout=5)
