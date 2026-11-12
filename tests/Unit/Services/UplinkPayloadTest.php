<?php

declare(strict_types=1);

use App\Services\Uplink\UplinkPayload;

$object = ['id' => 'abc', 'zones' => [1, 2, 3]];

it('decodes a base64 gzip payload', function () use ($object): void {
    $data = ['payload' => base64_encode((string) gzencode(json_encode($object)))];

    expect(UplinkPayload::decode($data))->toBe($object);
});

it('decodes a plain base64 payload', function () use ($object): void {
    $data = ['payload' => base64_encode(json_encode($object))];

    expect(UplinkPayload::decode($data))->toBe($object);
});

it('decodes a JSON text', function () use ($object): void {
    expect(UplinkPayload::decode(json_encode($object)))->toBe($object);
});

it('returns an array as is', function () use ($object): void {
    expect(UplinkPayload::decode($object))->toBe($object);
});

it('returns null when unreadable', function (mixed $data): void {
    expect(UplinkPayload::decode($data))->toBeNull();
})->with([
    'broken base64' => [['payload' => '%%% not base64 %%%']],
    'base64 of garbage' => [['payload' => base64_encode('not json')]],
    'broken gzip' => [['payload' => base64_encode("\x1f\x8bgarbage")]],
    'json list' => [json_encode([1, 2, 3])],
    'json scalar' => ['"text"'],
    'payload list' => [['payload' => base64_encode(json_encode([1, 2]))]],
    'empty string' => [''],
    'null' => [null],
    'int' => [42],
]);

it('returns null for a gzip bomb over the size limit', function (): void {
    $bomb = (string) gzencode(str_repeat('a', 64 * 1024 * 1024 + 1), 9);

    expect(UplinkPayload::decode(['payload' => base64_encode($bomb)]))->toBeNull();
});
