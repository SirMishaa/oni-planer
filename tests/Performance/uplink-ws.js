// Uplink WebSocket benchmark, run by `php artisan uplink:bench` (k6 v2).
//
// For each payload size, sends ITERATIONS `client-bench.ping` client events shaped like a zone
// layout (one object per cell) and waits for the `bench.ack` the server listener answers with,
// matched by correlation id. Env: URL (Reverb ws URL), CHANNEL, SIZES_KB (comma list),
// ITERATIONS, TIMEOUT_MS, SUMMARY (path of the JSON summary written at the end), ENCODING (json: data
// sent as JSON text; base64: data sent as {payload: base64} so Reverb keeps the content opaque).
import ws from 'k6/ws';
import encoding from 'k6/encoding';
import { Counter, Trend } from 'k6/metrics';

const sizes = (__ENV.SIZES_KB || '100,1000').split(',').map((size) => parseInt(size, 10));
const iterations = parseInt(__ENV.ITERATIONS || '5', 10);
const timeoutMs = parseInt(__ENV.TIMEOUT_MS || '30000', 10);
const channel = __ENV.CHANNEL || 'uplink-bench';
const base64 = __ENV.ENCODING === 'base64';

const metrics = {};
for (const size of sizes) {
    metrics[size] = {
        roundtrip: new Trend(`roundtrip_${size}kb`, true),
        inbound: new Trend(`inbound_${size}kb`, true),
        decode: new Trend(`decode_${size}kb`, true),
        memory: new Trend(`memory_${size}kb`),
        bytes: new Trend(`bytes_${size}kb`),
        lost: new Counter(`lost_${size}kb`),
    };
}
const closed = new Counter('socket_closed');

export const options = { vus: 1, iterations: 1, summaryTrendStats: ['count', 'min', 'med', 'p(95)', 'max'] };

/** A payload of about `kilobytes` KB: cells like a zone layout. */
function payload(id, kilobytes) {
    const cells = [];
    let length = 0;
    while (length < kilobytes * 1024) {
        const cell = { e: 'Oxygen', m: 1.2345, t: 300.15, b: cells.length % 7 };
        cells.push(cell);
        length += 52;
    }
    return { id, sentAt: Date.now(), cells };
}

export default function () {
    const plan = [];
    for (const size of sizes) {
        for (let i = 0; i < iterations; i++) {
            plan.push(size);
        }
    }

    ws.connect(__ENV.URL, null, (socket) => {
        let step = 0;
        let pending = null;

        const sendNext = () => {
            if (step >= plan.length) {
                socket.close();
                return;
            }
            const size = plan[step];
            const id = `${size}-${step}`;
            const data = payload(id, size);
            pending = { id, size, sentAt: data.sentAt };
            const text = JSON.stringify(data);
            socket.send(JSON.stringify({ event: 'client-bench.ping', channel, data: base64 ? { payload: encoding.b64encode(text) } : text }));
            socket.setTimeout(() => {
                if (pending && pending.id === id) {
                    metrics[size].lost.add(1);
                    pending = null;
                    step++;
                    sendNext();
                }
            }, timeoutMs);
        };

        socket.on('message', (raw) => {
            const frame = JSON.parse(raw);
            if (frame.event === 'pusher:connection_established') {
                socket.send(JSON.stringify({ event: 'pusher:subscribe', data: { channel } }));
            } else if (frame.event === 'pusher_internal:subscription_succeeded') {
                sendNext();
            } else if (frame.event === 'bench.ack') {
                const ack = JSON.parse(frame.data);
                if (!pending || ack.id !== pending.id) {
                    return;
                }
                const now = Date.now();
                const m = metrics[pending.size];
                m.roundtrip.add(now - pending.sentAt);
                m.inbound.add(ack.listenerStartedAt - pending.sentAt);
                m.decode.add(ack.decodeMs);
                m.memory.add(ack.peakMemoryMb);
                m.bytes.add(ack.bytes);
                pending = null;
                step++;
                sendNext();
            }
        });

        // The server closes the socket on an oversized frame (1009): every unanswered step is lost.
        socket.on('close', () => {
            closed.add(1);
            for (let rest = step; rest < plan.length; rest++) {
                metrics[plan[rest]].lost.add(1);
            }
            step = plan.length;
        });
    });
}

export function handleSummary(data) {
    return { [__ENV.SUMMARY || 'bench-summary.json']: JSON.stringify(data.metrics) };
}
