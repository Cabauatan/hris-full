import http from 'node:http';
import net from 'node:net';
import { constants, lstatSync, readFileSync, realpathSync, statSync } from 'node:fs';
import { open } from 'node:fs/promises';
import { pipeline } from 'node:stream/promises';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const maximumBodyBytes = 32 * 1024 * 1024;
const contentTypes = new Map([
    ['.html', 'text/html; charset=utf-8'], ['.js', 'text/javascript; charset=utf-8'],
    ['.mjs', 'text/javascript; charset=utf-8'], ['.css', 'text/css; charset=utf-8'],
    ['.json', 'application/json; charset=utf-8'], ['.svg', 'image/svg+xml'],
    ['.png', 'image/png'], ['.jpg', 'image/jpeg'], ['.jpeg', 'image/jpeg'],
    ['.webp', 'image/webp'], ['.avif', 'image/avif'], ['.gif', 'image/gif'],
    ['.ico', 'image/x-icon'], ['.woff', 'font/woff'], ['.woff2', 'font/woff2'],
    ['.ttf', 'font/ttf'], ['.webmanifest', 'application/manifest+json'],
]);

function portNumber(value, name, ephemeral = false) {
    if (!/^\d+$/.test(String(value))) {
        throw new Error(`${name} must be a valid TCP port.`);
    }

    const port = Number(value);
    if (!Number.isInteger(port) || port < (ephemeral ? 0 : 1) || port > 65535) {
        throw new Error(`${name} must be a valid TCP port.`);
    }

    return port;
}

function publicOrigin(statePath) {
    try {
        const stateStat = lstatSync(statePath);
        if (!stateStat.isFile() || stateStat.isSymbolicLink() || stateStat.size > 4096) {
            return null;
        }

        const state = JSON.parse(readFileSync(statePath, 'utf8').replace(/^\uFEFF/, ''));
        if (typeof state.url !== 'string') {
            return null;
        }

        const url = new URL(state.url);
        if (url.protocol !== 'https:' || url.username || url.password || url.port
            || url.pathname !== '/' || url.search || url.hash
            || !/^[a-z0-9]+(?:-[a-z0-9]+)*\.trycloudflare\.com$/.test(url.hostname)) {
            return null;
        }

        return url;
    } catch {
        return null;
    }
}

function plainResponse(response, status, message, method = 'GET') {
    if (response.headersSent || response.destroyed) {
        return;
    }

    response.writeHead(status, {
        'Content-Type': 'text/plain; charset=utf-8',
        'Cache-Control': 'no-store',
        'X-Content-Type-Options': 'nosniff',
        'X-Frame-Options': 'DENY',
        'Referrer-Policy': 'strict-origin-when-cross-origin',
    });
    response.end(method === 'HEAD' ? undefined : `${message}\n`);
}

function requestPath(request) {
    if (!request.url?.startsWith('/') || request.url.startsWith('//') || request.url.length > 8192) {
        return null;
    }

    try {
        const decoded = decodeURIComponent(request.url.split('?')[0]);
        if (/[\\:\u0000-\u001f\u007f%]/.test(decoded)
            || decoded.split('/').some((segment) => segment.startsWith('.') || /[. ]$/.test(segment))
            || /\.php(?:[/.]|$)/i.test(decoded)
            || /^\/(?:apps|storage|__gateway)(?:\/|$)/i.test(decoded)) {
            return null;
        }

        return decoded;
    } catch {
        return null;
    }
}

function exactHost(request, expectedHost) {
    const hostCount = request.rawHeaders.filter((_, index) => index % 2 === 0)
        .filter((name) => name.toLowerCase() === 'host').length;

    return hostCount === 1 && request.headers.host?.toLowerCase() === expectedHost;
}

function publicHttpRequest(request) {
    if (request.headers['x-forwarded-proto']?.toLowerCase() === 'http') {
        return true;
    }

    try {
        return JSON.parse(request.headers['cf-visitor'] ?? '{}')?.scheme === 'http';
    } catch {
        return false;
    }
}

function upstreamHeaders(request, origin, websocket = false) {
    const connectionHeaders = String(request.headers.connection ?? '').toLowerCase().split(',').map((value) => value.trim());
    const forbidden = new Set([
        'connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization',
        'te', 'trailer', 'transfer-encoding', 'upgrade', 'forwarded',
        ...connectionHeaders,
    ]);
    const headers = {};

    for (const [name, value] of Object.entries(request.headers)) {
        if (!forbidden.has(name) && !name.startsWith('x-forwarded-') && !name.startsWith('x-original-')) {
            headers[name] = value;
        }
    }

    headers.host = origin.hostname;
    headers['x-forwarded-host'] = origin.hostname;
    headers['x-forwarded-proto'] = 'https';
    headers['x-forwarded-port'] = '443';
    const clientIp = request.headers['cf-connecting-ip'];
    headers['x-forwarded-for'] = typeof clientIp === 'string' && net.isIP(clientIp) ? clientIp : '127.0.0.1';

    if (websocket) {
        headers.connection = 'Upgrade';
        headers.upgrade = 'websocket';
    }

    return headers;
}

function proxyRequest(request, response, origin, backendPort) {
    const length = request.headers['content-length'];
    if (length !== undefined && (!/^\d+$/.test(length) || Number(length) > maximumBodyBytes)) {
        request.resume();
        plainResponse(response, 413, 'Request body is too large.', request.method);
        return;
    }

    const upstream = http.request({
        host: '127.0.0.1', port: backendPort, method: request.method,
        path: request.url, headers: upstreamHeaders(request, origin), timeout: 120000,
    }, (upstreamResponse) => {
        if (response.headersSent || response.destroyed) {
            upstreamResponse.destroy();
            return;
        }

        const headers = { ...upstreamResponse.headers };
        for (const name of ['connection', 'keep-alive', 'transfer-encoding', 'upgrade', 'server', 'x-powered-by']) {
            delete headers[name];
        }

        headers['x-content-type-options'] = 'nosniff';
        headers['x-frame-options'] = 'DENY';
        headers['referrer-policy'] = 'strict-origin-when-cross-origin';
        response.writeHead(upstreamResponse.statusCode ?? 502, headers);
        upstreamResponse.pipe(response);
        upstreamResponse.on('error', () => response.destroy());
    });

    let received = 0;
    request.on('data', (chunk) => {
        received += chunk.length;
        if (received > maximumBodyBytes) {
            request.unpipe(upstream);
            plainResponse(response, 413, 'Request body is too large.', request.method);
            upstream.destroy();
            request.resume();
        }
    });
    upstream.on('timeout', () => upstream.destroy());
    upstream.on('error', () => plainResponse(response, 502, 'Backend is unavailable.', request.method));
    request.on('aborted', () => upstream.destroy());
    response.on('close', () => upstream.destroy());
    request.pipe(upstream);
}

async function safeFile(distRoot, pathname) {
    const parts = pathname.split('/').filter(Boolean);
    let candidate = distRoot;
    let file;

    try {
        for (const part of parts) {
            candidate = path.join(candidate, part);
            if (lstatSync(candidate).isSymbolicLink()) {
                return null;
            }
        }

        const canonical = realpathSync(candidate);
        if (canonical !== distRoot && !canonical.startsWith(`${distRoot}${path.sep}`)) {
            return null;
        }

        file = await open(canonical, constants.O_RDONLY | (constants.O_NOFOLLOW ?? 0));
        const information = await file.stat();
        if (!information.isFile()) {
            return null;
        }

        const selected = { file, information };
        file = null;
        return selected;
    } catch {
        return null;
    } finally {
        await file?.close().catch(() => {});
    }
}

async function staticRequest(request, response, pathname, distRoot) {
    if (!['GET', 'HEAD'].includes(request.method)) {
        plainResponse(response, 405, 'Method not allowed.', request.method);
        return;
    }

    let selectedPath = pathname === '/' ? '/index.html' : pathname;
    let selected = await safeFile(distRoot, selectedPath);
    if (!selected && !path.posix.extname(pathname) && request.headers.accept?.includes('text/html')) {
        selectedPath = '/index.html';
        selected = await safeFile(distRoot, selectedPath);
    }

    if (!selected) {
        plainResponse(response, 404, 'Not found.', request.method);
        return;
    }

    try {
        if (response.destroyed || request.aborted) {
            return;
        }

        response.writeHead(200, {
            'Content-Type': contentTypes.get(path.extname(selectedPath).toLowerCase()) ?? 'application/octet-stream',
            'Content-Length': selected.information.size,
            'Cache-Control': selectedPath.startsWith('/assets/') && /-[\w-]{8,}\.[^.]+$/.test(selectedPath)
                ? 'public, max-age=31536000, immutable' : 'no-cache',
            'X-Content-Type-Options': 'nosniff',
            'X-Frame-Options': 'DENY',
            'Referrer-Policy': 'strict-origin-when-cross-origin',
        });

        if (request.method === 'HEAD') {
            response.end();
            return;
        }

        await pipeline(selected.file.createReadStream(), response);
    } catch {
        response.destroy();
    } finally {
        await selected.file.close().catch(() => {});
    }
}

function rejectUpgrade(socket, status, message) {
    if (socket.destroyed || !socket.writable || socket.writableEnded) {
        socket.destroy();
        return;
    }

    socket.end(`HTTP/1.1 ${status} ${message}\r\nConnection: close\r\nContent-Length: 0\r\n\r\n`);
}

function proxyWebSocket(request, socket, head, origin, reverbPort) {
    const upstream = net.connect({ host: '127.0.0.1', port: reverbPort });
    upstream.setTimeout(10000);
    upstream.on('connect', () => {
        upstream.setTimeout(90000);
        const headers = Object.entries(upstreamHeaders(request, origin, true))
            .flatMap(([name, value]) => (Array.isArray(value) ? value : [value]).map((item) => `${name}: ${item}`));
        upstream.write(`${request.method} ${request.url} HTTP/1.1\r\n${headers.join('\r\n')}\r\n\r\n`);
        if (head.length) {
            upstream.write(head);
        }

        socket.pipe(upstream);
        upstream.pipe(socket);
    });
    upstream.on('timeout', () => upstream.destroy());
    upstream.on('error', () => socket.destroy());
    upstream.on('close', () => socket.destroy());
    socket.on('error', () => upstream.destroy());
    socket.on('close', () => upstream.destroy());
}

export async function startGateway(options) {
    if (!options?.dist || !options?.state) {
        throw new Error('Both dist and state paths are required.');
    }

    const distRoot = realpathSync(path.resolve(options.dist));
    if (!statSync(distRoot).isDirectory() || !statSync(path.join(distRoot, 'index.html')).isFile()) {
        throw new Error('The frontend dist must contain index.html.');
    }

    const statePath = path.resolve(options.state);
    if (statePath === distRoot || statePath.startsWith(`${distRoot}${path.sep}`)) {
        throw new Error('The gateway state file must be outside the frontend dist.');
    }

    const port = portNumber(options.port ?? 5003, 'port', true);
    const backendPort = portNumber(options.backendPort ?? 8003, 'backend-port');
    const reverbPort = portNumber(options.reverbPort ?? 8082, 'reverb-port');
    if (port !== 0 && [backendPort, reverbPort].includes(port)) {
        throw new Error('The gateway port must differ from its upstream ports.');
    }

    const sockets = new Set();
    const server = http.createServer({ maxHeaderSize: 16384, headersTimeout: 10000, requestTimeout: 120000 }, (request, response) => {
        const localHosts = ['localhost', '127.0.0.1'].flatMap((host) => [host, `${host}:${server.address().port}`]);
        if (request.url === '/__gateway/health' && localHosts.some((host) => exactHost(request, host))) {
            if (!['GET', 'HEAD'].includes(request.method)) {
                plainResponse(response, 405, 'Method not allowed.', request.method);
                return;
            }

            plainResponse(response, 200, 'Gateway ready.', request.method);
            return;
        }

        const origin = publicOrigin(statePath);
        if (!origin) {
            plainResponse(response, 503, 'Public URL is not configured.', request.method);
            return;
        }

        const insecure = publicHttpRequest(request);
        if (!exactHost(request, origin.hostname)
            || (request.headers.origin !== undefined && request.headers.origin !== origin.origin
                && !(insecure && request.headers.origin === `http://${origin.hostname}`))) {
            plainResponse(response, 403, 'Forbidden.', request.method);
            return;
        }

        const pathname = requestPath(request);
        if (!pathname) {
            plainResponse(response, 404, 'Not found.', request.method);
        } else if (insecure) {
            if (!['GET', 'HEAD'].includes(request.method)) {
                plainResponse(response, 403, 'HTTPS is required.', request.method);
                return;
            }

            response.setHeader('Location', `${origin.origin}${request.url}`);
            plainResponse(response, 307, 'Use HTTPS.', request.method);
        } else if (pathname === '/api' || pathname.startsWith('/api/') || pathname === '/up') {
            proxyRequest(request, response, origin, backendPort);
        } else if (/^\/app(?:\/|$)/.test(pathname)) {
            plainResponse(response, 426, 'WebSocket upgrade required.', request.method);
        } else {
            staticRequest(request, response, pathname, distRoot)
                .catch(() => plainResponse(response, 500, 'Unable to serve frontend.', request.method));
        }
    });

    server.on('connection', (socket) => {
        sockets.add(socket);
        socket.on('close', () => sockets.delete(socket));
    });
    server.on('upgrade', (request, socket, head) => {
        const origin = publicOrigin(statePath);
        if (!origin) {
            rejectUpgrade(socket, 503, 'Unavailable');
        } else if (!exactHost(request, origin.hostname) || request.headers.origin !== origin.origin) {
            rejectUpgrade(socket, 403, 'Forbidden');
        } else if (request.method !== 'GET' || !/^\/app\/[A-Za-z0-9_-]{1,200}$/.test(requestPath(request) ?? '')
            || request.headers.upgrade?.toLowerCase() !== 'websocket'
            || request.headers['sec-websocket-version'] !== '13'
            || !/^[A-Za-z0-9+/]{22}==$/.test(request.headers['sec-websocket-key'] ?? '')) {
            rejectUpgrade(socket, 400, 'Bad Request');
        } else {
            proxyWebSocket(request, socket, head, origin, reverbPort);
        }
    });
    server.on('clientError', (error, socket) => {
        if (error.code === 'ECONNRESET' || error.code === 'ERR_HTTP_REQUEST_TIMEOUT'
            || socket.bytesWritten > 0 || !socket.writable || socket.writableEnded || socket.destroyed) {
            socket.destroy();
            return;
        }

        rejectUpgrade(socket, 400, 'Bad Request');
    });
    server.closeGateway = async () => {
        for (const socket of sockets) {
            socket.destroy();
        }

        await new Promise((resolve, reject) => server.close((error) => error ? reject(error) : resolve()));
    };

    await new Promise((resolve, reject) => {
        server.once('error', reject);
        server.listen(port, '127.0.0.1', resolve);
    });

    return server;
}

export function parseCli(argumentsList) {
    const names = new Map([
        ['--dist', 'dist'], ['--state', 'state'], ['--port', 'port'],
        ['--backend-port', 'backendPort'], ['--reverb-port', 'reverbPort'],
    ]);
    const options = {};
    for (let index = 0; index < argumentsList.length; index += 2) {
        const name = names.get(argumentsList[index]);
        const value = argumentsList[index + 1];
        if (!name || value === undefined || value.startsWith('--') || Object.hasOwn(options, name)) {
            throw new Error('Expected --dist PATH --state PATH and optional numeric ports.');
        }

        options[name] = value;
    }

    for (const name of ['port', 'backendPort', 'reverbPort']) {
        if (options[name] !== undefined) {
            portNumber(options[name], name);
        }
    }

    return options;
}

if (process.argv[1] && import.meta.url === pathToFileURL(path.resolve(process.argv[1])).href) {
    try {
        const server = await startGateway(parseCli(process.argv.slice(2)));
        process.stdout.write(`HRIS public gateway listening on 127.0.0.1:${server.address().port}\n`);
        for (const signal of ['SIGINT', 'SIGTERM']) {
            process.once(signal, () => server.closeGateway().then(() => process.exit(0)));
        }
    } catch {
        process.stderr.write('Unable to start gateway. Check dist, state, and port arguments.\n');
        process.exitCode = 1;
    }
}
