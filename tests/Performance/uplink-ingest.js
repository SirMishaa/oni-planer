// Uplink ingest bench, run by `php artisan uplink:bench --ingest` (k6 v2).
//
// One socket, like the mod: sends zone states at RATE messages per second for DURATION seconds,
// cycling through the zones of PAYLOADS (one slow state every 7 messages of a zone, as the mod's
// 30 s / 5 s cadence). States are JSON text whose game time is the send time in seconds, which
// the server's RecordBenchLatency turns into a latency. Writes {sent} to SUMMARY.
import ws from 'k6/ws';
import { Counter } from 'k6/metrics';

const payloads = JSON.parse(open(__ENV.PAYLOADS));
const rate = parseInt(__ENV.RATE, 10);
const duration = parseInt(__ENV.DURATION, 10);
const sent = new Counter('sent');

export const options = { vus: 1, iterations: 1 };

export default function () {
    ws.connect(__ENV.URL, null, (socket) => {
        const total = rate * duration;
        let index = 0;

        const start = () => {
            const began = Date.now();
            const tick = () => {
                const due = Math.min(total, Math.floor(((Date.now() - began) * rate) / 1000) + 1);
                while (index < due) {
                    const zone = payloads[index % payloads.length];
                    const kind = Math.floor(index / payloads.length) % 7 === 0 ? 'slow' : 'fast';
                    const data = zone[kind].replace('__GAME_SECONDS__', (Date.now() / 1000).toFixed(3));
                    socket.send(JSON.stringify({ event: `client-zone.${kind}`, channel: 'uplink-data', data }));
                    sent.add(1);
                    index++;
                }
                if (index >= total) {
                    socket.setTimeout(() => socket.close(), 500);
                    return;
                }
                socket.setTimeout(tick, 5);
            };
            tick();
        };

        socket.on('message', (raw) => {
            const frame = JSON.parse(raw);
            if (frame.event === 'pusher:connection_established') {
                socket.send(JSON.stringify({ event: 'pusher:subscribe', data: { channel: 'uplink-data' } }));
            } else if (frame.event === 'pusher_internal:subscription_succeeded') {
                start();
            }
        });
    });
}

export function handleSummary(data) {
    return { [__ENV.SUMMARY]: JSON.stringify({ sent: data.metrics.sent ? data.metrics.sent.values.count : 0 }) };
}
