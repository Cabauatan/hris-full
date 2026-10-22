import assert from 'node:assert/strict';
import http from 'node:http';
import net from 'node:net';
import fs from 'node:fs';
import { mkdtemp, mkdir, writeFile, rm, symlink } from 'node:fs/promises';
import { syncBuiltinESMExports } from 'node:module';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { parseCli, startGateway } from './public-gateway.mjs';

const origin = 'https://hris-fixture-test.trycloudflare.com';
const publicHost = 'hris-fixture-test.trycloudflare.com';

async function listen(server) {
    await new Promise((resolve, reject) => {
        server.once('error', reject);
        server.listen(0, '127.0.0.1', resolve);
    });
    return server.address().port;
}

async function fixture(t, { configured = true } = {}) {
    const root = await mkdtemp(path.join(os.tmpdir(), 'hris-gateway-test-'));
    const dist = path.join(root, 'dist');
    const state = path.join(root, 'gateway-state.json');
    await mkdir(path.join(dist, 'assets'), { recursive: true });
    await writeFile(path.join(dist, 'index.html'), '<html>HRIS fixture</html>');
    await writeFile(path.join(dist, 'assets', 'index-AbCd1234.js'), 'export const fixture = true;');
    await writeFile(path.join(dist, 'hris-icon.png'), Buffer.from([137, 80, 78, 71]));
    await writeFile(path.join(dist, '.env'), 'private-fixture');
    await writeFile(path.join(root, 'outside-secret.txt'), 'outside-fixture');
    if (configured) {
        await writeFile(state, JSON.stringify({ url: origin }));
    }

    const apiRequests = [];
    const backend = http.createServer(async (request, response) => {
        const chunks = [];
        for await (const chunk of request) {
            chunks.push(chunk);
        }
        const received = { method: request.method, url: request.url, headers: request.headers, body: Buffer.concat(chunks).toString() };
        apiRequests.push(received);
        response.writeHead(201, {
            'Content-Type': 'application/json',
            'Set-Cookie': ['hris_session=fixture; Secure; HttpOnly; SameSite=Lax', 'XSRF-TOKEN=fixture; Secure; SameSite=Lax'],
            'X-Powered-By': 'do-not-disclose',
        });
        response.end(JSON.stringify(received));
    });
    const websocketRequests = [];
    const websocketSockets = new Set();
    const reverb = http.createServer();
    reverb.on('connection', (socket) => {
        websocketSockets.add(socket);
        socket.on('close', () => websocketSockets.delete(socket));
    });
    reverb.on('upgrade', (request, socket, head) => {
        websocketRequests.push({ url: request.url, headers: request.headers });
        socket.write('HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n\r\n');
        if (head.length) {
            socket.write(`RELAY:${head.toString()}`);
        }
        socket.on('data', (data) => socket.write(`RELAY:${data.toString()}`));
    });
    const backendPort = await listen(backend);
    const reverbPort = await listen(reverb);
    const gateway = await startGateway({ dist, state, port: 0, backendPort, reverbPort });

    t.after(async () => {
        await gateway.closeGateway();
        for (const socket of websocketSockets) {
            socket.destroy();
        }
        await Promise.all([backend, reverb].map((server) => new Promise((resolve) => server.close(resolve))));
        await rm(root, { recursive: true, force: true });
    });

    return { root, dist, state, gateway, backend, backendPort, apiRequests, websocketRequests };
}

function request(gateway, requestPath, { method = 'GET', headers = {}, body, agent = false } = {}) {
    return new Promise((resolve, reject) => {
        const outgoing = http.request({
            host: '127.0.0.1', port: gateway.address().port, path: requestPath, method,
            headers: { Host: publicHost, ...headers }, agent,
        }, (response) => {
            const chunks = [];
            response.on('data', (chunk) => chunks.push(chunk));
            response.on('end', () => resolve({ status: response.statusCode, headers: response.headers, body: Buffer.concat(chunks).toString(), reusedSocket: outgoing.reusedSocket }));
            response.on('error', reject);
        });
        outgoing.setTimeout(3000, () => outgoing.destroy(new Error('Fixture request timed out.')));
        outgoing.on('error', reject);
        outgoing.end(body);
    });
}

function upgrade(gateway, { host = publicHost, url = '/app/fixtureKey?protocol=7', requestOrigin = origin, payload = '' } = {}) {
    return new Promise((resolve, reject) => {
        const socket = net.connect({ host: '127.0.0.1', port: gateway.address().port });
        let received = '';
        socket.setTimeout(3000, () => socket.destroy(new Error('Fixture upgrade timed out.')));
        socket.on('connect', () => {
            const originHeader = requestOrigin === null ? '' : `Origin: ${requestOrigin}\r\n`;
            socket.write(`GET ${url} HTTP/1.1\r\nHost: ${host}\r\n${originHeader}Connection: Upgrade\r\nUpgrade: websocket\r\nSec-WebSocket-Version: 13\r\nSec-WebSocket-Key: AAAAAAAAAAAAAAAAAAAAAA==\r\n\r\n${payload}`);
        });
        socket.on('data', (chunk) => {
            received += chunk.toString();
            if ((received.includes('\r\n\r\n') && !received.startsWith('HTTP/1.1 101')) || received.includes(`RELAY:${payload}`)) {
                socket.destroy();
                resolve(received);
            }
        });
        socket.on('end', () => resolve(received));
        socket.on('error', reject);
    });
}

test('gateway binds only loopback and exposes readiness only with a local Host', async (t) => {
    const { gateway } = await fixture(t, { configured: false });
    assert.equal(gateway.address().address, '127.0.0.1');
    assert.equal((await request(gateway, '/__gateway/health', { headers: { Host: `127.0.0.1:${gateway.address().port}` } })).status, 200);
    assert.equal((await request(gateway, '/__gateway/health', { headers: { Host: 'localhost' } })).status, 200);
    assert.equal((await request(gateway, '/', { headers: { Accept: 'text/html' } })).status, 503);
    assert.equal((await request(gateway, '/__gateway/health')).status, 503);
});

test('state reload immediately enables only its exact HTTPS public host and rejects unsafe state URLs', async (t) => {
    const { gateway, state } = await fixture(t, { configured: false });
    for (const url of ['http://hris-fixture-test.trycloudflare.com', 'https://example.com', 'https://x.trycloudflare.com/path', 'https://user@x.trycloudflare.com', 'https://x.trycloudflare.com:8080', 'https://x.trycloudflare.com/#secret']) {
        await writeFile(state, JSON.stringify({ url }));
        assert.equal((await request(gateway, '/')).status, 503, url);
    }
    await writeFile(state, `\uFEFF${JSON.stringify({ url: origin })}`);
    assert.equal((await request(gateway, '/')).status, 200);
    assert.equal((await request(gateway, '/', { headers: { Host: 'other.trycloudflare.com' } })).status, 403);
    assert.equal((await request(gateway, '/', { headers: { Host: '127.0.0.1' } })).status, 403);
    assert.equal((await request(gateway, '/__gateway/health')).status, 404);
    await writeFile(state, '{bad json');
    assert.equal((await request(gateway, '/')).status, 503);
    await writeFile(state, 'x'.repeat(4097));
    assert.equal((await request(gateway, '/')).status, 503);
});

test('built static assets preserve content types, HEAD semantics, and immutable hashed asset caching', async (t) => {
    const { gateway } = await fixture(t);
    const asset = await request(gateway, '/assets/index-AbCd1234.js?cache=1');
    assert.equal(asset.status, 200);
    assert.equal(asset.body, 'export const fixture = true;');
    assert.equal(asset.headers['content-type'], 'text/javascript; charset=utf-8');
    assert.equal(asset.headers['cache-control'], 'public, max-age=31536000, immutable');
    const head = await request(gateway, '/assets/index-AbCd1234.js', { method: 'HEAD' });
    assert.equal(head.status, 200);
    assert.equal(head.body, '');
    assert.equal(Number(head.headers['content-length']), asset.body.length);
    const icon = await request(gateway, '/hris-icon.png');
    assert.equal(icon.headers['content-type'], 'image/png');
    assert.equal(icon.headers['cache-control'], 'no-cache');
});

test('SPA reload falls back only for extensionless browser GET and HEAD routes', async (t) => {
    const { gateway } = await fixture(t);
    const reload = await request(gateway, '/manage/work-schedule-days?edit=1', { headers: { Accept: 'text/html' } });
    assert.equal(reload.status, 200);
    assert.equal(reload.body, '<html>HRIS fixture</html>');
    assert.equal(reload.headers['cache-control'], 'no-cache');
    assert.equal(reload.headers['x-frame-options'], 'DENY');
    assert.equal((await request(gateway, '/manage/work-schedule-days', { method: 'HEAD', headers: { Accept: 'text/html' } })).body, '');
    assert.equal((await request(gateway, '/manage/work-schedule-days', { headers: { Accept: 'application/json' } })).status, 404);
    assert.equal((await request(gateway, '/assets/missing.js', { headers: { Accept: 'text/html' } })).status, 404);
    assert.equal((await request(gateway, '/login', { method: 'POST', headers: { Accept: 'text/html' } })).status, 405);
});

test('Chrome and Firefox navigation headers work across requests on the same keepalive connection', async (t) => {
    const { gateway } = await fixture(t);
    const agent = new http.Agent({ keepAlive: true, maxSockets: 1 });
    t.after(() => agent.destroy());
    const userAgents = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:144.0) Gecko/20100101 Firefox/144.0',
    ];
    for (const userAgent of userAgents) {
        const navigation = await request(gateway, '/auth/login?redirect=/dashboard', {
            agent, headers: {
                'User-Agent': userAgent,
                Accept: 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Encoding': 'gzip, deflate, br, zstd',
                'Sec-Fetch-Dest': 'document', 'Sec-Fetch-Mode': 'navigate', 'Sec-Fetch-Site': 'none',
            },
        });
        assert.equal(navigation.status, 200);
        assert.equal(navigation.body, '<html>HRIS fixture</html>');
        const asset = await request(gateway, '/assets/index-AbCd1234.js', { agent });
        assert.equal(asset.status, 200);
        assert.equal(asset.reusedSocket, true);
        assert.equal(asset.body, 'export const fixture = true;');
    }
});

test('public HTTP navigation redirects to the exact HTTPS state origin while unsafe HTTP methods never reach the API', async (t) => {
    const { gateway, apiRequests } = await fixture(t);
    for (const headers of [
        { 'X-Forwarded-Proto': 'http' },
        { 'CF-Visitor': '{"scheme":"http"}' },
        { 'X-Forwarded-Proto': 'http', Origin: `http://${publicHost}` },
    ]) {
        const redirect = await request(gateway, '/auth/login?redirect=%2Fdashboard', { headers });
        assert.equal(redirect.status, 307);
        assert.equal(redirect.headers.location, `${origin}/auth/login?redirect=%2Fdashboard`);
        assert.equal(redirect.headers['cache-control'], 'no-store');
        assert.equal(redirect.headers['set-cookie'], undefined);
        assert.equal((await request(gateway, '/auth/login', { method: 'HEAD', headers })).body, '');
        const unsafe = await request(gateway, '/api/v1/auth/login', { method: 'POST', headers, body: 'fixture' });
        assert.equal(unsafe.status, 403);
        assert.equal(unsafe.body, 'HTTPS is required.\n');
    }
    assert.equal(apiRequests.length, 0);
    assert.equal((await request(gateway, '//evil.example', { headers: { 'X-Forwarded-Proto': 'http' } })).status, 404);
    assert.equal((await request(gateway, '/auth/login', { headers: { 'X-Forwarded-Proto': 'http', Host: 'evil.example' } })).status, 403);
    assert.equal((await request(gateway, '/auth/login', { headers: { 'X-Forwarded-Proto': 'http', Origin: 'https://evil.example' } })).status, 403);
    assert.equal((await request(gateway, '/auth/login', { headers: { Accept: 'text/html', 'X-Forwarded-Proto': 'https', 'CF-Visitor': '{"scheme":"https"}' } })).status, 200);
    assert.equal((await request(gateway, '/auth/login', { headers: { Accept: 'text/html', 'CF-Visitor': 'invalid' } })).status, 200);
    const health = await request(gateway, '/__gateway/health', { headers: { Host: 'localhost', 'X-Forwarded-Proto': 'http' } });
    assert.equal(health.status, 200);
    assert.equal(health.headers.location, undefined);
});

test('an interrupted reused connection closes without appending an unsolicited HTTP error response', async (t) => {
    const { gateway } = await fixture(t);
    const received = await new Promise((resolve, reject) => {
        const socket = net.connect({ host: '127.0.0.1', port: gateway.address().port });
        let body = '';
        let interrupted = false;
        socket.setTimeout(3000, () => socket.destroy(new Error('Fixture interrupted request timed out.')));
        socket.on('connect', () => socket.write(`GET / HTTP/1.1\r\nHost: ${publicHost}\r\nConnection: keep-alive\r\n\r\n`));
        socket.on('data', (chunk) => {
            body += chunk.toString();
            if (!interrupted && body.endsWith('<html>HRIS fixture</html>')) {
                interrupted = true;
                socket.end('GET / HTTP/1.1\r\nHost:');
            }
        });
        socket.on('error', reject);
        socket.on('close', () => resolve(body));
    });
    assert.equal(received.split('HTTP/1.1 ').length - 1, 1);
    assert.ok(received.startsWith('HTTP/1.1 200 '));
    assert.equal((await request(gateway, '/')).status, 200);
});

test('an invalid initial HTTP request still receives a single bad-request response', async (t) => {
    const { gateway } = await fixture(t);
    const received = await new Promise((resolve, reject) => {
        const socket = net.connect({ host: '127.0.0.1', port: gateway.address().port });
        let body = '';
        socket.setTimeout(3000, () => socket.destroy(new Error('Fixture malformed request timed out.')));
        socket.on('connect', () => socket.write(`GET / HTTP/1.1\r\nHost: ${publicHost}\r\nMalformed header\r\n\r\n`));
        socket.on('data', (chunk) => { body += chunk.toString(); });
        socket.on('error', reject);
        socket.on('close', () => resolve(body));
    });
    assert.equal(received.split('HTTP/1.1 ').length - 1, 1);
    assert.ok(received.startsWith('HTTP/1.1 400 Bad Request'));
    assert.equal((await request(gateway, '/')).status, 200);
});

test('connection reset and header timeout errors close sockets without writing HTTP responses', async (t) => {
    const { gateway } = await fixture(t);
    for (const code of ['ECONNRESET', 'ERR_HTTP_REQUEST_TIMEOUT']) {
        gateway.once('connection', (socket) => {
            gateway.emit('clientError', Object.assign(new Error('Fixture transport error.'), { code }), socket);
        });
        const received = await new Promise((resolve, reject) => {
            const socket = net.connect({ host: '127.0.0.1', port: gateway.address().port });
            let body = '';
            socket.setTimeout(3000, () => socket.destroy(new Error('Fixture transport error timed out.')));
            socket.on('data', (chunk) => { body += chunk.toString(); });
            socket.on('error', reject);
            socket.on('close', () => resolve(body));
        });
        assert.equal(received, '', code);
        assert.equal((await request(gateway, '/')).status, 200);
    }
});

test('an aborted static request closes its opened file even when the response closes before stat finishes', async (t) => {
    const { gateway, dist } = await fixture(t);
    const filename = path.join(dist, 'slow.bin');
    await writeFile(filename, Buffer.alloc(1024, 7));
    let releaseStat;
    const statGate = new Promise((resolve) => { releaseStat = resolve; });
    let reportOpened;
    const opened = new Promise((resolve) => { reportOpened = resolve; });
    const originalOpen = fs.promises.open;
    let handle;
    const mockedOpen = t.mock.method(fs.promises, 'open', async (...args) => {
        const file = await originalOpen(...args);
        if (args[0] === filename) {
            handle = file;
            const originalStat = file.stat.bind(file);
            t.mock.method(file, 'stat', async () => {
                reportOpened();
                await statGate;
                return originalStat();
            });
        }
        return file;
    });
    syncBuiltinESMExports();
    t.after(async () => {
        releaseStat();
        mockedOpen.mock.restore();
        syncBuiltinESMExports();
        await handle?.close();
    });
    const responseClosed = new Promise((resolve) => {
        gateway.once('request', (_, response) => response.once('close', resolve));
    });
    const outgoing = http.request({
        host: '127.0.0.1', port: gateway.address().port, path: '/slow.bin',
        headers: { Host: publicHost }, agent: false,
    });
    outgoing.on('error', () => {});
    outgoing.end();
    await opened;
    outgoing.destroy();
    await responseClosed;
    releaseStat();
    const deadline = Date.now() + 1000;
    while (handle.fd !== -1 && Date.now() < deadline) {
        await new Promise(setImmediate);
    }
    assert.equal(handle.fd, -1);
    assert.equal((await request(gateway, '/')).status, 200);
});

test('dotfiles, traversal, PHP, storage, Reverb publishing, and state paths never reach the API or SPA', async (t) => {
    const { gateway, apiRequests } = await fixture(t);
    for (const url of ['/.env', '/%2eenv', '/../outside-secret.txt', '/%2e%2e/outside-secret.txt', '/%252e%252e/outside-secret.txt', '/%5c..%5csecret', '/assets/../index.html', '/index.php', '/INDEX.PHP/test', '/storage/private', '/apps/123/events', '/__gateway/state', '/index.html:secret', '/bad%ZZ']) {
        const response = await request(gateway, url, { headers: { Accept: 'text/html' } });
        assert.equal(response.status, 404, url);
        assert.equal(response.body, 'Not found.\n', url);
    }
    assert.equal(apiRequests.length, 0);
});

test('a directory junction or symlink outside dist cannot expose its contents', async (t) => {
    const { gateway, root, dist } = await fixture(t);
    await symlink(root, path.join(dist, 'outside'), process.platform === 'win32' ? 'junction' : 'dir');
    assert.equal((await request(gateway, '/outside/outside-secret.txt')).status, 404);
});

test('a file symlink outside dist cannot expose its contents when the OS permits creating one', async (t) => {
    const { gateway, root, dist } = await fixture(t);
    try {
        await symlink(path.join(root, 'outside-secret.txt'), path.join(dist, 'leak.txt'));
    } catch (error) {
        if (error.code === 'EPERM') {
            t.skip('The current Windows account cannot create symlinks.');
            return;
        }
        throw error;
    }
    assert.equal((await request(gateway, '/leak.txt')).status, 404);
});

test('API proxy preserves methods, body, query, session cookies and sends canonical HTTPS proxy headers', async (t) => {
    const { gateway, apiRequests } = await fixture(t);
    const response = await request(gateway, '/api/v1/example?filter=one', {
        method: 'POST', body: '{"fixture":true}',
        headers: {
            Origin: origin, 'Content-Type': 'application/json', Cookie: 'hris_session=inbound-fixture',
            Forwarded: 'host=evil.example;proto=http', 'X-Forwarded-Host': 'evil.example',
            'X-Forwarded-Proto': 'untrusted-scheme', 'X-Forwarded-For': '203.0.113.99',
            'CF-Connecting-IP': '198.51.100.23',
        },
    });
    assert.equal(response.status, 201);
    assert.equal(apiRequests.length, 1);
    const received = apiRequests[0];
    assert.equal(received.method, 'POST');
    assert.equal(received.url, '/api/v1/example?filter=one');
    assert.equal(received.body, '{"fixture":true}');
    assert.equal(received.headers.cookie, 'hris_session=inbound-fixture');
    assert.equal(received.headers.host, publicHost);
    assert.equal(received.headers['x-forwarded-proto'], 'https');
    assert.equal(received.headers['x-forwarded-host'], publicHost);
    assert.equal(received.headers['x-forwarded-port'], '443');
    assert.equal(received.headers['x-forwarded-for'], '198.51.100.23');
    assert.equal(received.headers.forwarded, undefined);
    assert.deepEqual(response.headers['set-cookie'], ['hris_session=fixture; Secure; HttpOnly; SameSite=Lax', 'XSRF-TOKEN=fixture; Secure; SameSite=Lax']);
    assert.equal(response.headers['x-powered-by'], undefined);
});

test('API rejects cross-origin requests and falls back to loopback for malformed provider client IP', async (t) => {
    const { gateway, apiRequests } = await fixture(t);
    assert.equal((await request(gateway, '/api/v1/example', { headers: { Origin: 'https://evil.example' } })).status, 403);
    assert.equal((await request(gateway, '/api/v1/example', { headers: { Origin: 'null' } })).status, 403);
    assert.equal((await request(gateway, '/api/v1/example', { headers: { Origin: '' } })).status, 403);
    assert.equal(apiRequests.length, 0);
    assert.equal((await request(gateway, '/api', { headers: { 'CF-Connecting-IP': '198.51.100.23, 203.0.113.5', 'X-Forwarded-For': '203.0.113.99' } })).status, 201);
    assert.equal(apiRequests[0].headers['x-forwarded-for'], '127.0.0.1');
    assert.equal((await request(gateway, '/up')).status, 201);
});

test('API refuses oversized uploads before contacting the backend', async (t) => {
    const { gateway, apiRequests } = await fixture(t);
    const response = await request(gateway, '/api/v1/upload', {
        method: 'POST', headers: { 'Content-Length': String(32 * 1024 * 1024 + 1) },
    });
    assert.equal(response.status, 413);
    assert.equal(apiRequests.length, 0);
});

test('backend connection failure produces a generic 502 without local details', async (t) => {
    const { gateway, backend } = await fixture(t);
    await new Promise((resolve) => backend.close(resolve));
    const response = await request(gateway, '/api/v1/example');
    assert.equal(response.status, 502);
    assert.equal(response.body, 'Backend is unavailable.\n');
});

test('WebSocket upgrade relays the initial socket payload and preserves the expected secure origin', async (t) => {
    const { gateway, websocketRequests } = await fixture(t);
    const response = await upgrade(gateway, { payload: 'client-payload' });
    assert.ok(response.startsWith('HTTP/1.1 101 Switching Protocols'));
    assert.ok(response.includes('RELAY:client-payload'));
    assert.equal(websocketRequests.length, 1);
    assert.equal(websocketRequests[0].url, '/app/fixtureKey?protocol=7');
    assert.equal(websocketRequests[0].headers.origin, origin);
    assert.equal(websocketRequests[0].headers.host, publicHost);
    assert.equal(websocketRequests[0].headers['x-forwarded-proto'], 'https');
});

test('WebSocket rejects missing or foreign Origin, foreign Host, and publishing endpoints', async (t) => {
    const { gateway, websocketRequests } = await fixture(t);
    for (const options of [{ requestOrigin: null }, { requestOrigin: 'https://evil.example' }, { host: 'evil.example' }]) {
        assert.ok((await upgrade(gateway, options)).startsWith('HTTP/1.1 403'));
    }
    assert.ok((await upgrade(gateway, { url: '/apps/123/events' })).startsWith('HTTP/1.1 400'));
    assert.ok((await upgrade(gateway, { url: '/app/key/extra' })).startsWith('HTTP/1.1 400'));
    assert.equal(websocketRequests.length, 0);
    assert.equal((await request(gateway, '/app/fixtureKey')).status, 426);
});

test('CLI parsing rejects injected hosts, invalid ports, duplicate flags, and unknown switches', () => {
    assert.deepEqual(parseCli(['--dist', 'some dist', '--state', 'state.json', '--port', '5003']), { dist: 'some dist', state: 'state.json', port: '5003' });
    for (const args of [['--port', '0'], ['--port', '65536'], ['--port', '-1'], ['--backend-port', '8003;echo'], ['--reverb-port', 'https://evil.example'], ['--host', '0.0.0.0'], ['--dist'], ['--dist', 'one', '--dist', 'two']]) {
        assert.throws(() => parseCli(args));
    }
});

test('startup rejects a state file inside dist and colliding or malformed upstream ports', async (t) => {
    const { dist, state } = await fixture(t);
    await assert.rejects(startGateway({ dist, state: path.join(dist, 'state.json'), port: 0 }));
    await assert.rejects(startGateway({ dist, state, port: 5003, backendPort: 5003 }));
    await assert.rejects(startGateway({ dist, state, port: 0, reverbPort: '8082/evil' }));
    await assert.rejects(startGateway({ dist: path.join(dist, 'missing'), state, port: 0 }));
});
